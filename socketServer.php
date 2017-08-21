<?php
//创建Server对象，监听 127.0.0.1:9501端口
$serv = new swoole_server("0.0.0.0", 6002);
// server 运行前配置
$serv->set([
    'worker_num' => 1,
    'daemonize' => true,
    'task_worker_num' => 1,  # task 进程数
    'log_file' => './socket.log',
]);

$serv->on('WorkerStart', function ($serv, $worker_id) {
    if (0 == $worker_id) {
        // 启动 Timer 定时器,每5s回调一次 asyncWriteDatabase 函数
        swoole_timer_tick(1000 * 2, function ($timer_id) use ($serv) {
            $redis = new \Redis();
            $redis->connect('122.226.180.195', 6001);

            $length = $redis->lLen('gtja_phoneList');
            for ($i = 0; $i < $length; $i++) {
                $mobile_json = $redis->rPop('gtja_phoneList');
                $conn_list = $serv->connection_list(0, 10);
                foreach ($conn_list as $fd) {
                    echo "send mobile:$fd-$mobile_json\n";
                    $serv->send($fd, $mobile_json);
                }
            }

            $length = $redis->lLen('gtja_codeList');
            for ($i = 0; $i < $length; $i++) {
                $verifyCode_json = $redis->rPop('gtja_codeList');
                $conn_list = $serv->connection_list(0, 10);
                foreach ($conn_list as $fd) {
                    echo "send code:$fd-$verifyCode_json\n";
                    $serv->send($fd, $verifyCode_json);
                }
            }
            $redis->close();
        });
    }
});

//监听连接进入事件
$serv->on('connect', function ($serv, $fd) {
    echo "Client: Connect fd:$fd.\n";
});

//监听数据接收事件
$serv->on('receive', function ($serv, $fd, $from_id, $data) {
    echo "receive:$fd";
    $serv->send($fd, "From server fd:$fd,from_id:$from_id,data:$data");
});


//处理异步任务
$serv->on('task', function ($serv, $task_id, $from_id, $data) {
    echo "New AsyncTask[id=$task_id]" . PHP_EOL;
    //返回任务执行的结果
    $serv->finish("$data -> OK");
});

//处理异步任务的结果
$serv->on('finish', function ($serv, $task_id, $data) {
    echo "AsyncTask[$task_id] Finish: $data" . PHP_EOL;
});

//监听连接关闭事件
$serv->on('close', function ($serv, $fd) {
    echo "Client: Close fd:$fd.\n";
});

//启动服务器
$serv->start();

