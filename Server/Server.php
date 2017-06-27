<?php
/**
 * Created by chatr_room_test.
 * User: lb
 * Date: 2017-06-22 10:10
 * Time: 10:10
 */

namespace Server\Server;

include_once 'Mongo.php';
use Server\Mongo\Mongo;
use Swoole;

class Server {

    private $server;
    private $timeTick = 0;
    private $mongo;

    public function __construct() {

        $this->mongo = new Mongo();

        $this->server = new Swoole\WebSocket\Server('0.0.0.0', '1992');
        $this->server->set([
            'daemonize' => 0, //是否开启守护进程
//            'work_mode' => 3,
            'worker_num' => 4,
            'max_request' => 100,
//            'dispatch_mode' => 2,
            'task_worker_num' => 2,
            'open_length_check' => true,
            'package_length_offset' => 0,
            'package_body_offset' => 4,
            'package_length_type' => 'N'
        ]);

        $this->server->on('Start', array($this, 'onStart'));
        $this->server->on('Connect', array($this, 'onConnect'));
        $this->server->on('Message', array($this, 'onReceive'));
        $this->server->on('Close', array($this, 'onClose'));
        $handlerArray = array(
            'onTimer',
            'onWorkerStart',
            'onWorkerStop',
            'onTask',
            'onFinish',
            'onShutdown'
        );
        foreach ($handlerArray as $handler) {
            if (method_exists($this, $handler)) {
                $this->server->on(\str_replace('on', '', $handler), array($this, $handler));
            }
        }

        $this->server->start();

    }

    public function onStart() {
        echo "server is start\n";
        //服务启动初始化online collection
        $this->mongo->removeOnline();
    }

    public function onConnect($server, $fd) {
        echo "{$fd} is connect\n";
    }

    public function onReceive($server, $frame) {
        echo "workid is:" . $server->worker_id . PHP_EOL;
        echo 'receive message' . PHP_EOL;
        print_r($frame);

        $data = json_decode($frame->data, true);

        switch ($data['action']) {
            case 'login':   //登陆
                $isLogin = $this->mongo->login($data);
                $return = [
                    'type' => 'login',
                    'status' => $isLogin ? 'success' : 'fail',
                    'user' => $data['username']
                ];
                $server->push($frame->fd, json_encode($return));
                break;
            case 'register':    //注册
                $isSinIn = $this->mongo->SignIn($data);
                $return = [
                    'type' => 'register',
                    'status' => $isSinIn ? 'success' : 'fail',
                    'user' => $data['username'],
                ];
                $server->push($frame->fd, json_encode($return));
                break;
            case 'online':      //上线
//                $this->mongo->setLonInUser($data, $frame->fd);
                if (empty($data['username'])) {
                    return;
                }

                $userPrevious = $this->mongo->setLonInUserAndReturn($data, $frame->fd);
                if ($userPrevious) {
                    $send = [
                        'type' => 'kickAccount',
                    ];
                    //发送被挤号信息
                    $server->push($userPrevious['fd'], json_encode($send));
                    //关闭连接
                    $server->close($userPrevious['fd']);
                }

                $user = $this->mongo->getUserByUsername($data['username']);

                //广播上线
                $send = [
                    'type' => 'sbOnlineNotification',   //上线通知
                    'user' => [
                        'uid' => $user['uid'],
                        'head' => $user['head'],
                        'username' => $user['user_name']
                    ]
                ];
                $this->onlineBroadCast(json_encode($send), $frame->fd);
                break;
            case 'getOnlineList':   //获取在线列表
                $list = $this->mongo->getOnlineList();

                $return = [
                    'type' => 'getOnlineList',
                    'list' => $list
                ];
                $server->push($frame->fd, json_encode($return));
                break;
            case 'getHistoryMsg':  //登陆时获取历史消息 （20条）
                $list = $this->mongo->getGroupHistoryMsg();

                $return = [
                    'type' => 'getHistoryMsg',
                    'list' => $list
                ];
                $server->push($frame->fd, json_encode($return));

                break;
            case 'groupChat':     //群发消息
                $isInsert = $this->mongo->insertGroupChat($data['username'], $data['msg']);
                if ($isInsert) {
                    $user = $this->mongo->getUserByUsername($data['username']);
                    $send = [
                        'type' => 'receiveGroupMsg',   //收到群发消息通知
                        'data' => [
                            'user' => $data['username'],
                            'head' => "images/head/{$user['head']}.jpg",
                            'msg' => $data['msg'],
                            'time' => date('m-d H:i:s'),
                            'groupId' => 0
                        ]
                    ];
                    $this->onlineBroadCast(json_encode($send), $frame->fd);
                }
                break;
            case 'privateChat':
                $isInsert = $this->mongo->insertMsg($data['send'], $data['receive'], $data['msg']);
                if (!$isInsert) {
                    return;
                }
                //查找接受者是否在线
                $receive = $this->mongo->getOnlineUserByUsername($data['receive']);
                if ($receive) {
                    $send = $this->mongo->getUserByUsername($data['send']);
                    $send = [
                        'type' => 'receiveMsg',   //收到群发消息通知
                        'data' => [
                            'user' => $send['user_name'],
                            'head' => "images/head/{$send['head']}.jpg",
                            'msg' => $data['msg'],
                            'time' => date('H-m-d H:i:s'),
                        ]
                    ];
                    $server->push($receive['fd'], json_encode($send));
                }
                break;
            default:
                break;
        }

//        $server->shutdown();
//        Swoole\Timer::tick('1000', [$this, 'onTick'], $frame);
    }

    public function onClose($server, $fd) {
        echo "{$fd} is Close";

        $user = $this->mongo->setLogOutUser($fd);

        if ($user) {
            //广播下线
            $send = [
                'type' => 'sbOfflineNotification',   //下线通知
                'user' => [
                    'username' => $user['user_name']
                ]
            ];
            $this->offlineBroadCast(json_encode($send), $fd);
        }
    }

    public function onShutdown($server) {
        //清空online表
        $this->mongo->removeOnline();
    }

//    public function onWorkerStart() {
//        echo 'worker Start' . PHP_EOL;
////        $this->redis = DBFactory::getInstance('Chat');
//    }

    public function onWorkerStart($serv, $worker_id) {
//        global $argv;

        //为每个worker实例化一个mongo对象
        $this->mongo = new Mongo();

        if ($worker_id >= $serv->setting['worker_num']) {
            echo "task worker1" . PHP_EOL;
        } else {
            echo "event worker22222" . PHP_EOL;
        }
    }

    public function onWorkerStop() {

    }

    public function onTask() {
        echo 'task Start' . PHP_EOL;
    }

    public function onFinish() {

    }

//    public function onTick($timer_id, $params = null) {
//        if ($this->timeTick == 10) {
//            Swoole\Timer::clear($timer_id);
//        }
//
//        echo "timeTick params is :\n";
//        print_r($params);
//
//        $msg = rand(10000000, 99999999999);
//        $this->server->push($params->fd, 'hahahaha' . $msg);
//    }


    /**
     * 群发消息广播
     */
    private function groupMsgBroad($data, $fd) {
        foreach ($this->server->connections as $connection) {
            if ($connection != $fd) {
                $this->server->push($connection, $data);
            }
        }
    }

    /**
     * 上线广播
     * @param String (JSON) $data 广播消息
     * @param int $fd
     *
     */
    private function onlineBroadCast($data, $fd) {
        foreach ($this->server->connections as $connection) {
            if ($connection != $fd) {
                $this->server->push($connection, $data);
            }
        }
    }

    /**
     * 下线广播
     * @param String (JSON) $data 广播消息
     * @param int $fd
     *
     */
    private function offlineBroadCast($data, $fd) {
        foreach ($this->server->connections as $connection) {
            if ($connection != $fd) {
                $this->server->push($connection, $data);
            }
        }
    }


}

new Server();