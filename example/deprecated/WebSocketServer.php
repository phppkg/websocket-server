<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2016/10/10
 * Time: 下午8:29
 */

namespace Inhere\WebSocket\Server\deprecated;

use Inhere\Http\ServerRequest as Request;
use Inhere\Http\Response;

/**
 * Class WebSocketServer
 *
 * ```
 * $ws = new WebSocketServer($host, $port);
 *
 * // bind events
 * $ws->on('open', callback);
 *
 * $ws->start();
 * ```
 */
class WebSocketServer extends BaseWebSocket
{
    /**
     * the master socket
     * @var resource
     */
    private $master;

    /**
     * 连接的客户端列表
     * @var resource[]
     * [
     *  id => socket,
     * ]
     */
    private $sockets = [];

    /**
     * 连接的客户端握手状态列表
     * @var array
     * [
     *  cid => [ ip=> string , port => int, handshake => bool ], // bool: handshake status.
     * ]
     */
    private $clients = [];

    /**
     * options
     * @var array
     */
    protected $options = [
        'debug' => false,

        'open_log' => true,
        'log_file' => '',

        // while 循环时间间隔 毫秒 millisecond. 1s = 1000ms = 1000 000us
        'sleep_ms' => 500,
        // 最大允许连接数量
        'max_connect' => 25,
        // 最大数据接收长度 1024 2048
        'max_data_len' => 2048,
    ];

    /**
     * default client info data
     * @var array
     */
    protected $defaultInfo = [
        'ip' => '',
        'port' => 0,
        'handshake' => false,
        'path' => '/',
    ];

    /**
     * @return array
     */
    public function getSupportedEvents(): array
    {
        return [self::ON_CONNECT, self::ON_HANDSHAKE, self::ON_OPEN, self::ON_MESSAGE, self::ON_CLOSE, self::ON_ERROR];
    }

    /////////////////////////////////////////////////////////////////////////////////////////
    /// start server
    /////////////////////////////////////////////////////////////////////////////////////////

    protected function beforeStart()
    {
        if (!extension_loaded('sockets')) {
            throw new \InvalidArgumentException('the extension [sockets] is required for run the server.');
        }

        if ($this->getEventCount() < 1) {
            $sup = implode(',', $this->getSupportedEvents());
            $this->print('[ERROR] Please register event handle callback before start. supported events: ' . $sup, true, -500);
        }
    }

    /**
     * create and prepare socket resource
     */
    protected function prepareMasterSocket()
    {
        // reset
        socket_clear_error();
        $this->sockets = $this->clients = [];

        // 创建一个 TCP socket
        // AF_INET: IPv4 网络协议。TCP 和 UDP 都可使用此协议。
        // AF_UNIX: 使用 Unix 套接字. 例如 /tmp/my.sock
        // more see http://php.net/manual/en/function.socket-create.php
        $this->master = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        if (!is_resource($this->master)) {
            $this->print('[ERROR] Unable to create socket: ' . $this->getSocketError(), true, socket_last_error());
        }

        // 设置IP和端口重用,在重启服务器后能重新使用此端口;
        socket_set_option($this->master, SOL_SOCKET, SO_REUSEADDR, TRUE);
        // socket_set_option($this->socket,SOL_SOCKET, SO_RCVTIMEO, ['sec' =>0, 'usec' =>100]);

        // 给套接字绑定名字
        socket_bind($this->master, $this->getHost(), $this->getPort());

        $max = $this->getOption('max_connect', 20);

        // 监听套接字上的连接. 最多允许 $max 个连接，超过的客户端连接会返回 WSAECONNREFUSED 错误
        socket_listen($this->master, $max);

        $this->log("Started WebSocket server on {$this->host}:{$this->port} (max allow connection: $max)");
    }

    /**
     * start server
     */
    public function start()
    {
        $this->beforeStart();

        // create and prepare
        $this->prepareMasterSocket();

        $maxLen = (int)$this->getOption('max_data_len', 2048);

        // interval time
        $setTime = (int)$this->getOption('sleep_ms', 800);
        $sleepTime = $setTime > 50 ? $setTime : 800;
        $sleepTime *= 1000; // ms -> us

        while (true) {
            $write = $except = null;
            // copy， 防止 $this->sockets 的变动被 socket_select() 接收到
            $read = $this->sockets;
            $read[] = $this->master;

            // 会监控 $read 中的 socket 是否有变动
            // $tv_sec =0 时此函数立即返回，可以用于轮询机制
            // $tv_sec =null 将会阻塞程序执行，直到有新连接时才会继续向下执行
            if (false === socket_select($read, $write, $except, null)) {
                $this->log('socket_select() failed, reason: ' . $this->getSocketError(), 'error');
                continue;
            }

            // handle ...
            foreach ($read as $sock) {
                $this->handleSocket($sock, $maxLen);
            }

            //sleep(1);
            usleep($sleepTime);
        }
    }

    /**
     * @param resource $sock
     * @param int $len
     * @return bool
     */
    protected function handleSocket($sock, $len)
    {
        // 每次循环检查到 $this->socket 时，都会用 socket_accept() 去检查是否有新的连接进入，有就加入连接列表
        if ($sock === $this->master) {
            // 从已经监控的socket中接受新的客户端请求
            if (false === ($newSock = socket_accept($sock))) {
                $this->error($this->getSocketError());

                return false;
            }

            $this->connect($newSock);
            return true;
        }

        $cid = (int)$sock;

        // 不在已经记录的client列表中
        if (!isset($this->sockets[$cid], $this->clients[$cid])) {
            return $this->close($cid, $sock);
        }

        $data = null;
        // 函数 socket_recv() 从 socket 中接受长度为 len 字节的数据，并保存在 $data 中。
        $bytes = socket_recv($sock, $data, $len, 0);

        // 没有发送数据或者小于7字节
        if (false === $bytes || $bytes < 7 || !$data) {
            $this->log("Failed to receive data or not received data(client close connection) from #$cid client, will close the socket.");
            return $this->close($cid, $sock);
        }

        // 是否已经握手
        if (!$this->clients[$cid]['handshake']) {
            return $this->handshake($sock, $data, $cid);
        }

        $this->message($cid, $data, $bytes, $this->clients[$cid]);

        return true;
    }

    /**
     * 增加一个初次连接的客户端 同时记录到握手列表，标记为未握手
     * @param resource $socket
     */
    protected function connect($socket)
    {
        $cid = (int)$socket;
        socket_getpeername($socket, $ip, $port);

        // 初始化客户端信息
        $this->clients[$cid] = $info = [
            'ip' => $ip,
            'port' => $port,
            'handshake' => false,
            'path' => '/',
        ];
        // 客户端连接单独保存
        $this->sockets[$cid] = $socket;

        $this->log("A new client connected, ID: $cid, From {$info['ip']}:{$info['port']}. Count: " . $this->count());

        // 触发 connect 事件回调
        $this->fire(self::ON_CONNECT, [$this, $cid]);
    }

    /**
     * 响应升级协议(握手)
     * Response to upgrade agreement (handshake)
     * @param resource $socket
     * @param string $data
     * @param int $cid
     * @return bool|mixed
     */
    protected function handshake($socket, string $data, int $cid)
    {
        $this->log("Ready to shake hands with the #$cid client connection. request:\n$data");
        $client = $this->clients[$cid];
        $response = new Response();

        // 解析请求头信息错误
        if (!preg_match("/Sec-WebSocket-Key: (.*)\r\n/i", $data, $match)) {
            $this->log("handle handshake failed! [Sec-WebSocket-Key] not found in header. Data: \n $data", 'error');

            $response
                ->setStatus(404)
                ->setBody('<b>400 Bad Request</b><br>[Sec-WebSocket-Key] not found in header.');

            $this->writeTo($socket, $response->toString());

            return $this->close($cid, $socket, false);
        }

        // 解析请求头信息
        $request = Request::makeByParseRawData($data);

        // 触发 handshake 事件回调，如果返回 false -- 拒绝连接，比如需要认证，限定路由，限定ip，限定domain等
        // 就停止继续处理。并返回信息给客户端
        if (false === $this->fire(self::ON_HANDSHAKE, [$request, $response, $cid])) {
            $this->log("The #$cid client handshake's callback return false, will close the connection", 'notice');
            $this->writeTo($socket, $response->toString());

            return $this->close($cid, $socket, false);
        }

        // general key
        $key = $this->genSign($match[1]);
        $response
            ->setStatus(101)
            ->setHeaders([
                'Upgrade' => 'websocket',
                'Connection' => 'Upgrade',
                'Sec-WebSocket-Accept' => $key,
            ]);

        // 响应握手成功
        $this->writeTo($socket, $response->toString());

        // 标记已经握手 更新路由 path
        $client['handshake'] = true;
        $client['path'] = $path = $request->getPath();
        $this->clients[$cid] = $client;

        $this->log("The #$cid client connection handshake successful! Info:", 'info', $client);

        // 握手成功 触发 open 事件
        return $this->fire(self::ON_OPEN, [$this, $request, $cid]);
    }

    /**
     * handle client message
     * @param int $cid
     * @param string $data
     * @param int $bytes
     * @param array $client The client info [@see $defaultInfo]
     */
    protected function message(int $cid, string $data, int $bytes, array $client)
    {
        $data = $this->decode($data);

        $this->log("Received $bytes bytes message from #$cid, Data: $data");

        // call on message handler
        $this->fire(self::ON_MESSAGE, [$this, $data, $cid, $client]);
    }

    /**
     * alias method of the `close()`
     * @param int $cid
     * @param null|resource $socket
     * @return mixed
     */
    public function disconnect(int $cid, $socket = null)
    {
        return $this->close($cid, $socket);
    }

    /**
     * Closing a connection
     * @param int $cid
     * @param null|resource $socket
     * @param bool $triggerEvent
     * @return bool
     */
    public function close(int $cid, $socket = null, bool $triggerEvent = true)
    {
        if (!is_resource($socket) && !($socket = $this->sockets[$cid] ?? null)) {
            $this->log("Close the client socket connection failed! #$cid client socket not exists", 'error');
        }

        // close socket connection
        if (is_resource($socket)) {
            socket_shutdown($socket, 2);
            socket_close($socket);
        }

        $client = $this->clients[$cid];
        unset($this->sockets[$cid], $this->clients[$cid]);

        // call close handler
        if ($triggerEvent) {
            $this->fire(self::ON_CLOSE, [$this, $cid, $client]);
        }

        $this->log("The #$cid client connection has been closed! Count: " . $this->count());

        return true;
    }

    /**
     * @param $msg
     */
    protected function error($msg)
    {
        $this->log("An error occurred! Error: $msg", 'error');

        $this->fire(self::ON_ERROR, [$msg, $this]);
    }

    /////////////////////////////////////////////////////////////////////////////////////////
    /// send message to client
    /////////////////////////////////////////////////////////////////////////////////////////

    /**
     * send message
     * @param string $data
     * @param int $sender
     * @param int|array|null $receiver
     * @param int[] $expected
     * @return int
     */
    public function send(string $data, int $sender = 0, $receiver = null, array $expected = [])
    {
        // only one receiver
        if ($receiver && (($isInt = is_int($receiver)) || 1 === count($receiver))) {
            $receiver = $isInt ? $receiver : array_shift($receiver);

            return $this->sendTo($receiver, $data, $sender);
        }

        return $this->broadcast($data, (array)$receiver, $expected, $sender);
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
        if (!$data || $receiver < 1) {
            return 0;
        }

        if (!($socket = $this->getSocket($receiver))) {
            $this->log("The target user #$receiver not connected or has been logout!", 'error');

            return 0;
        }

        $res = $this->frame($data);
        $fromUser = $sender < 1 ? 'SYSTEM' : $sender;

        $this->log("(private)The #{$fromUser} send message to the user #{$receiver}. Data: {$data}");

        return $this->writeTo($socket, $res);
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
        if (!$data || !$this->count()) {
            return 0;
        }

        // only one receiver
        if (1 === count($receivers)) {
            return $this->sendTo(array_shift($receivers), $data, $sender);
        }

        $res = $this->frame($data);
        $len = strlen($res);
        $fromUser = $sender < 1 ? 'SYSTEM' : $sender;

        // to all
        if (!$expected && !$receivers) {
            $this->log("(broadcast)The #{$fromUser} send a message to all users. Data: {$data}");

            foreach ($this->sockets as $socket) {
                $this->writeTo($socket, $res, $len);
            }

            // to receivers
        } elseif ($receivers) {
            $this->log("(broadcast)The #{$fromUser} gave some specified user sending a message. Data: {$data}");
            foreach ($receivers as $receiver) {
                if ($socket = $this->getSocket($receiver)) {
                    $this->writeTo($socket, $res, $len);
                }
            }

            // to all
        } else {
            $this->log("(broadcast)The #{$fromUser} send the message to everyone except some people. Data: {$data}");
            foreach ($this->sockets as $cid => $socket) {
                if (isset($expected[$cid])) {
                    continue;
                }

                if ($receivers && !isset($receivers[$cid])) {
                    continue;
                }

                $this->writeTo($socket, $res, $len);
            }
        }

        return socket_last_error();
    }

    /**
     * response data to client by socket connection
     * @param resource $socket
     * @param string $data
     * @param int $length
     * @return int      Return socket last error number code. gt 0 on failure, eq 0 on success
     */
    public function writeTo($socket, string $data, int $length = 0)
    {
        // response data to client
        socket_write($socket, $data, $length > 0 ? $length : strlen($data));

        return socket_last_error();
    }

    /**
     * @param null|resource $socket
     * @return string
     */
    public function getSocketError($socket = null)
    {
        return socket_strerror(socket_last_error($socket));
    }

    /////////////////////////////////////////////////////////////////////////////////////////
    /// helper method
    /////////////////////////////////////////////////////////////////////////////////////////

    /**
     *  check it is a accepted client
     * @notice maybe don't complete handshake
     * @param $cid
     * @return bool
     */
    public function hasClient(int $cid)
    {
        return isset($this->clients[$cid]);
    }

    /**
     * get client info data
     * @param int $cid
     * @return mixed
     */
    public function getClient(int $cid)
    {
        return $this->clients[$cid] ?? $this->defaultInfo;
    }

    /**
     * @return array
     */
    public function getClients(): array
    {
        return $this->clients;
    }

    /**
     * @return int
     */
    public function countClient(): int
    {
        return $this->count();
    }

    public function count(): int
    {
        return count($this->clients);
    }

    /**
     * check it a accepted client and handshake completed  client
     * @param int $cid
     * @return bool
     */
    public function hasHandshake(int $cid): bool
    {
        if ($this->hasClient($cid)) {
            return $this->getClient($cid)['handshake'];
        }

        return false;
    }

    /**
     * count handshake clients
     * @return int
     */
    public function countHandshake(): int
    {
        $count = 0;

        foreach ($this->clients as $info) {
            if ($info['handshake']) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * check it is a accepted client
     * @notice maybe don't complete handshake
     * @param  resource $socket
     * @return bool
     */
    public function isClientSocket($socket)
    {
        return in_array($socket, $this->sockets, true);
    }

    /**
     * get client socket connection by index
     * @param $cid
     * @return resource|false
     */
    public function getSocket($cid)
    {
        if ($this->hasClient($cid)) {
            return $this->sockets[$cid];
        }

        return false;
    }

    /**
     * @return array
     */
    public function getSockets(): array
    {
        return $this->sockets;
    }

    /**
     * @return resource
     */
    public function getMaster(): resource
    {
        return $this->master;
    }
}
