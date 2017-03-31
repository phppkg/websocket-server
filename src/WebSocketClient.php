<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-03-27
 * Time: 9:14
 */
namespace inhere\webSocket;

use inhere\webSocket\http\Request;
use inhere\webSocket\http\Response;

/**
 * Class WebSocketClient
 * @package inhere\webSocket
 */
class WebSocketClient extends BaseWebSocket
{
    const TOKEN_LENGHT = 16;
    const MSG_CONNECTED = 1;
    const MSG_DISCONNECTED = 2;
    const MSG_LOST_CONNECTION = 3;

    const ON_TICK = 'tick';

    /**
     * @var string
     */
    private $url;

    /**
     * @var resource
     */
    private $socket;

    private $key;

    /**
     * @var string
     */
    private $origin;

    /**
     * @var string
     */
    private $path;

    /**
     * @var Request
     */
    private $request;

    /**
     * @var Response
     */
    private $response;

    /**
     * @var bool
     */
    private $connected = false;

    /**
     * @var array
     */
    protected $options = [
        'debug'    => false,

        'open_log' => true,
        'log_file' => '',

        'timeout' => 3,

        // stream context
        'context' => null,
    ];

    /**
     * @return array
     */
    public function getSupportedEvents(): array
    {
        return [ self::ON_OPEN, self::ON_MESSAGE, self::ON_CLOSE, self::ON_ERROR];
    }

    /**
     * WebSocketClient constructor.
     * @param string $url eg `ws://127.0.0.1:9501/chat`
     * @param array $options
     */
    public function __construct(string $url, array $options = [])
    {
        parent::__construct($options);

        $this->url = $url;
    }

    public function __destruct()
    {
        if ($this->socket) {
            if (get_resource_type($this->socket) === 'stream') {
                fclose($this->socket);
            }

            $this->socket = null;
        }
    }

    public function start()
    {
        $this->connect();

        $tickHandler = $this->callbacks[self::ON_TICK];

        while (true) {
            if ( $tickHandler ) {
                if ( call_user_func($tickHandler, $this) === false ) {
                    break;
                }
            }

            usleep(5000);
        }
    }

    public function connect()
    {

    }

    protected function buildRequest()
    {
        $parts = parse_url($this->url);

        $scheme    = $parts['scheme'];
        $host      = $parts['host'];

        $user      = $parts['user'] ?? '';
        $pass      = $parts['pass'] ?? '';
        $port      = $parts['port'] ?? ($scheme === 'wss' ? 443 : 80);
        $path      = $parts['path'] ?? '/';
        $query     = $parts['query'] ?? '';
        $fragment  = $parts['fragment'] ?? '';

        $this->request = Request::make();
    }

    /**
     * Send a message to the server.
     * @param string $data
     * @param string $opCode
     */
    public function send(string $data, string $opCode = 'text')
    {

    }

    /**
     * Read data from a socket
     * @param resource $socket
     */
    public function receive($socket = null)
    {

    }

    public function onOpen(callable $callback)
    {

    }

    public function onMessage(callable $callback)
    {
        $this->callbacks[self::ON_MESSAGE] = $callback;
    }

    public function onClose(callable $callback)
    {

    }

    public function onError(callable $callback)
    {

    }


    protected function createHeader()
    {
        $host = $this->getHost();

        if ($host === '127.0.0.1' || $host === '0.0.0.0') {
            $host = 'localhost';
        }

        $origin = $this->getOrigin() ?: 'null';

        return
            "GET {$this->getPath()} HTTP/1.1" . "\r\n" .
            "Origin: {$origin}" . "\r\n" .
            "Host: {$host}:{$this->getPort()}" . "\r\n" .
            "Sec-WebSocket-Key: {$this->getKey()}" . "\r\n" .
            "User-Agent: PHPWebSocketClient/" . self::VERSION . "\r\n" .
            "Upgrade: websocket" . "\r\n" .
            "Connection: Upgrade" . "\r\n" .
            "Sec-WebSocket-Protocol: Wamp" . "\r\n" .
            "Sec-WebSocket-Version: 13" . "\r\n" . "\r\n";
    }


    /////////////////////////////////////////////////////////////////////////////////////////
    /// getter/setter method
    /////////////////////////////////////////////////////////////////////////////////////////

    /**
     * @return resource
     */
    public function getSocket(): resource
    {
        return $this->socket;
    }

    /**
     * @param resource $socket
     */
    public function setSocket(resource $socket)
    {
        $this->socket = $socket;
    }

    /**
     * @return mixed
     */
    public function getKey()
    {
        return $this->key;
    }

    /**
     * @param mixed $key
     */
    public function setKey($key)
    {
        $this->key = $key;
    }

    /**
     * @return mixed
     */
    public function getOrigin()
    {
        return $this->origin;
    }

    /**
     * @param mixed $origin
     */
    public function setOrigin($origin)
    {
        $this->origin = $origin;
    }

    /**
     * @return mixed
     */
    public function getPath()
    {
        if (!$this->path) {
            $this->path = '/';
        }

        return $this->path;
    }

    /**
     * @param mixed $path
     */
    public function setPath($path)
    {
        $this->path = $path;
    }

    /**
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->connected;
    }

    /**
     * @param bool $connected
     */
    public function setConnected(bool $connected)
    {
        $this->connected = $connected;
    }

    /**
     * @return string
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * @return Request
     */
    public function getRequest(): Request
    {
        return $this->request;
    }

    /**
     * @return Response
     */
    public function getResponse(): Response
    {
        return $this->response;
    }
}
