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
        //启动Timer定时器,每1s回调一次
        swoole_timer_tick(1000 * 1, function ($timer_id) use ($serv) {
            $redis = new \Redis();
            $redis->connect('122.226.180.195', 6001);
            $phone_len = $redis->lLen('gtja_phoneList');
            $conn_list = $serv->connection_list(0, 10);
            $conn_count = $conn_list ? count($conn_list) : 0;
            echo date('Y-m-d H:i:s', time()) . " phone_len:$phone_len conn_count:$conn_count" . PHP_EOL;
            if ($phone_len > 0 && $conn_count > 0) {//号码队列里面有号码，并且有连接的客户端
                foreach ($conn_list as $fd) {
                    $is_fd_running = $redis->get($fd);
                    if ($is_fd_running) {
                        continue;//如果还在进行中则寻找下一个fd
                    } else {
                        $mobile_json = $redis->rPop('gtja_phoneList');
                        if ($mobile_json) {
                            $mobile_arr = json_decode($mobile_json, true);
                            $redis->setex('gtja_mobile_' . $mobile_arr['mobile'], 4 * 60, $fd);//确保验证码找到相应的fd,5d分钟过期。
                            $redis->setex($fd, 4 * 60, 1);//设置正在运行，5分钟后过期
                            if ($mobile_json) {
                                echo date('Y-m-d H:i:s', time()) . " $fd send mobile: $mobile_json" . PHP_EOL;
                                $serv->send($fd, $mobile_json);
                            }
                        } else {
                            break;//没有号码就退出循环
                        }
                    }
                }
            }

            $length = $redis->lLen('gtja_codeList');
            for ($i = 0; $i < $length; $i++) {
                $verifyCode_json = $redis->rPop('gtja_codeList');
                $mobile_arr = json_decode($verifyCode_json, true);
                $fd = $redis->get('gtja_mobile_' . $mobile_arr['mobile']);
                if ($fd) {
                    $conn = $serv->connection_info($fd);
                    if ($conn) {
                        echo date('Y-m-d H:i:s', time()) . " $fd send code: $verifyCode_json" . PHP_EOL;
                        $serv->send($fd, $verifyCode_json);
                    } else {
                        echo date('Y-m-d H:i:s', time()) . " $fd is closed" . PHP_EOL;
                    }
                } else {
                    echo date('Y-m-d H:i:s', time()) . ' verifyCode timeout ' . $verifyCode_json . PHP_EOL;
                }
            }
            $redis->close();
        });
    }
});

//监听连接进入事件
$serv->on('connect', function ($serv, $fd) {
    echo date('Y-m-d H:i:s', time()) . " Client: Connect fd:$fd.\n";
});

//监听数据接收事件
$serv->on('receive', function ($serv, $fd, $from_id, $data) {
    $serv->send($fd, "From server fd:$fd,from_id:$from_id,data:$data");
});


//处理异步任务
$serv->on('task', function ($serv, $task_id, $from_id, $data) {
    echo date('Y-m-d H:i:s', time()) . " New AsyncTask[id=$task_id]" . PHP_EOL;
    //返回任务执行的结果
    $serv->finish("$data -> OK");
});

//处理异步任务的结果
$serv->on('finish', function ($serv, $task_id, $data) {
    echo date('Y-m-d H:i:s', time()) . " AsyncTask[$task_id] Finish: $data" . PHP_EOL;
});

//监听连接关闭事件
$serv->on('close', function ($serv, $fd) {
    echo date('Y-m-d H:i:s', time()) . " Client: Close fd:$fd.\n";
});

//启动服务器
$serv->start();

