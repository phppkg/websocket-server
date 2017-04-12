<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-04-01
 * Time: 12:47
 */

namespace inhere\webSocket\server;

use inhere\webSocket\http\Request;
use inhere\webSocket\http\Response;
use inhere\webSocket\http\Uri;
use Swoole\Http\Request as SWRequest;
use Swoole\Http\Response as SWResponse;
use Swoole\Websocket\Frame;
use Swoole\Websocket\Server;

/**
 * Class Server
 * power by `swoole` extension
 * @package inhere\webSocket\server
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
    protected $name = 'swoole';

    /**
     * @var Server
     */
    private $server;

    /**
     * @return bool
     */
    public static function isSupported()
    {
        return extension_loaded('swoole');
    }

    public function __construct($host = '0.0.0.0', $port = 8080, array $options = [])
    {
        $this->options['mode'] = self::MODE_PROCESS;
        $this->options['swoole'] = [
            // 'user'    => '',
            'worker_num'    => 4,
            'task_worker_num' => 2, // 启用 task worker,必须为Server设置onTask和onFinish回调
            'daemonize'     => 0,
            'max_request'   => 1000,
            // 在1.7.15以上版本中，当设置dispatch_mode = 1/3时会自动去掉onConnect/onClose事件回调。
            // see @link https://wiki.swoole.com/wiki/page/49.html
            'dispatch_mode' => 1,
            // 'log_file' , // '/tmp/swoole.log', // 不设置log_file会打印到屏幕

            // 使用SSL必须在编译swoole时加入--enable-openssl选项 并且配置下面两项
            // 'ssl_cert_file' => __DIR__.'/config/ssl.crt',
            // 'ssl_key_file' => __DIR__.'/config/ssl.key',
        ];

        parent::__construct($host, $port, $options);
    }

    /**
     * create and prepare socket resource
     */
    protected function prepareWork()
    {
        $host = $this->getHost();
        $port = $this->getPort();
        $mode = $this->getOption('mode') === self::MODE_BASE ? SWOOLE_BASE : SWOOLE_PROCESS;
        $socketType = SWOOLE_SOCK_TCP;

        if ($this->getOption('enable_ssl')) {
            $this->checkEnvWhenEnableSSL();
            $socketType |= SWOOLE_SSL;
        }

        $this->server = new Server($host, $port, $mode, $socketType);

        // setting swoole config
        // 对于Server的配置即 $server->set() 中传入的参数设置，必须关闭/重启整个Server才可以重新加载
        $this->server->set($this->getOption('swoole', []));

        $max = $this->getOption('max_conn', 20);

        $this->log("Started WebSocket server on {$this->host}:{$this->port} (max allow connection: $max)");
    }

    /**
     * do start server
     */
    protected function doStart()
    {
        // register events
        $this->server->on(self::ON_CONNECT, [$this, 'onConnect']);

        // 设置onHandShake回调函数后不会再触发onOpen事件，需要应用代码自行处理
        // onHandShake函数必须返回true表示握手成功，返回其他值表示握手失败
        $this->server->on(self::ON_HANDSHAKE, [$this, 'onHandshake']);
        // $this->server->on(self::ON_OPEN, [$this, 'open']);

        $this->server->on(self::ON_MESSAGE, [$this, 'onMessage']);
        $this->server->on(self::ON_CLOSE, [$this, 'onClose']);

        $this->server->on('task', [$this, 'onTask']);
        $this->server->on('finish', [$this, 'onFinish']);

        $this->server->start();
    }

    public function onConnect(Server $server, int $fd)
    {
        $this->connect($fd);
    }

    public function onHandShake(SWRequest $swRequest, SWResponse $swResponse)
    {
        $cid = $swRequest->fd;

        $method = $swRequest->server['request_method'];
        $uriStr = $swRequest->server['request_uri'];

        $request = new Request($method, Uri::createFromString($uriStr));
        $request->setHeaders($swRequest->header);
        $request->setCookies($swRequest->cookie);

        $this->log("Ready to shake hands with the #$cid client connection. request:\n" . $request->toString());

        $response = new Response();

        // 解析请求头信息错误
        if (!$secKey = $swRequest->header["sec-websocket-key"]) {
            $this->log("handle handshake failed! [Sec-WebSocket-Key] not found in header. Data: \n" . $request->toString(), 'error');

            $swResponse->status(404);
            $swResponse->write('<b>400 Bad Request</b><br>[Sec-WebSocket-Key] not found in request header.');

            // $this->close($cid, $socket, false);
            return false;
        }

        // 触发 handshake 事件回调，如果返回 false -- 拒绝连接，比如需要认证，限定路由，限定ip，限定domain等
        // 就停止继续处理。并返回信息给客户端
        if ( false === $this->trigger(self::ON_HANDSHAKE, [$request, $response, $cid]) ) {
            $this->log("The #$cid client handshake's callback return false, will close the connection", 'notice');

            $swResponse->status($response->getStatusCode());

            foreach ($response->getHeaders() as $name => $value) {
                $swResponse->header($name, $value);
            }

            $swResponse->write($response->getBody());

            // $this->close($cid, $socket, false);
            return false;
        }

        // general key
        $sign = $this->genSign($secKey);
        $response->setHeaders([
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Accept' => $sign,
        ]);

        // 响应握手成功
        $swResponse->status(101);
        foreach ($response->getHeaders() as $name => $value) {
            $swResponse->header($name, $value);
        }

        // 标记已经握手 更新路由 path
        $meta = $this->metas[$cid];
        $meta['handshake'] = true;
        $meta['path'] = $request->getPath();
        $this->metas[$cid] = $meta;

        $this->log("The #$cid client connection handshake successful! Info:", 'info', $meta);

        // $this->server->defer(function() use($request) {});

        // 握手成功 触发 open 事件
        $this->trigger(self::ON_OPEN, [$this, $request, $cid]);

        return true;
    }

    public function onMessage(Server $server, Frame $frame)
    {
        $this->message($frame->fd, $frame->data, strlen($frame->data));
    }

    /**
     * @param Server $server
     * @param int $cid
     * @return bool
     */
    public function onClose(Server $server, $cid)
    {
        $meta = $this->metas[$cid];
        unset($this->metas[$cid], $this->clients[$cid]);

        // call close handler
        $this->trigger(self::ON_CLOSE, [$this, $cid, $meta]);

        $this->log("The #$cid client connection has been closed! Count: " . $this->count());

        return true;
    }

    ////////////////////// Task Event //////////////////////

    /**
     * 处理异步任务( onTask )
     * @param  Server $server
     * @param  int           $taskId
     * @param  int           $fromId
     * @param  mixed         $data
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
     * @param  int           $taskId
     * @param  mixed         $data
     */
    public function onFinish(Server $server, $taskId, $data)
    {
        //$this->addLog("AsyncTask[$taskId] Finish. Data: $data");
    }

    /**
     * @param int $fd
     */
    protected function connect($fd)
    {
        $cid = $fd;
        // @link https://wiki.swoole.com/wiki/page/p-connection_info.html
        $data = $this->server->getClientInfo($fd);

        // 初始化客户端信息
        $this->metas[$cid] = $meta = [
            'host' => $data['remote_ip'],
            'port' => $data['remote_port'],
            'handshake' => false,
            'path' => '/',
        ];

        // 客户端连接单独保存。 这里为了兼容其他驱动，保存了cid
        $this->clients[$cid] = $cid;

        $this->log("A new client connected, ID: $cid, From {$meta['host']}:{$meta['port']}. Count: " . $this->count());

        // 触发 connect 事件回调
        $this->trigger(self::ON_CONNECT, [$this, $cid]);
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
        // $data = $this->decode($data);

        $this->log("Received $bytes bytes message from #$cid, Data: $data");

        // call on message handler
        $this->trigger(self::ON_MESSAGE, [$this, $data, $cid, $meta]);
    }

    /**
     * @inheritdoc
     */
    public function close(int $cid, $socket = null, bool $triggerEvent = true)
    {
        // close socket connection
        $this->doClose($cid);

        $meta = $this->metas[$cid];
        unset($this->metas[$cid], $this->clients[$cid]);

        // call close handler
        if ( $triggerEvent ) {
            $this->trigger(self::ON_CLOSE, [$this, $cid, $meta]);
        }

        $this->log("The #$cid client connection has been closed! Count: " . $this->count());
    }

    /**
     * Closing a connection
     * @param int $cid
     * @return bool
     */
    protected function doClose($cid)
    {
        return $this->server->close($cid);
    }


    public function sendToAll(string $data, int $sender = 0): int
    {
        $startFd = 0;
        $count = 0;

        while(true) {
            $connList = $this->server->connection_list($startFd, 50);

            if($connList===false || ($num = count($connList)) === 0) {
                break;
            }

            $count += $num;
            $startFd = end($connList);

            foreach($connList as $fd) {
                $this->server->send($fd, $data);
            }
        }

        return $count;
    }

    public function sendToSome(string $data, array $receivers = [], array $expected = [], int $sender = 0): int
    {

    }

    /**
     */
    protected function checkEnvWhenEnableSSL()
    {
        if ( !defined('SWOOLE_SSL')) {
            $this->print('[ERROR] If you want use SSL(https), must add option --enable-openssl on the compile swoole.', true, -500);
        }

        // check ssl config
        if ( !$this->getOption('ssl_cert_file') || !$this->getOption('ssl_key_file')) {
            $this->print("[ERROR] If you want use SSL(https), must config the 'swoole.ssl_cert_file' and 'swoole.ssl_key_file'", true, -500);
        }
    }

    /**
     * response data to client by socket connection
     * @param int    $fd
     * @param string $data
     * @param int    $length
     * @return int   Return error number code. gt 0 on failure, eq 0 on success
     */
    public function writeTo($fd, string $data, int $length = 0)
    {
        $finish = true;
        $opcode = 1;

        return $this->server->push($fd, $data, $opcode, $finish) ? 0 : 1;
    }

    /**
     * @param int $cid
     * @return bool
     */
    public function exist(int $cid)
    {
        return $this->server->exist($cid);
    }

    /**
     * @param null|resource $socket
     * @return int
     */
    public function getErrorNo($socket = null)
    {
        return $this->server->getLastError();
    }

    /**
     * @param null|resource $socket
     * @return string
     */
    public function getErrorMsg($socket = null)
    {
        return '';
    }

    /**
     * @return resource
     */
    public function getSocket(): resource
    {
        return $this->server->getSocket();
    }
}
