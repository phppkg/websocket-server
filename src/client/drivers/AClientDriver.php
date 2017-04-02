<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-04-01
 * Time: 15:55
 */

namespace inhere\webSocket\client\drivers;

use inhere\exceptions\ConnectException;
use inhere\library\traits\TraitSimpleFixedEvent;
use inhere\library\traits\TraitUseSimpleOption;
use inhere\webSocket\http\Request;
use inhere\webSocket\http\Response;
use inhere\webSocket\http\Uri;

/**
 * Class AClientDriver
 * @package inhere\webSocket\client\drivers
 */
abstract class AClientDriver implements IClientDriver
{
    use TraitSimpleFixedEvent;
    use TraitUseSimpleOption;

    /**
     * Websocket version
     */
    const WS_VERSION = '13';

    const PROTOCOL_WS = 'ws';
    const PROTOCOL_WSS = 'wss';

    // abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!"§$%&/()=[]{}
    const TOKEN_CHARS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!"$&/()=[]{}0123456789';

    const DEFAULT_HOST = '127.0.0.1';
    const DEFAULT_PORT = 8080;


    /**
     * eg `ws://127.0.0.1:9501/chat`
     * @var string
     */
    protected $url;

    /**
     * @var string
     */
    protected $host;

    /**
     * @var int
     */
    protected $port;

    /**
     * @var resource
     */
    protected $socket;

    /**
     * all available opCodes
     * @var array
     */
    protected static $opCodes = [
        'continuation' => 0,
        'text' => 1,
        'binary' => 2,
        'close' => 8,
        'ping' => 9,
        'pong' => 10,
    ];

    /**
     * @var Request
     */
    protected $request;

    /**
     * @var Response
     */
    protected $response;

    /**
     * @var bool
     */
    private $connected = false;

    /**
     * @var array
     */
    protected $options = [
        'debug' => false,

        'open_log' => true,
        'log_file' => '',

        'timeout' => 3,

        // stream context
        'context' => null,

        'auth' => [
            // 'username'=>"",
            // 'password'=>"",
            // 'type'=>"" // basic | digest
        ],

        // append headers
        'headers' => [
            'origin' => '',
        ],

        // append headers
        'cookies' => [],
    ];

    /**
     * @return array
     */
    public function getSupportedEvents(): array
    {
        return [self::ON_OPEN, self::ON_MESSAGE, self::ON_CLOSE, self::ON_ERROR];
    }

    /**
     * WebSocket constructor.
     * @param string $url `ws://127.0.0.1:9501/chat`
     * @param array $options
     */
    public function __construct(string $url, array $options = [])
    {
        $this->setOptions($options, true);

        $this->url = $url;

        $uri = Uri::createFromString($url); // todo ...
        $this->request = new Request('GET', $uri);

        $this->host = $uri->getHost();
        $this->port = $uri->getPort();

        $this->init();
    }

    public function __destruct()
    {
        $this->close();
    }

    protected function init()
    {
        $headers = $this->getDefaultHeaders();

        // Handle basic authentication.
        if ($user = $this->request->getUri()->getUserInfo()) {
            $headers['authorization'] = 'Basic ' . base64_encode($user);
        }

        $this->request->setHeaders($headers);

        if ($csHeaders = $this->getOption('headers')) {
            $this->request->setHeaders($csHeaders);
        }

        if ($csCookies = $this->getOption('cookies')) {
            $this->request->setCookies($csCookies);
        }
    }

    abstract public function connect($timeout = 0.1, $flag = 0);

    public function wait()
    {

    }

    public function onOpen(callable $callback)
    {
        $this->callbacks[self::ON_OPEN] = $callback;
    }

    public function onMessage(callable $callback)
    {
        $this->callbacks[self::ON_MESSAGE] = $callback;
    }

    public function onClose(callable $callback)
    {
        $this->callbacks[self::ON_CLOSE] = $callback;
    }

    public function onError(callable $callback)
    {
        $this->callbacks[self::ON_ERROR] = $callback;
    }

    /**
     * @inheritdoc
     */
    public function send($data, $flag = null)
    {
        $written = fwrite($this->socket, $data);

        if ($written < ($dataLen = strlen($data))) {
            throw new ConnectException("Could only write $written out of $dataLen bytes.");
        }

        return $written;
    }

    public function sendFile(string $filename)
    {

    }

    public function receive($size = null, $flag = null)
    {

    }

    public function readResponseHeader()
    {
        $header = '';

        // fgets() 从文件指针中读取一行。 从 handle 指向的文件中读取一行并返回长度最多为 length - 1 字节的字符串。
        // 碰到换行符（包括在返回值中）、EOF 或者已经读取了 length - 1 字节后停止（看先碰到那一种情况）。
        // 如果没有指定 length，则默认为 1K，或者说 1024 字节。
        while ($str = trim(fgets($this->socket, 4096))) {
            $header .= "$str\n";
        }

        return $header;
    }

    /**
     * http://php.net/manual/zh/stream.examples.php
     * @return array
     */
    public function readResponse()
    {
        $header = $body = '';

        // fgets() 从文件指针中读取一行。 从 handle 指向的文件中读取一行并返回长度最多为 length - 1 字节的字符串。
        // 碰到换行符（包括在返回值中）、EOF 或者已经读取了 length - 1 字节后停止（看先碰到那一种情况）。
        // 如果没有指定 length，则默认为 1K，或者说 1024 字节。
        while ($str = trim(fgets($this->socket, 4096))) {
            $header .= "$str\n";
        }

        // feof() — 测试文件指针是否到了文件结束的位置
        while (!feof($this->socket)) {
            $body .= fgets($this->socket, 4096);
        }

        return [$header, $body];
    }

    public function close(bool $force = false)
    {
        if ( $this->socket ) {
            $this->socket = null;
        }
    }

    /**
     * Helper to convert a binary to a string of '0' and '1'.
     * @param string $binStr
     * @return string
     */
    protected static function bin2String($binStr)
    {
        $string = '';
        $len = strlen($binStr);

        for ($i = 0; $i < $len; $i++) {
            $string .= sprintf('%08b', ord($binStr[$i]));
        }

        return $string;
    }

    /**
     * Default headers
     * @return array
     */
    public function getDefaultHeaders()
    {
        return [
            'Host' => $this->getHost() . ':' . $this->getPort(),
            'User-Agent' => 'php-webSocket-client',
            'Connection' => 'Upgrade',
            'Upgrade'   => 'websocket',
            'Sec-WebSocket-Key' => $this->genKey(),
            'Sec-WebSocket-Version' => self::WS_VERSION,
            'Sec-WebSocket-Protocol' => 'sws',
        ];
    }

    /**
     * @return array
     */
    public static function getOpCodes(): array
    {
        return self::$opCodes;
    }

    /**
     * @return array
     */
    public function getSupportedTransports()
    {
        return stream_get_transports();
    }

    /**
     * @return array
     */
    public function getSupportedWrappers()
    {
        return stream_get_wrappers();
    }

    /**
     * @param null|resource $socket
     * @return bool
     */
    abstract public function getLastErrorNo($socket = null);

    /**
     * @param null|resource $socket
     * @return string
     */
    abstract public function getLastError($socket = null);

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
     * @return string
     */
    public function getHost(): string
    {
        if ( !$this->host ) {
            $this->host = self::DEFAULT_HOST;
        }

        return $this->host;
    }

    /**
     * @return int
     */
    public function getPort(): int
    {
        if ( !$this->port || $this->port <= 0 ) {
            $this->port = self::DEFAULT_PORT;
        }

        return $this->port;
    }

    /**
     * @return Uri
     */
    public function getUri()
    {
        return $this->request->getUri();
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
     * Generate a random string for WebSocket key.(for client)
     * @return string Random string
     */
    public function genKey(): string
    {
        $key = '';
        $chars = self::TOKEN_CHARS;
        $chars_length = strlen($chars);

        for ($i = 0; $i < 16; $i++) {
            $key .= $chars[random_int(0, $chars_length - 1)]; //mt_rand
        }

        return base64_encode($key);
    }

    /**
     * @param string $data
     * @param string $opCode
     * @param bool $masked
     * @param bool $final
     * @return string
     */
    protected function encode(string $data, string $opCode = 'text', bool $masked = true, bool $final = true)
    {
        $length = strlen($data);
        $head = '';
        $head .= $final ? '1' : '0';
        $head .= '000';
        $head .= sprintf('%04b', static::$opCodes[$opCode]);
        $head .= $masked ? '1' : '0';

        if ($length > 65535) {
            $head .= decbin(127);
            $head .= sprintf('%064b', $length);
        } elseif ($length > 125) {
            $head .= decbin(126);
            $head .= sprintf('%016b', $length);
        } else {
            $head .= sprintf('%07b', $length);
        }

        $frame = '';

        foreach (str_split($head, 8) as $binStr) {
            $frame .= chr(bindec($binStr));
        }

        $mask = '';
        if ($masked) {
            for ($i = 0; $i < 4; ++$i) {
                $mask .= chr(random_int(0, 255));
            }

            $frame .= $mask;
        }

        for ($i = 0; $i < $length; ++$i) {
            $frame .= ($masked === true) ? $data[$i] ^ $mask[$i % 4] : $data[$i];
        }

        return $frame;
    }

    /**
     * @param string $message
     * @param string $type
     * @param array $data
     */
    public function log(string $message, string $type = 'info', array $data = [])
    {
        $date = date('Y-m-d H:i:s');
        $type = strtoupper(trim($type));

        $this->print("[$date] [$type] $message " . ( $data ? json_encode($data) : '' ) );
    }

    /**
     * @param mixed $messages
     * @param bool $nl
     * @param null|int $exit
     */
    public function print($messages, $nl = true, $exit = null)
    {
        $text = is_array($messages) ? implode(($nl ? "\n" : ''), $messages) : $messages;

        fwrite(\STDOUT, $text . ($nl ? "\n" : ''));

        if ( $exit !== null ) {
            exit((int)$exit);
        }
    }
}
