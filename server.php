#!/usr/bin/php
<?php
require 'func.php';
$config = require 'config.php';
$redis  = new Redis();
$redis->pconnect($config['redis']['host'], $config['redis']['port']);
print "Connection to server sucessfully\n";
print "Server is running: " . $redis->ping()."\n";
if($config['redis']['pass']){
    $redis->auth( $config['redis']['pass'] );
}
var_dump($config['redis']);
//if($config['redis']['db']){
//    $redis->select( $config['redis']['db'] );
//}

$ws = new swoole_websocket_server("0.0.0.0", 8080);

$ws->on('open', function ($ws, $request) {
    global $redis;
    try {
        $req    = $request->server;
        $uri    = explode('/', $req['request_uri']);
        $cb_name    = $uri[1];
        $cb_pass    = $uri[2];
        $hash   = md5($cb_pass. $cb_name);

        //if fd is 1, the server had been restarted
        if($request->fd==1){
            hash_clear($redis);
        }

        var_dump('open: '.$hash.', fd: '.$request->fd);

        //save $fd => $hash

        if($redis->hGet('fd.to.hash', $request->fd)!=$hash){
            // login success
            $redis->hSet('fd.to.hash', $request->fd, $hash);
        }
        //save $hash => $fd
        // this list saves all client ids
        // which have connected current clipboard. 
        $redis->lPush('publish_'.$hash, $request->fd);

        // get messages from clipboard
        $all    = $redis->lRange($hash, 0, -1);
        if (!$all) {
            $all = [];
        } else {
            $all = array_reverse($all);
        }
        $result = array('type'=>'all', 'data'=>$all);
        if (!$ws->isEstablished($request->fd)) {
            var_dump('fd unset');
            return;
        }
        $ws->push($request->fd, json_encode($result));
    } catch(\Exception $e) {
        var_dump('Error:'.$e->getMessage());
    }
    
});

$ws->on('message', function ($ws, $frame) {
    global $redis;
    try {
        $hash       = $redis->hGet('fd.to.hash', $frame->fd);
        if (!$hash) {
            var_dump('cannot find hash by fd');
            return;
        }
        $r          = json_decode($frame->data, true);
        if(empty($r))return;
        $msg        = $r['msg'];
        if($msg==='')return;
        switch ($r['type']) {
            case 'message':
                save_cb($redis, $hash, $msg);
                publish($redis, $hash, $ws, $msg);
                var_dump("receive msg on '{$hash}' with '{$msg}'");
                break;
            case 'ping':
                publish($redis, $hash, $ws, [
                    'type' => 'pong', 'msg' => 'pone',
                ], true);
                break;
            default:
                var_dump('unknown type: '.$frame->data);
                break;
        }
    } catch (\Exception $e) {
        var_dump('Error:'.$e->getMessage());
    }
    
});

$ws->on('close', function ($ws, $fd) {
    global $redis;
    try {
        $hash   = $redis->hGet('fd.to.hash', $fd);
        // remove $hash=>$fd
        // remove the client from the push channel.
        $redis->lRem('publish_'.$hash, $fd, 0);
        // remove $fd=>$hash
        // remove login info
        $redis->hDel('fd.to.hash', $fd);
        echo "client-{$fd} is closed\n";
    } catch (\Exception $e) {
        var_dump('Error:'.$e->getMessage());
    }
});

$ws->on('request', function($request, $response) {
    global $redis, $ws;
    try {
        $server = $request->server;
        $pathInfo = $server['path_info'];
        $path = explode('/', $pathInfo);
        $cb_name = $path[1];
        $cb_pass = $path[2];
        $hash = md5($cb_pass. $cb_name);
        $response->header('Content-Type', 'text/plain; charset=utf-8');

        switch($server['request_method']) {
            case 'POST':
                $postData = $request->post;
                $content = $postData['content'];
                save_cb($redis, $hash, $content);
                publish($redis, $hash, $ws, $content);
                var_dump('content from cli');
                break;
            case 'GET':
                if (count($path) == 3) {
                    $messages = $redis->lRange($hash, 0, 0);
                    $str = '';
                    foreach($messages as $k => $m) {
                        $m = htmlspecialchars_decode($m);
                        $str .= "{$m}\n\n";
                    }
                    $response->end($str);
                } else {
                    $response->end('');
                }
                break;
            default:
        }
    } catch (\Exception $e) {
        var_dump('Error:'.$e->getMessage());
    }
});

$ws->start();
