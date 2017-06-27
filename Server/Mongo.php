<?php
/**
 * Created by chatr_room_test.
 * User: lb
 * Date: 2017-06-22 13:43
 * Time: 13:43
 */

namespace Server\Mongo;

include_once './vendor/autoload.php';
use MongoDb;
use MongoDB\Client;

class Mongo {

    private $client;

    public function __construct() {

        $this->client = new Client();
    }

    /**
     * 用户注册
     * @param array $user 用户信息
     * @return mixed $isSignIn 是否注册成功
     */
    public function SignIn($user) {
        $isExist = $this->client->chat->user->findOne([
            'user_name' => $user['username']
        ]);

        if ($isExist) {
            return false;
        }

        $id = $this->counters('uid');
        $head = '0' . (($id % 2000) + 2000);
        //TODO user设置uid唯一索引
        $isSignIn = $this->client->chat->user->insertOne([
            'uid' => $id,
            'user_name' => $user['username'],
            'email' => $user['email'],
            'nick' => $user['nick'],
            'password' => $user['password'],
            'head' => $head,
            'create_time' => time(),
        ]);

        return $isSignIn;
    }

    /**
     * 用户登陆
     * @param array $user 用户信息
     * @return mixed $isLogin 是否登陆
     */
    public function login($user) {
        $isLogin = $this->client->chat->user->findOne([
            'user_name' => $user['username'],
            'password' => $user['password']
        ]);

        return $isLogin;
    }

    /**
     *  设置上线用户
     * @param array $user 用户信息
     * @return mixed $isInsert 是否插入成功
     */
    public function setLonInUser($user, $fd) {
        //插入上线列表
        $isInsert = $this->client->chat->online->updateOne([
            'user_name' => $user['username'],
        ], [
            '$set' => [
                'fd' => $fd,
                'login_time' => time(),
            ]
        ], [
                'upsert' => true,
            ]
        );
        return $isInsert;
    }

    /**
     * 设置用户信息并返回
     * @param array $user 用户信息
     * @param mixed $fd fd
     * @return mixed $userPrevious 用户信息
     */
    public function setLonInUserAndReturn($user, $fd) {
        //插入上线列表
        $userPrevious = $this->client->chat->online->findOneAndUpdate([
            'user_name' => $user['username'],
        ],
            [
                '$set' => [
                    'fd' => $fd,
                    'login_time' => time(),
                ]
            ]
            , [
                'upsert' => true,
//                'returnDocument' => MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_BEFORE,
            ]
        );
        return $userPrevious;
    }

    /**
     * 设置下线用户并返回用户信息
     * @param int $fd fd
     * @return mixed $user 用户信息
     *
     */
    public function setLogOutUser($fd) {
        $user = $this->client->chat->online->findOneAndDelete([
            'fd' => $fd
        ]);
        return $user;
    }

    /**
     * 获取线上用户
     */
    public function getOnlineList() {
        $list = $this->client->chat->online->find();

        $return = [];
        if ($list) {
            $list = iterator_to_array($list);
            foreach ($list as $value) {
                $usernameArr[] = $value['user_name'];
            }
            $usernameArr = array_values(array_unique($usernameArr));

            $users = $this->client->chat->user->find([
                'user_name' => ['$in' => $usernameArr]
            ]);

            foreach ($users as $user) {
                $return[] = [
                    'username' => $user['user_name'],
                    'head' => $user['head'],
                ];
            }
        }
        return $return;
    }

    /**
     * 根据用户名获取在线用户信息
     * @param string $username 用户名
     * @return array|null|object 用户信息
     */
    public function getOnlineUserByUsername($username) {
        $user = $this->client->chat->online->findOne([
            'user_name' => $username
        ]);

        return $user;
    }

    /**
     * 根据用户名获取信息
     * @param string $username 用户名
     * @return array|null|object   用户信息
     */
    public function getUserByUsername($username) {
        $user = $this->client->chat->user->findOne([
            'user_name' => $username
        ]);

        return $user;
    }

    /**
     * 移除所有在线数据（服务重启/关闭）使用.
     */
    public function removeOnline() {
        $this->client->chat->online->deleteMany([]);
    }

    /**
     * 插入群聊消息
     * @param string $user 用户名
     * @param string $msg 消息
     * @param int $groupId
     * @return \MongoDB\InsertOneResult 是否插入
     */
    public function insertGroupChat($user, $msg, $groupId = 0) {
        $isInsert = $this->client->chat->group_msg->insertOne([
            'user_name' => $user,
            'msg' => $msg,
            'group_id' => $groupId,
            'create_time' => time(),
        ]);

        return $isInsert;
    }

    /**
     * 获取群主历史消息
     * @param int $groupId 群主ID，0为所有人共存的群组
     * @param int $limit 条数
     * @return array        消息列表
     */
    public function getGroupHistoryMsg($groupId = 0, $limit = 20) {
        $list = $this->client->chat->group_msg->find([
            'group_id' => $groupId
        ], [
            '$limit' => $limit,
            '$sort' => ['create_time' => -1],
        ]);
        $return = [];
        if ($list) {
            $list = iterator_to_array($list);
            foreach ($list as $value) {
                $usernameArr[] = $value['user_name'];
            }

            $usernameArr = array_values(array_unique($usernameArr));
            print_r($usernameArr);
            $usersTmp = $this->client->chat->user->find([
                'user_name' => ['$in' => $usernameArr]
            ]);

            $users = [];
            foreach ($usersTmp as $user) {
                $users[$user['user_name']] = [
                    'head' => "images/head/{$user['head']}.jpg",
                ];
            }

            foreach ($list as $msg) {
                $return[] = [
                    'username' => $msg['user_name'],
                    'head' => $users[$msg['user_name']]['head'],
                    'msg' => $msg['msg'],
                    'group_id' => $msg['group_id'],
                    'time' => date('Y-m-d H:i:s', $msg['create_time']),
                ];
            }
        }

        return $return;
    }

    /**
     * 单条消息发送
     * @param string $send 发送者用户名
     * @param string $receive 接收着用户名
     * @param string $msg 消息
     * @return \MongoDB\InsertOneResult|boolean 是否添加
     */
    public function insertMsg($send, $receive, $msg) {
        if (!$send || !$receive || !$msg) {
            return false;
        }
        $isInsert = $this->client->chat->msg->insertOne([
            'send' => $send,
            'receive' => $receive,
            'msg' => $msg,
            'time' => time()
        ]);
        return $isInsert;
    }

//    public function


    /**
     * 计数器.实现自增
     */
    private function counters($key) {

        $result = $this->client->chat->counters->findOneAndUpdate(
            ['key' => $key],
            ['$inc' => ['seq' => 1]]
        );

        return $result->seq;
    }
}
