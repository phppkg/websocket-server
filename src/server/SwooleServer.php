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

        $this->server->on(self::ON_MESSAGE, [$this, 'message']);
        $this->server->on(self::ON_CLOSE, [$this, 'close']);

        $this->server->start();
    }

    public function onConnect(Server $server, int $fd)
    {

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

        // $this->server->defer(function() use($request) {});

        // 握手成功 触发 open 事件
        $this->trigger(self::ON_OPEN, [$this, $request, $cid]);

        return true;
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

        // 客户端连接单独保存 todo 可以不保存
        $this->clients[$cid] = $this->getSocket();

        $this->log("A new client connected, ID: $cid, From {$meta['host']}:{$meta['port']}. Count: " . $this->count());

        // 触发 connect 事件回调
        $this->trigger(self::ON_CONNECT, [$this, $cid]);
    }

    /**
     * Closing a connection
     * @param resource $socket
     * @return bool
     */
    protected function doClose($socket)
    {
        // TODO: Implement doClose() method.
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
     * @param resource $socket
     * @param string $data
     * @param int $length
     * @return int      Return socket last error number code. gt 0 on failure, eq 0 on success
     */
    public function writeTo($socket, string $data, int $length = 0)
    {
        // TODO: Implement writeTo() method.
    }

    /**
     * @param null|resource $socket
     * @return int
     */
    public function getErrorNo($socket = null)
    {
        // TODO: Implement getErrorNo() method.
    }

    /**
     * @param null|resource $socket
     * @return string
     */
    public function getErrorMsg($socket = null)
    {
        // TODO: Implement getErrorMsg() method.
    }

    /**
     * @return resource
     */
    public function getSocket(): resource
    {
        return $this->server->getSocket();
    }
}
