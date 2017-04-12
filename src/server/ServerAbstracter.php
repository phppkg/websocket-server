<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-03-30
 * Time: 13:01
 */

namespace inhere\webSocket\server;

use inhere\library\helpers\PhpHelper;
use inhere\library\helpers\ProcessHelper;
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
    protected $defaultInfo = [
        'host' => '',
        'port' => 0,
        'handshake' => false,
        'path' => '/',
    ];

    /**
     * @return array
     */
    public function getSupportedEvents(): array
    {
        return [ self::ON_CONNECT, self::ON_HANDSHAKE, self::ON_OPEN, self::ON_MESSAGE, self::ON_CLOSE, self::ON_ERROR];
    }

    public function getDefaultOptions()
    {
        return array_merge(parent::getDefaultOptions(),[
            // enable ssl
            'enable_ssl' => false,

            // while 循环时间间隔 毫秒 millisecond. 1s = 1000ms = 1000 000us
            'sleep_ms' => 500,

            // 最大允许连接数量
            'max_conn' => 25,

            // 最大数据接收长度 1024 2048
            'max_data_len' => 2048,

            // pid file
            'pid_file' => './tmp/ws_server.pid',

            // 日志配置
            'log_service' => [
                'name'         => 'ws_server_log',
                'basePath'     => './tmp/logs/websocket',
                'logConsole'   => false,
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

        $this->log("The webSocket server power by [<info>{$this->name}</info>], driver class: <default>" . static::class . '</default>', 'info');
    }

    /**
     * start server
     */
    public function start()
    {
        // handle input command
        $this->handleCliCommand();

        // create and prepare
        $this->prepareWork();

        $max = $this->getOption('max_conn', 20);
        $this->log("Started WebSocket server on <info>{$this->getHost()}:{$this->getPort()}</info> (max allow connection: $max)", 'info');

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
     */
    abstract protected function prepareWork();

    /**
     * do start server
     */
    abstract protected function doStart();

    /**
     * Handle Command
     * e.g
     *     php bin/test_server.php start -d
     * @return bool
     */
    protected function handleCliCommand()
    {
        $command = $this->cliIn->getCommand(); // e.g 'start'
        $this->checkInputCommand($command);

        $masterPid = 0;
        $masterIsStarted = true;

        if (!PhpHelper::isWin()) {
            $masterPid = ProcessHelper::getPidByPidFile($this->getPidFIle());
            $masterIsStarted = ($masterPid > 0) && @posix_kill($masterPid, 0);
        }

        // start: do Start Server
        if ( $command === 'start' ) {
            // check master process is running
            if ( $masterIsStarted ) {
                $this->cliOut->error("The swoole server({$this->name}) have been started. (PID:{$masterPid})", true);
            }

            // run as daemon
            $asDaemon = (bool)$this->cliIn->getBool('d', $this->isDaemon());
            $this->setOption('as_daemon', $asDaemon);

            return true;
        }

        // check master process
        if ( !$masterIsStarted ) {
            $this->cliOut->error('The websocket server is not running.', true);
        }

        // switch command
        switch ($command) {
            case 'stop':
            case 'restart':
                // stop: stop and exit. restart: stop and start
                //$this->doStopServer($masterPid, $command === 'stop');
                break;
            case 'reload':
                //$this->doReloadWorkers($masterPid, $this->cliIn->getBool('task'));
                break;
            case 'info':
                //$this->showInformation();
                exit(0);
                break;
            case 'status':
                //$this->showRuntimeStatus();
                break;
            default:
                $this->cliOut->error("The command [{$command}] is don't supported!");
                $this->showHelpPanel();
                break;
        }

        return true;
    }

    protected function checkInputCommand($command)
    {
        $supportCommands = ['start', 'reload', 'restart', 'stop', 'info', 'status'];

        // show help info
        if (
            // no input
            !$command ||
            // command equal to 'help'
            $command === 'help' ||
            // is an not supported command
            !in_array($command, $supportCommands, true) ||
            // has option -h|--help
            $this->cliIn->getBool('h', false) ||
            $this->cliIn->getBool('help', false)
        ) {
            $this->showHelpPanel();
        }
    }

    /**
     * Show help
     * @param  boolean $showHelpAfterQuit
     */
    public function showHelpPanel($showHelpAfterQuit = true)
    {
        $scriptName = $this->cliIn->getScriptName(); // 'bin/test_server.php'
        if ( strpos($scriptName, '.') && 'php' === pathinfo($scriptName,PATHINFO_EXTENSION) ) {
            $scriptName = 'php ' . $scriptName;
        }
        $this->cliOut->helpPanel(
        // Usage
            "$scriptName {start|reload|restart|stop|status} [-d]",
            // Commands
            [
                'start'   => 'Start the server',
                'reload'  => 'Reload all workers of the started server',
                'restart' => 'Stop the server, After start the server.',
                'stop'    => 'Stop the server',
                'info'    => 'Show the server information for current project',
                'status'  => 'Show the started server status information',
                'help'    => 'Display this help message',
            ],
            // Options
            [
                '-d'         => 'Run the server on daemonize.(<comment>not supported on windows</comment>)',
                '--task'     => 'Only reload task worker, when reload server',
                '--driver'   => 'You can custom webSocket driver, allow: sockets, swoole, streams',
                '-h, --help' => 'Display this help message',
            ],
            // Examples
            [
                "<info>$scriptName start -d</info> Start server on daemonize mode.",
                "<info>$scriptName start --driver={name}</info> custom webSocket driver, allow: sockets, swoole, streams"
            ],
            // Description
            'webSocket server tool, Version <comment>' . ServerAbstracter::VERSION .
            '</comment>. Update time ' . ServerAbstracter::UPDATE_TIME,
            $showHelpAfterQuit
        );
    }
    /////////////////////////////////////////////////////////////////////////////////////////
    /// event method
    /////////////////////////////////////////////////////////////////////////////////////////

    /**
     * 增加一个初次连接的客户端 同时记录到握手列表，标记为未握手
     * @param resource $socket
     */
    abstract protected function connect($socket);

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
        $meta = $this->metas[$cid];
        $response = new Response();

        // 解析请求头信息错误
        if ( !preg_match("/Sec-WebSocket-Key: (.*)\r\n/i",$data, $match) ) {
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
        if ( false === $this->trigger(self::ON_HANDSHAKE, [$request, $response, $cid]) ) {
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
     * @param array $meta The client info [@see $defaultInfo]
     */
    protected function message(int $cid, string $data, int $bytes, array $meta = [])
    {
        $meta = $meta ?: $this->getMeta($cid);
        $data = $this->decode($data);

        $this->log("Received $bytes bytes message from #$cid, Data: $data");

        // call on message handler
        $this->trigger(self::ON_MESSAGE, [$this, $data, $cid, $meta]);
    }

    /**
     * @param $msg
     */
    protected function error($msg)
    {
        $this->log("An error occurred! Error: $msg", 'error');

        $this->trigger(self::ON_ERROR, [$msg, $this]);
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
        if ( !is_resource($socket) && !($socket = $this->clients[$cid] ?? null) ) {
            $this->log("Close the client socket connection failed! #$cid client socket not exists", 'error');
        }

        // close socket connection
        if ( is_resource($socket)  ) {
            $this->doClose($socket);
        }

        $meta = $this->metas[$cid];
        unset($this->metas[$cid], $this->clients[$cid]);

        // call close handler
        if ( $triggerEvent ) {
            $this->trigger(self::ON_CLOSE, [$this, $cid, $meta]);
        }

        $this->log("The #$cid client connection has been closed! Count: " . $this->count());

        return true;
    }

    abstract protected function doClose($socket);

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
            $receiver = $isInt ? $receiver: array_shift($receiver);

            return $this->sendTo($receiver, $data, $sender);
        }

        return $this->broadcast($data, (array)$receiver,  $expected, $sender);
    }

    /**
     * Send a message to the specified user 发送消息给指定的用户
     * @param int    $receiver 接收者
     * @param string $data
     * @param int    $sender   发送者
     * @return int
     */
    public function sendTo(int $receiver, string $data, int $sender = 0)
    {
        if ( !$data || $receiver < 1 ) {
            return 0;
        }

        if ( !($socket = $this->getClient($receiver)) ) {
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
     * @param string $data      消息数据
     * @param int    $sender    发送者
     * @param int[]  $receivers 指定接收者们
     * @param int[]  $expected  要排除的接收者
     * @return int   Return socket last error number code.  gt 0 on failure, eq 0 on success
     */
    public function broadcast(string $data, array $receivers = [], array $expected = [], int $sender = 0): int
    {
        if ( !$data ) {
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
        if ( !$expected && !$receivers) {
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
                if ( isset($expected[$cid]) ) {
                    continue;
                }

                if ( $receivers && !isset($receivers[$cid]) ) {
                    continue;
                }

                $this->writeTo($socket, $res, $len);
            }
        }

        return $this->getErrorNo();
    }

    /**
     * response data to client by socket connection
     * @param resource  $socket
     * @param string    $data
     * @param int       $length
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

    /////////////////////////////////////////////////////////////////////////////////////////
    /// helper method
    /////////////////////////////////////////////////////////////////////////////////////////

    /**
     * @param $s
     * @return string
     */
    public function frame($s)
    {
        /** @var array $a */
        $a = str_split($s, 125);
        $prefix = self::BINARY_TYPE_BLOB;

        if (count($a) === 1){
            return $prefix . chr(strlen($a[0])) . $a[0];
        }

        $ns = '';

        foreach ($a as $o){
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
        /*$len = $masks = $data =*/ $decoded = '';
        $len = ord($buffer[1]) & 127;

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

    public function log(string $msg, string $type = 'debug', array $data = [])
    {
        // if close debug, don't output debug log.
        if ( $this->isDebug() || $type !== 'debug') {
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
     * @return string
     */
    public function getPidFIle(): string
    {
        return $this->getOption('pid_file', '');
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
     * @return mixed
     */
    public function getMeta(int $cid)
    {
        return $this->metas[$cid] ?? $this->defaultInfo;
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
        return count($this->metas);
    }

    /**
     * check it a accepted client and handshake completed  client
     * @param int $cid
     * @return bool
     */
    public function hasHandshake(int $cid): bool
    {
        if ( $this->hasMeta($cid) ) {
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
        if ( $this->hasMeta($cid) ) {
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
