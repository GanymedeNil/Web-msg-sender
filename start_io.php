<?php
use Workerman\Worker;
use Workerman\Lib\Timer;
use PHPSocketIO\SocketIO;

include __DIR__ . '/vendor/autoload.php';

$roomCount = array();
// 全局数组保存uid在线数据
$uidConnectionMap = array();
// 记录最后一次广播的在线用户数
$last_online_count = 0;
// 记录最后一次广播的在线页面数
$last_online_page_count = 0;

$rid = 0;
// PHPSocketIO服务
$sender_io = new SocketIO(2120);
// 客户端发起连接事件时，设置连接socket的各种事件回调
$sender_io->on('connection', function ($socket) use ($sender_io) {
    // 当客户端发来登录事件时触发
    $socket->on('login', function ($data) use ($socket, $sender_io) {
        global $rid, $roomCount, $uidConnectionMap, $last_online_page_count;
        // 已经登录过了
        if (isset($socket->uid)) {
            return;
        }

        list($roomid, $uid) = array_values($data);
        // 更新对应uid的在线数据
        $uid = (string)$uid;
        $roomid = (string)$roomid;
        if (!isset($roomCount[$roomid][$uid])) {
            $roomCount[$roomid][$uid] = 0;
        }
        if (!isset($uidConnectionMap[$uid])) {
            $uidConnectionMap[$uid] = 0;
        }

        // 这个uid有++$uidConnectionMap[$uid]个socket连接
        ++$uidConnectionMap[$uid];
        // 将这个连接加入到uid分组，方便针对uid推送数据
        ++$roomCount[$roomid][$uid];
        $socket->join($uid);
        $socket->uid = $uid;
        $socket->join('room'.$roomid);
        $socket->roomid = $roomid;
        $rid = $roomid;
        $count = count($roomCount[$roomid]);
        // 更新这个socket对应页面的在线数据
        $sender_io->to($roomid)->emit('update_online_count', "当前<b>{$count}</b>人在线，共打开<b>{$last_online_page_count}</b>个页面");
    });

    // 当客户端断开连接是触发（一般是关闭网页或者跳转刷新导致）
    $socket->on('disconnect', function () use ($socket) {
        if (!isset($socket->uid)) {
            return;
        }
        global $roomCount, $uidConnectionMap;
        // 将uid的在线socket数减一
        if (--$uidConnectionMap[$socket->uid] <= 0) {
            $socket->leave($socket->uid);
            unset($uidConnectionMap[$socket->uid]);
        }
        if (--$roomCount[$socket->roomid][$socket->uid] <= 0) {
            $socket->leave($socket->roomid);
            unset($roomCount[$socket->roomid][$socket->uid]);
        }
        if (empty($roomCount[$socket->roomid])) {
            unset($roomCount[$socket->roomid]);
        }

    });

});

// 当$sender_io启动后监听一个http端口，通过这个端口可以给任意uid或者所有uid推送数据
$sender_io->on('workerStart', function ($socket) use ($sender_io) {
    // 监听一个http端口
    $inner_http_worker = new Worker('http://0.0.0.0:2121');
    // 当http客户端发来数据时触发
    $inner_http_worker->onMessage = function ($http_connection, $data) {
        global $uidConnectionMap;
        $_POST = $_POST ? $_POST : $_GET;
        // 推送数据的url格式 type=publish&to=uid&content=xxxx
        switch (@$_POST['type']) {
            case 'publish':
                global $sender_io;
                $to = @$_POST['to'];
                $_POST['content'] = htmlspecialchars(@$_POST['content']);
                // 有指定uid则向uid所在socket组发送数据
                if ($to) {
                    $sender_io->to($to)->emit('new_msg', $_POST['content']);
                    // 否则向所有uid推送数据
                } else {
                    $sender_io->emit('new_msg', @$_POST['content']);
                }
                // http接口返回，如果用户离线socket返回fail
                if ($to && !isset($uidConnectionMap[$to])) {
                    return $http_connection->send('offline');
                } else {
                    return $http_connection->send('ok');
                }
        }
        return $http_connection->send('fail');
    };
    // 执行监听
    $inner_http_worker->listen();

    // 一个定时器，定时向所有uid推送当前uid在线数及在线页面数
    Timer::add(1, function () use ($socket, $sender_io) {
        global $rid, $roomCount, $uidConnectionMap, $last_online_count, $last_online_page_count;
        $online_count_now = count($uidConnectionMap);
        $online_page_count_now = array_sum($uidConnectionMap);
        $count = isset($roomCount[$rid]) ? count($roomCount[$rid]) : 0;
        // 只有在客户端在线数变化了才广播，减少不必要的客户端通讯
        if ($last_online_count != $online_count_now || $last_online_page_count != $online_page_count_now) {
            $sender_io->to($rid)->emit('update_online_count', "当前<b>{$count}</b>人在线，共打开<b>{$online_page_count_now}</b>个页面");
            $last_online_count = $online_count_now;
            $last_online_page_count = $online_page_count_now;
        }
    });
});

if (!defined('GLOBAL_START')) {
    Worker::runAll();
}
