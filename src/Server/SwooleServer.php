<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-04-01
 * Time: 12:47
 */

namespace Inhere\WebSocket\Server;

use Inhere\Http\ServerRequest as Request;
use Inhere\Http\Response;
use Inhere\Http\Uri;
use Swoole\Http\Request as SWRequest;
use Swoole\Http\Response as SWResponse;
use Swoole\Websocket\Frame;
use Swoole\Websocket\Server;

/**
 * Class Server
 * power by `swoole` extension
 * @package Inhere\WebSocket\Server
 */
class SwooleServer extends ServerAbstracter
{
    // 运行模式
    // SWOOLE_PROCESS 业务代码在Worker进程中执行
    // SWOOLE_BASE    业务代码在Reactor进程中直接执行
    const MODE_BASE = 'base';
    const MODE_PROCESS = 'process';

    /**
     * @var string
     */
    protected $driver = 'swoole';

    /**
     * @var Server
     */
    private $swoole;

    /**
     * @return bool
     */
    public static function isSupported(): bool
    {
        return \extension_loaded('swoole');
    }

    /**
     * @return array
     */
    public function appendDefaultConfig(): array
    {
        return [
            'mode'   => self::MODE_PROCESS,
            'swoole' => [
                // 'user'    => '',
                'worker_num' => 4,
                'task_worker_num' => 2, // 启用 task worker,必须为Server设置onTask和onFinish回调
                'daemonize' => 0,
                'max_request' => 1000,
                // 在1.7.15以上版本中，当设置dispatch_mode = 1/3时会自动去掉 `onConnect/onClose` 事件回调。
                // see @link https://wiki.swoole.com/wiki/page/49.html
                'dispatch_mode' => 2,
                // 'log_file' , // '/tmp/swoole.log', // 不设置log_file会打印到屏幕

                // 使用SSL必须在编译swoole时加入--enable-openssl选项 并且配置下面两项
                // 'ssl_cert_file' => __DIR__.'/config/ssl.crt',
                // 'ssl_key_file' => __DIR__.'/config/ssl.key',
            ]
        ];
    }

    /**
     * @inheritdoc
     */
    protected function prepare()
    {
        $host = $this->getHost();
        $port = $this->getPort();
        $mode = $this->config['mode'] === self::MODE_BASE ? SWOOLE_BASE : SWOOLE_PROCESS;
        $socketType = SWOOLE_SOCK_TCP;

        if ($this->get('enable_ssl')) {
            $this->checkEnvWhenEnableSSL();
            $socketType |= SWOOLE_SSL;
        }

        $this->swoole = new Server($host, $port, $mode, $socketType);

        // setting swoole config
        // 对于Server的配置即 $server->set() 中传入的参数设置，必须关闭/重启整个Server才可以重新加载
        $this->swoole->set($this->get('swoole', [
            'worker_num' => 2
        ]));
    }

    /**
     * do start server
     */
    protected function doStart()
    {
        // register events
        // \Swoole\Websocket\Server 不会触发 'connect' 事件
        $this->swoole->on(self::ON_CONNECT, [$this, 'onConnect']);

        // onHandshake函数必须返回true表示握手成功，返回其他值表示握手失败
        $this->swoole->on(self::ON_HANDSHAKE, [$this, 'onHandshake']);

        // 设置onHandshake回调函数后不会再触发onOpen事件，需要应用代码自行处理
        $this->swoole->on(self::ON_OPEN, [$this, 'onOpen']);

        $this->swoole->on(self::ON_MESSAGE, [$this, 'onMessage']);
        $this->swoole->on(self::ON_CLOSE, [$this, 'onClose']);

        $this->swoole->on('task', [$this, 'onTask']);
        $this->swoole->on('finish', [$this, 'onFinish']);

        $this->swoole->start();
    }

    /**
     * 使当前worker进程停止运行，并立即触发onWorkerStop回调函数
     * @param int $wid
     */
    public function stopWorker(int $wid)
    {
        $this->swoole->stop($wid);
    }

    /**
     * @param Server $server
     * @param int $fd
     * @param int $fromId
     */
    public function onConnect(Server $server, int $fd, int $fromId)
    {
        $this->debug("onConnect: PID {$server->master_pid}, form reactor ID $fromId, connection ID $fd");

        // trigger connect event.
        $this->connect($fd);
    }

    /**
     * @param SWRequest $swRequest
     * @param SWResponse $swResponse
     * @return bool
     */
    public function onHandshake(SWRequest $swRequest, SWResponse $swResponse): bool
    {
        $cid = $swRequest->fd;

        // begin start handshake
        $method = $swRequest->server['request_method'];
        $uriStr = $swRequest->server['request_uri'];

        $request = new Request($method, Uri::createFromString($uriStr));
        $request->setHeaders($swRequest->header ?: []);

        if ($cookies = $swRequest->cookie ?? null) {
            $request->setCookies($cookies);
        }

        $this->log("Handshake: Ready to shake hands with the #$cid client connection. request:\n" . $request->toString());

        $response = new Response();
        $secKey = $swRequest->header['sec-websocket-key'];

        // sec-websocket-key 错误
        if ($this->isInvalidSecWSKey($secKey)) {
            $this->log("handshake failed with client #{$cid}! [Sec-WebSocket-Key] not found OR is error in header. request: \n" . $request->toString(), 'error');

            $swResponse->status(404);
            $swResponse->write('<b>400 Bad Request</b><br>[Sec-WebSocket-Key] not found in request header.');
            $swResponse->end();

            // $this->close($cid, $socket, false);
            return false;
        }

        // 触发 handshake 事件回调，如果返回 false -- 拒绝连接，比如需要认证，限定路由，限定ip，限定domain等
        // 就停止继续处理。并返回信息给客户端
        if (false === $this->fire(self::ON_HANDSHAKE, [$request, $response, $cid])) {
            $this->log("The #$cid client handshake's callback return false, will close the connection", 'notice');

            $swResponse->status($response->getStatusCode());

            foreach ($response->getHeaders() as $name => $value) {
                $swResponse->header($name, $value);
            }

            $swResponse->write($response->getBody());
            // $swResponse->end();

            // $this->close($cid, $socket, false);
            return false;
        }

        // setting response
        $response
            ->setStatus(101)
            ->setHeaders([
                'Upgrade' => 'websocket',
                'Connection' => 'Upgrade',
                'Sec-WebSocket-Accept' => $this->genSign($secKey),
                'Sec-WebSocket-Version' => self::WS_VERSION,
            ]);
        $this->debug("Handshake: response info:\n" . $response->toString());

        // 响应握手成功
        $swResponse->status($response->getStatusCode());
        foreach ($response->getHeaders() as $name => $value) {
            $swResponse->header($name, $value);
        }

//        $respData = $response->toString();
//        $this->debug("Handshake: response info:\n" . $respData);
//        $r = $this->server->send($cid, $respData);

        // 标记已经握手 更新路由 path
        $meta = $this->metas[$cid];
        $meta['handshake'] = true;
        $meta['path'] = $request->getPath();
        $this->metas[$cid] = $meta;

        $this->log("Handshake: The #$cid client connection handshake successful! Meta:", 'info', $meta);

        // 握手成功 触发 open 事件
        $this->swoole->defer(function () use ($request, $cid) {
            $this->fire(self::ON_OPEN, [$this, $request, $cid]);
        });

        //var_dump($this);
        return true;
    }

    /**
     * @notice the method don't use
     * @param Server $server
     * @param SWRequest $swRequest
     */
    public function onOpen(Server $server, SWRequest $swRequest)
    {
        $cid = $swRequest->fd;

        $this->debug("onConnect: PID {$server->master_pid}, connection ID $cid");

        $method = $swRequest->server['request_method'];
        $uriStr = $swRequest->server['request_uri'];

        $request = new Request($method, Uri::createFromString($uriStr));
        $request->setHeaders($swRequest->header ?: []);

        if ($cookies = $swRequest->cookie ?? null) {
            $request->setCookies($cookies);
        }

        // 标记已经握手 更新路由 path
        $meta = $this->metas[$cid];
        $meta['handshake'] = true;
        $meta['path'] = $request->getPath();
        $this->metas[$cid] = $meta;

        $this->log("Open: The #$cid client connection open successful! Meta:", 'info', $meta);

        $this->fire(self::ON_OPEN, [$this, $request, $cid]);
    }

    /**
     * @param Server $server
     * @param Frame $frame
     */
    public function onMessage(Server $server, Frame $frame)
    {
        //var_dump($this);
        $this->debug("Swoole: PID $server->master_pid FD $frame->fd, OpCode: $frame->opcode, Data: $frame->data", $this->metas);
        $this->message($frame->fd, $frame->data, \strlen($frame->data));
    }

    /**
     * @param Server $server
     * @param int $cid
     */
    public function onClose(Server $server, $cid)
    {
        $this->debug("onClose: A client connection #$cid has been lost[worker:{$server->worker_id}]");

        $this->afterClose($cid);
    }

    ////////////////////// Task Event //////////////////////

    /**
     * 处理异步任务( onTask )
     * @param  Server $server
     * @param  int $taskId
     * @param  int $fromId
     * @param  mixed $data
     */
    public function onTask(Server $server, $taskId, $fromId, $data)
    {
        // $this->addLog("Handle New AsyncTask[id:$taskId]");
        // 返回任务执行的结果(finish操作是可选的，也可以不返回任何结果)
        // $server->finish("$data -> OK");
    }

    /**
     * 处理异步任务的结果
     * @param  Server $server
     * @param  int $taskId
     * @param  mixed $data
     */
    public function onFinish(Server $server, $taskId, $data)
    {
        //$this->addLog("AsyncTask[$taskId] Finish. Data: $data");
    }

    /**
     * {@inheritdoc}
     */
    public function decode($buffer)
    {
        return $buffer;
    }

    /**
     * Closing a connection
     * @param int $cid
     * @param null|resource $socket
     * @return bool
     */
    protected function doClose(int $cid, $socket = null)
    {
        return $this->swoole->close($cid);
    }

    /**
     * Send a message to the specified user 发送消息给指定的用户
     * @param int $receiver 接收者
     * @param string $data
     * @param int $sender 发送者
     * @return int
     */
    public function sendTo(int $receiver, string $data, int $sender = 0)
    {
        $finish = true;
        $opcode = 1;

        $fromUser = $sender < 1 ? 'SYSTEM' : $sender;

        $this->log("(private)The #{$fromUser} send message to the user #{$receiver}. Data: {$data}");

        return $this->swoole->push($receiver, $data, $opcode, $finish) ? 0 : -500;
    }

    /**
     * broadcast message 广播消息
     * @param string $data 消息数据
     * @param int $sender 发送者
     * @param int[] $receivers 指定接收者们
     * @param int[] $expected 要排除的接收者
     * @return int   Return socket last error number code.  gt 0 on failure, eq 0 on success
     */
    public function broadcast(string $data, array $receivers = [], array $expected = [], int $sender = 0): int
    {
        if (!$data) {
            return 0;
        }

        // only one receiver
        if (1 === \count($receivers)) {
            return $this->sendTo(array_shift($receivers), $data, $sender);
        }

        // to all
        if (!$expected && !$receivers) {
            $this->sendToAll($data, $sender);

            // to some
        } else {
            $this->sendToSome($data, $receivers, $expected, $sender);
        }

        return $this->getErrorNo();
    }

    /**
     * @param string $data
     * @param int $sender
     * @return int
     */
    public function sendToAll(string $data, int $sender = 0): int
    {
        $startFd = 0;
        $count = 0;
        $fromUser = $sender < 1 ? 'SYSTEM' : $sender;

        $this->log("(broadcast)The #{$fromUser} send a message to all users. Data: {$data}");

        while (true) {
            $connList = $this->swoole->connection_list($startFd, 50);

            if ($connList === false || ($num = \count($connList)) === 0) {
                break;
            }

            $count += $num;
            $startFd = end($connList);

            /** @var $connList array */
            foreach ($connList as $fd) {
                $this->swoole->push($fd, $data);
            }
        }

        return $count;
    }

    /**
     * @param string $data
     * @param array $receivers
     * @param array $expected
     * @param int $sender
     * @return int
     */
    public function sendToSome(string $data, array $receivers = [], array $expected = [], int $sender = 0): int
    {
        $count = 0;
        $res = $data;
        $len = \strlen($res);
        $fromUser = $sender < 1 ? 'SYSTEM' : $sender;

        // to receivers
        if ($receivers) {
            $this->log("(broadcast)The #{$fromUser} gave some specified user sending a message. Data: {$data}");

            foreach ($receivers as $receiver) {
                if ($this->hasClient($receiver)) {
                    $count++;
                    $this->swoole->push($receiver, $res, $len);
                }
            }

            return $count;
        }

        // to special users
        $startFd = 0;
        $this->log("(broadcast)The #{$fromUser} send the message to everyone except some people. Data: {$data}");

        while (true) {
            $connList = $this->swoole->connection_list($startFd, 50);

            if ($connList === false || ($num = \count($connList)) === 0) {
                break;
            }

            $count += $num;
            $startFd = end($connList);

            /** @var $connList array */
            foreach ($connList as $fd) {
                if (isset($expected[$fd])) {
                    continue;
                }

                if ($receivers && !isset($receivers[$fd])) {
                    continue;
                }

                $this->swoole->push($fd, $data);
            }
        }

        return $count;
    }

    /**
     * response data to client by socket connection
     * @param int $fd
     * @param string $data
     * @param int $length
     * @return int   Return error number code. gt 0 on failure, eq 0 on success
     */
    public function writeTo($fd, string $data, int $length = 0)
    {
//        $finish = true;
//        $opcode = 1;

        // return $this->server->push($fd, $data, $opcode, $finish) ? 0 : 1;
        return $this->swoole->send($fd, $data) ? 0 : 1;
    }

    /**
     * @param int $cid
     * @return bool
     */
    public function exist(int $cid): bool
    {
        return $this->swoole->exist($cid);
    }

    /**
     */
    protected function checkEnvWhenEnableSSL()
    {
        if (!\defined('SWOOLE_SSL')) {
            $this->cliOut->error('If you want use SSL(https), must add option --enable-openssl on the compile swoole.', -500);
        }

        // check ssl config
        if (!$this->get('ssl_cert_file') || !$this->get('ssl_key_file')) {
            $this->cliOut->error("If you want use SSL(https), must config the 'swoole.ssl_cert_file' and 'swoole.ssl_key_file'", -500);
        }
    }

    /**
     * 获取对端socket的IP地址和端口
     * @param int $cid
     * @return array
     */
    public function getPeerName($cid)
    {
        $data = $this->getClientInfo($cid);

        return [
            'ip' => $data['remote_ip'] ?? '',
            'port' => $data['remote_port'] ?? 0,
        ];
    }

    /**
     * @param int $cid
     * @return array
     * [
     *  from_id => int
     *  server_fd => int
     *  server_port => int
     *  remote_port => int
     *  remote_ip => string
     *  connect_time => int
     *  last_time => int
     * ]
     */
    public function getClientInfo(int $cid)
    {
        // @link https://wiki.swoole.com/wiki/page/p-connection_info.html
        return $this->swoole->getClientInfo($cid);
    }

    /**
     * @param null|resource $socket
     * @return int
     */
    public function getErrorNo($socket = null)
    {
        return $this->swoole->getLastError();
    }

    /**
     * @param null|resource $socket
     * @return string
     */
    public function getErrorMsg($socket = null)
    {
        $err = error_get_last();

        return $err['message'] ?? '';
    }

    /**
     * @return resource
     */
    public function getSocket(): resource
    {
        return $this->swoole->getSocket();
    }
}
