<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-03-30
 * Time: 13:01
 */

namespace inhere\webSocket\server;

use inhere\webSocket\WSAbstracter;
use inhere\webSocket\http\Request;
use inhere\webSocket\http\Response;

/**
 * Class AServerDriver
 * @package inhere\webSocket\server
 */
abstract class ServerAbstracter extends WSAbstracter implements ServerInterface
{
    /**
     * the callback on the before start server
     * @var \Closure
     */
    private $beforeStartCb;

    /**
     * the master socket
     * @var resource
     */
    protected $socket;

    /**
     * client total number
     * @var int
     */
    private $clientNumber = 0;

    /**
     * 连接的客户端列表
     * @var resource[]
     * [
     *  id => socket,
     * ]
     */
    protected $clients = [];

    /**
     * 连接的客户端信息列表
     * @var array
     * [
     *  cid => [ ip=> string , port => int, handshake => bool ], // bool: handshake status.
     * ]
     */
    protected $metas = [];

    /**
     * default client meta info
     * @var array
     */
    protected $defaultMeta = [
        'host' => '',
        'port' => 0,
        'handshake' => false,
        'path' => '/',
        'connect_time' => 0,
    ];

    /**
     * @return array
     */
    public function getSupportedEvents(): array
    {
        return [self::ON_CONNECT, self::ON_HANDSHAKE, self::ON_OPEN, self::ON_MESSAGE, self::ON_CLOSE, self::ON_ERROR];
    }

    /**
     * @return array
     */
    public function getDefaultOptions()
    {
        return array_merge(parent::getDefaultOptions(), [

            // while 循环时间间隔 毫秒 millisecond. 1s = 1000ms = 1000 000us
            'sleep_ms' => 500,

            // 最大允许连接数量
            'max_connect' => 100,

            // 最大数据接收长度 1024 2048
            'max_data_len' => 2048,

            'buffer_size' => 8192, // 8kb

            // 日志配置
            'log_service' => [
                'name' => 'ws_server_log',
                'basePath' => './tmp/logs/websocket',
                'logConsole' => false,
                'logThreshold' => 0,
            ]
        ]);
    }

    /**
     * WebSocket constructor.
     * @param string $host
     * @param int $port
     * @param array $options
     */
    public function __construct(string $host = '0.0.0.0', int $port = 8080, array $options = [])
    {
        parent::__construct($options);

        if (!static::isSupported()) {
            // throw new \InvalidArgumentException("The extension [$this->name] is required for run the server.");
            $this->cliOut->error("Your system is not supported the driver: {$this->name}, by " . static::class, -200);
        }

        $this->host = $host;
        $this->port = $port;

        $this->cliOut->write("The webSocket server power by [<info>{$this->name}</info>], driver class: <default>" . static::class . '</default>', 'info');
    }

    /**
     * start server
     */
    public function start()
    {
        $max = (int)$this->getOption('max_connect', self::MAX_CONNECT);
        $this->log("Started WebSocket server on <info>{$this->getHost()}:{$this->getPort()}</info> (max allow connection: $max)", 'info');

        // create and prepare
        $this->prepareWork($max);

        // if `$this->beforeStartCb` exists.
        if ($cb = $this->beforeStartCb) {
            $cb($this);
        }

        $this->doStart();
    }

    /**
     * @param \Closure $closure
     */
    public function beforeStart(\Closure $closure = null)
    {
        $this->beforeStartCb = $closure;
    }

    /**
     * create and prepare socket resource
     * @param int $maxConnect
     */
    abstract protected function prepareWork(int $maxConnect);

    /**
     * do start server
     */
    abstract protected function doStart();

    /////////////////////////////////////////////////////////////////////////////////////////
    /// event method
    /////////////////////////////////////////////////////////////////////////////////////////

    /**
     * 增加一个初次连接的客户端 同时记录到握手列表，标记为未握手
     * @param resource|int $socket
     */
    protected function connect($socket)
    {
        $cid = (int)$socket;
        $data = $this->getPeerName($socket);

        // 初始化客户端信息
        $this->metas[$cid] = $meta = [
            'host' => $data['host'],
            'port' => $data['port'],
            'handshake' => false,
            'path' => '/',
            'connect_time' => time(),
        ];
        // 客户端连接单独保存
        $this->clients[$cid] = $socket;
        $this->clientNumber++;

        $this->log("Connect: A new client connected, ID: $cid, From {$meta['host']}:{$meta['port']}. Count: {$this->clientNumber}");

        // 触发 connect 事件回调
        $this->trigger(self::ON_CONNECT, [$this, $cid]);
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
        $this->log("Handshake: Ready to shake hands with the #$cid client connection. request:\n$data");
        $meta = $this->metas[$cid];
        $response = new Response();

        // 解析请求头信息错误
        if (!preg_match("/Sec-WebSocket-Key: (.*)\r\n/i", $data, $match)) {
            $this->log("handle handshake failed! [Sec-WebSocket-Key] not found in header. Data: \n $data", 'error');

            $response
                ->setStatus(404)
                ->setBody('<b>400 Bad Request</b><br>[Sec-WebSocket-Key] not found in request header.');

            $this->writeTo($socket, $response->toString());

            return $this->close($cid, $socket, false);
        }

        // 解析请求头信息
        $request = Request::makeByParseRawData($data);

        // 触发 handshake 事件回调，如果返回 false -- 拒绝连接，比如需要认证，限定路由，限定ip，限定domain等
        // 就停止继续处理。并返回信息给客户端
        if (false === $this->trigger(self::ON_HANDSHAKE, [$request, $response, $cid])) {
            $this->log("The #$cid client handshake's callback return false, will close the connection", 'notice');
            $this->writeTo($socket, $response->toString());

            return $this->close($cid, $socket, false);
        }

        /**
         * @TODO
         *   ? Origin;
         *   ? Sec-WebSocket-Protocol;
         *   ? Sec-WebSocket-Extensions.
         */

        // setting response
        $response
            ->setStatus(101)
            ->setHeaders([
                'Upgrade' => 'websocket',
                'Connection' => 'Upgrade',
                'Sec-WebSocket-Accept' => $this->genSign($match[1]),
                'Sec-WebSocket-Version' => self::WS_VERSION,
            ]);

        // 响应握手成功
        $respData = $response->toString();
        $this->debug("Handshake: response info:\n" . $respData);
        $this->writeTo($socket, $respData);

        // 标记已经握手 更新路由 path
        $meta['handshake'] = true;
        $meta['path'] = $request->getPath();
        $this->metas[$cid] = $meta;

        $this->log("The #$cid client connection handshake successful! Info:", 'info', $meta);

        // 握手成功 触发 open 事件
        return $this->trigger(self::ON_OPEN, [$this, $request, $cid]);
    }

    /**
     * handle client message
     * @param int $cid
     * @param string $data
     * @param int $bytes
     * @param array $meta The client info [@see $defaultMeta]
     */
    protected function message(int $cid, string $data, int $bytes, array $meta = [])
    {
        $meta = $meta ?: $this->getMeta($cid);
        $data = $this->decode($data);

        $this->log("Message: Received $bytes bytes message from #$cid, Data: $data");

        // call on message handler
        $this->trigger(self::ON_MESSAGE, [$this, $data, $cid, $meta]);
    }

    /**
     * Closing a connection
     * `disconnect()` is alias method of the `close()`
     * @param int $cid
     * @param null|resource $socket
     * @param bool $triggerEvent
     * @return bool
     */
    public function disconnect(int $cid, $socket = null, bool $triggerEvent = true)
    {
        return $this->close($cid, $socket, $triggerEvent);
    }

    public function close(int $cid, $socket = null, bool $triggerEvent = true)
    {
        $this->log("Close: Will close the #$cid client connection");

        $ret = $this->doClose($cid, $socket);

        $this->afterClose($cid, $triggerEvent);

        return $ret;
    }

    /**
     * Closing a connection
     * @param int $cid
     * @param resource|null $socket
     * @return bool
     */
    abstract protected function doClose(int $cid, $socket = null);

    /**
     * @param int $cid
     * @param bool $triggerEvent
     */
    protected function afterClose(int $cid, bool $triggerEvent = true)
    {
        $meta = $this->metas[$cid];
        $this->clientNumber--;

        unset($this->metas[$cid], $this->clients[$cid]);

        // call on close callback
        if ($triggerEvent) {
            $this->trigger(self::ON_CLOSE, [$this, $cid, $meta]);
        }

        $this->log("Close: The #$cid client connection has been closed! From {$meta['host']}:{$meta['port']}. Count: {$this->clientNumber}");
    }

    /**
     * @param $msg
     */
    protected function error($msg)
    {
        $this->log("An error occurred! Error: $msg", 'error');

        $this->trigger(self::ON_ERROR, [$msg, $this]);
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

        if (!($socket = $this->getClient($receiver))) {
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
        if (!$data) {
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

            foreach ($this->clients as $socket) {
                $this->writeTo($socket, $res, $len);
            }

            // to receivers
        } elseif ($receivers) {
            $this->log("(broadcast)The #{$fromUser} gave some specified user sending a message. Data: {$data}");
            foreach ($receivers as $receiver) {
                if ($socket = $this->getClient($receiver)) {
                    $this->writeTo($socket, $res, $len);
                }
            }

            // to special users
        } else {
            $this->log("(broadcast)The #{$fromUser} send the message to everyone except some people. Data: {$data}");
            foreach ($this->clients as $cid => $socket) {
                if (isset($expected[$cid])) {
                    continue;
                }

                if ($receivers && !isset($receivers[$cid])) {
                    continue;
                }

                $this->writeTo($socket, $res, $len);
            }
        }

        return $this->getErrorNo();
    }

    /**
     * response data to client by socket connection
     * @param resource $socket
     * @param string $data
     * @param int $length
     * @return int      Return socket last error number code. gt 0 on failure, eq 0 on success
     */
    abstract public function writeTo($socket, string $data, int $length = 0);

    /**
     * @param null|resource $socket
     * @return int
     */
    abstract public function getErrorNo($socket = null);

    /**
     * @param null|resource $socket
     * @return string
     */
    abstract public function getErrorMsg($socket = null);

    /**
     * 获取对端socket的IP地址和端口
     * @param resource|int $socket Driver is sockets or streams, type is resource. Driver is swoole type is int.
     * @return array
     */
    abstract public function getPeerName($socket);

    /////////////////////////////////////////////////////////////////////////////////////////
    /// helper method
    /////////////////////////////////////////////////////////////////////////////////////////

    /**
     * packData encode
     * @param string $s
     * @return string
     */
    public function frame(string $s)
    {
        /** @var array $a */
        $a = str_split($s, 125);
        $prefix = self::BINARY_TYPE_BLOB;

        // <= 125
        if (count($a) === 1) {
            return $prefix . chr(strlen($a[0])) . $a[0];
        }

        $ns = '';

        foreach ($a as $o) {
            $ns .= $prefix . chr(strlen($o)) . $o;
        }

        return $ns;
    }

    /**
     * @param $buffer
     * @return string
     */
    public function decode($buffer)
    {
        /*$len = $masks = $data =*/
        $decoded = '';

        $fin = (ord($buffer{0}) & 0x80) === 0x80; // 1bit，1表示最后一帧
        if (!$fin) {
            return '';// 超过一帧暂不处理
        }

        $maskFlag = (ord($buffer{1}) & 0x80) === 0x80; // 是否包含掩码 0x80 -> 128
        if (!$maskFlag) {
            return '';// 不包含掩码的暂不处理
        }

        // $len = ord($buffer[1]) & 0x7F; // 数据长度
        $len = ord($buffer[1]) & 127; // 数据长度 0x7F -> 127

        if ($len === 126) {
            $masks = substr($buffer, 4, 4);
            $data = substr($buffer, 8);
        } else if ($len === 127) {
            $masks = substr($buffer, 10, 4);
            $data = substr($buffer, 14);
        } else {
            $masks = substr($buffer, 2, 4);
            $data = substr($buffer, 6);
        }

        $dataLen = strlen($data);
        for ($index = 0; $index < $dataLen; $index++) {
            $decoded .= $data[$index] ^ $masks[$index % 4];
        }

        return $decoded;
    }

    /////////////////////////////////////////////////////////////////////////////////////////
    /// helper method
    /////////////////////////////////////////////////////////////////////////////////////////

    /**
     * {@inheritDoc}
     */
    public function log(string $msg, string $type = 'debug', array $data = [])
    {
        // if close debug, don't output debug log.
        if ($this->isDebug() || $type !== 'debug') {
            if (!$this->isDaemon()) {
                [$time, $micro] = explode('.', microtime(1));
                $time = date('Y-m-d H:i:s', $time);
                $json = $data ? json_encode($data) : '';
                $type = strtoupper($type);

                $this->cliOut->write("[{$time}.{$micro}] [$type] $msg {$json}");
            } else if ($logger = $this->getLogger()) {
                $logger->$type(strip_tags($msg), $data);
            }
        }
    }

    /**
     * @return bool
     */
    public function isDaemon(): bool
    {
        return (bool)$this->getOption('as_daemon', false);
    }

    /**
     *  check it is a accepted client
     * @notice maybe don't complete handshake
     * @param $cid
     * @return bool
     */
    public function hasMeta(int $cid)
    {
        return isset($this->metas[$cid]);
    }

    /**
     * get client info data
     * @param int $cid
     * @return array
     */
    public function getMeta(int $cid)
    {
        return $this->metas[$cid] ?? [];
    }

    /**
     * get client info data
     * @param int $cid
     * @return array
     */
    public function getClientInfo(int $cid)
    {
        return $this->getMeta($cid);
    }

    /**
     * @return array
     */
    public function getMetas(): array
    {
        return $this->metas;
    }

    /**
     * @return int
     */
    public function count(): int
    {
        return $this->clientNumber;
    }

    /**
     * check it a accepted client and handshake completed  client
     * @param int $cid
     * @return bool
     */
    public function isHandshake(int $cid): bool
    {
        if ($this->hasMeta($cid)) {
            return $this->getMeta($cid)['handshake'];
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

        foreach ($this->metas as $info) {
            if ($info['handshake']) {
                $count++;
            }
        }

        return $count;
    }

    /**
     *  check it is a exists client
     * @notice maybe don't complete handshake
     * @param $cid
     * @return bool
     */
    public function hasClient(int $cid)
    {
        return isset($this->clients[$cid]);
    }

    /**
     * check it is a accepted client socket
     * @notice maybe don't complete handshake
     * @param  resource $socket
     * @return bool
     */
    public function isClient($socket)
    {
        return in_array($socket, $this->clients, true);
    }

    /**
     * get client socket connection by index
     * @param $cid
     * @return resource|false
     */
    public function getClient($cid)
    {
        if ($this->hasMeta($cid)) {
            return $this->clients[$cid];
        }

        return false;
    }

    /**
     * @return array
     */
    public function getClients(): array
    {
        return $this->clients;
    }

    /**
     * @return resource
     */
    public function getSocket(): resource
    {
        return $this->socket;
    }

}
