<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-04-01
 * Time: 15:55
 */

namespace inhere\webSocket\client;

use inhere\exceptions\ConnectException;
use inhere\webSocket\WSAbstracter;
use inhere\webSocket\http\Request;
use inhere\webSocket\http\Response;
use inhere\webSocket\http\Uri;

/**
 * Class ClientAbstracter
 * @package inhere\webSocket\client
 */
abstract class ClientAbstracter extends WSAbstracter implements ClientInterface
{
    const PROTOCOL_WS = 'ws';
    const PROTOCOL_WSS = 'wss';

    const DEFAULT_HOST = '127.0.0.1';
    const DEFAULT_FRAGMENT_SIZE = 1024;

    /**
     * eg `ws://127.0.0.1:9501/chat`
     * @var string
     */
    protected $url;

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
     * @var string
     */
    private $key;

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

        'timeout' => 2.2,

        // 数据块大小 发送数据时将会按这个大小拆分发送
        'fragment_size' => 1024,

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
        return [self::ON_OPEN, self::ON_MESSAGE, self::ON_TICK, self::ON_CLOSE, self::ON_ERROR];
    }

    /**
     * WebSocket constructor.
     * @param string $url `ws://127.0.0.1:9501/chat`
     * @param array $options
     */
    public function __construct(string $url, array $options = [])
    {
        if (!static::isSupported()) {
            $this->print("[ERROR] Your system is not supported the driver: {$this->name}, by " . static::class, true, -200);
        }

        $this->url = $url;
        $this->setOptions($options, true);
        $this->init();

        $this->log("The webSocket client power by [{$this->name}], remote server is {$url}");
    }

    /**
     * destruct
     */
    public function __destruct()
    {
        if ($this->isConnected()) {
            $this->close();
        }
    }

    protected function init()
    {
        $uri = Uri::createFromString($this->url); // todo ...
        $this->request = new Request('GET', $uri);

        $this->host = $uri->getHost();
        $this->port = $uri->getPort();

        $headers = $this->getDefaultHeaders();

        // Handle basic authentication.
        if ($user = $uri->getUserInfo()) {
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

    // start a keep-live client
    public function start()
    {
        if (!$this->isConnected()) {
            $this->connect();
        }

        $tickHandler = $this->getCallback(self::ON_TICK);
        $msgHandler = $this->getCallback(self::ON_MESSAGE);

        while (true) {
            if ($tickHandler && (call_user_func($tickHandler, $this) === false)) {
                break;
            }

            $write = $except = null;
            $changed = [$this->socket];

            if (stream_select($changed, $write, $except, null) > 0) {
                foreach ($changed as $socket) {
                    $message = $this->receive();

                    if ($message !== false && $msgHandler) {
                        call_user_func($msgHandler, $message, $this);
                    }
                }
            }

            usleep(5000);
        }
    }

    /**
     * @param float $timeout
     * @param int $flags
     * @return bool
     */
    public function connect($timeout = 0.1, $flags = 0)
    {
        $this->doConnect($timeout, $flags);

        $this->connected = true;

        return $this->doHandShake();
    }

    /**
     * @param float $timeout
     * @param int $flags
     */
    abstract protected function doConnect($timeout = 2.1, $flags = 0);

    /**
     * doHandShake
     * @return bool
     */
    public function doHandShake()
    {
        $request = $this->request->toString();
        $this->log("Request header: \n$request");
        $this->write($request);

        // Get server response header
        $respHeader = $this->readResponseHeader();
        $this->log("Response header: \n$respHeader");

        return $this->checkResponse($respHeader);
    }

    /**
     * check the handShake Response
     * @param string $header
     * @return bool
     * @throws ConnectException
     */
    protected function checkResponse($header)
    {
        // Validate response.
        if (!preg_match('#Sec-WebSocket-Accept:\s(.*)$#mUi', $header, $matches)) {
            $this->close();

            // $address = $scheme . '://' . $host . $uri->getPathAndQuery();
            throw new ConnectException("Connection to '{$this->url}' failed: Server sent invalid upgrade response header:\n$header");
        }

        $secAccept = trim($matches[1]);
        $genKey = base64_encode(pack('H*', sha1($this->key . self::SIGN_KEY)));

        if ($secAccept !== $genKey) {
            $this->log("check response header is failed!\n sec-accept: {$secAccept}，sec-local: {$genKey}");
            $this->close();

            return false;
        }

        $this->log('the hand shake is successful!');

        return true;
    }

    /**
     * @param null|int $length If is NULL, read all.
     * @return string
     * @throws ConnectException
     */
    protected function read($length = null)
    {
        if ($length > 0) {
            return $this->readLength($length);
        }

        $data = '';
        $fragmentSize = $this->getOption('fragment_size') ?: self::DEFAULT_FRAGMENT_SIZE;

        do {
            $buff = fread($this->socket, $fragmentSize);

            if ($buff === false) {
                $this->log('read data is failed. Stream state: ', 'error');
                return false;
            }

            $data .= $buff;
            usleep(1000);

        } while (!feof($this->socket));

        return $data;
    }

    /**
     * @param $length
     * @return string
     * @throws ConnectException
     */
    protected function readLength($length)
    {
        $buffer = fread($this->socket, $length);

        if ($buffer === false) {
            throw new ConnectException("Broken frame, read return 'FALSE' of stated $length bytes");
        }

        if ($buffer === '') {
            throw new ConnectException('Empty read; connection dead?');
        }

        return $buffer;
    }

    /**
     * @param string $data
     * @return bool|int
     * @throws ConnectException
     */
    protected function write($data)
    {
        $written = fwrite($this->socket, $data);

        if ($written < ($dataLen = strlen($data))) {
            throw new ConnectException("Could only write $written out of $dataLen bytes.");
        }

        return $written;
    }

    const SEND_ALL_ONCE = 1;
    const SEND_ALL_FRAGMENT = 2;

    /**
     * @inheritdoc
     */
    public function send($data, $flag = null)
    {
        $this->log("Send data to server, Data: $data");

        return $this->sendByFragment($data);
    }

    /**
     * @param string $payload
     * @param string $opcode
     * @param bool $masked
     * @return int
     */
    protected function sendByFragment($payload, $opcode = 'text', $masked = true)
    {
        if ( !$this->connected ) {
            $this->connect();
        }

        if (!isset(self::$opCodes[$opcode])) {
            throw new \InvalidArgumentException("Bad opcode '$opcode'.  Try 'text' or 'binary'.");
        }

        // record the length of the payload
        $payloadLength = strlen($payload);
        $fragmentCursor = 0;
        $fragmentSize = $this->getOption('fragment_size') ?: self::DEFAULT_FRAGMENT_SIZE;

        // while we have data to send
        while ($payloadLength > $fragmentCursor) {
            // get a fragment of the payload
            $sub_payload = substr($payload, $fragmentCursor, $fragmentSize);

            // advance the cursor
            $fragmentCursor += $fragmentSize;

            // is this the final fragment to send?
            $final = $payloadLength <= $fragmentCursor;

            // send the fragment
            $encoded = $this->encode($sub_payload, $opcode, $masked, $final);
            $this->write($encoded);

            // all fragments after the first will be marked a continuation
            $opcode = 'continuation';
        }

        return $payloadLength;
    }

    public function sendFile(string $filename)
    {}

    /**
     * @return string
     */
    public function readResponseHeader()
    {
        $header = '';

        // fgets() 从文件指针中读取一行。 从 handle 指向的文件中读取一行并返回长度最多为 length - 1 字节的字符串。
        // 碰到换行符（包括在返回值中）、EOF 或者已经读取了 length - 1 字节后停止（看先碰到那一种情况）。
        // 如果没有指定 length，则默认为 1K，或者说 1024 字节。
        // 这里到了 head 与 body 分隔时的一行是 `\r\n\r\n` trim 就是空字符串，就会停止继续读取
        while ($str = trim(fgets($this->socket, 1024))) {
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
        $body   = '';
        $header = $this->readResponseHeader();

        // feof() — 测试文件指针是否到了文件结束的位置
        while (!feof($this->socket)) {
            $body .= fgets($this->socket, 4096);
        }

        return [$header, $body];
    }

    /**
     * @param bool $force
     */
    public function disconnect(bool $force = false)
    {
        $this->close($force);
    }
    public function close(bool $force = false)
    {
        if ( $this->socket ) {
            $this->socket = null;
        }

        $this->connected = false;
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

        // Write frame head to frame.
        foreach (str_split($head, 8) as $binStr) {
            $frame .= chr(bindec($binStr));
        }

        // Handle masking
        $mask = '';
        if ($masked) {
            for ($i = 0; $i < 4; ++$i) {
                $mask .= chr(random_int(0, 255));
            }

            $frame .= $mask;
        }

        // Append payload data to frame:
        for ($i = 0; $i < $length; ++$i) {
            $frame .= ($masked === true) ? $data[$i] ^ $mask[$i % 4] : $data[$i];
        }

        return $frame;
    }

    /**
     * @param int $size
     * @param null $flag
     * @return bool|string
     */
    public function receive($size = 65535, $flag = null)
    {
        $data = fread($this->socket, 2);

        if (strlen($data) === 1) {
            $data .= fread($this->socket, 1);
        }

        if ($data === false || strlen($data) < 2) {
            return false;
        }

        $final = (bool) (ord($data[0]) & 1 << 7);
        $rsv1 = (bool) (ord($data[0]) & 1 << 6);
        $rsv2 = (bool) (ord($data[0]) & 1 << 5);
        $rsv3 = (bool) (ord($data[0]) & 1 << 4);

        $opcode = ord($data[0]) & 31;
        $masked = (bool) (ord($data[1]) >> 7);
        $payload = '';
        $length = (int) (ord($data[1]) & 127); // Bits 1-7 in byte 1

        if ($length > 125) {
            $temp = $length === 126 ? fread($this->socket, 2) : fread($this->socket, 8);
            if ($temp === false) {
                return false;
            }

            $length = '';
            $max = strlen($temp);

            for ($i = 0; $i < $max; ++$i) {
                $length .= sprintf('%08b', ord($temp[$i]));
            }

            $length = bindec($length);
        }

        $mask = '';
        if ($masked) {
            $mask = fread($this->socket, 4);
            if ($mask === false) {
                return false;
            }
        }

        if ($length > 0) {
            $fragmentSize = $this->getOption('fragment_size');
            $temp = '';
            do {
                $buff = fread($this->socket, min($length, $fragmentSize));

                if ($buff === false) {
                    return false;
                }

                $temp .= $buff;
            } while (strlen($temp) < $length);

            if ($masked) {
                for ($i = 0; $i < $length; ++$i) {
                    $payload .= ($temp[$i] ^ $mask[$i % 4]);
                }
            } else {
                $payload = $temp;
            }
        }

        if ($opcode === static::$opCodes['close']) {
            return false;
        }

        return $final ? $payload : ($payload . $this->receive());
    }

    /////////////////////////////////////////////////////////////////////////////////////////
    /// getter/setter method
    /////////////////////////////////////////////////////////////////////////////////////////

    /**
     * Default headers
     * @return array
     */
    public function getDefaultHeaders()
    {
        $this->key = $this->genKey();

        return [
            'Host' => $this->getHost() . ':' . $this->getPort(),
            'User-Agent' => 'php-webSocket-client',
            'Connection' => 'Upgrade',
            'Upgrade'   => 'websocket',
            'Sec-WebSocket-Key' => $this->key,
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
     * @return string
     */
    public function getUrl(): string
    {
        return $this->url;
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
     * @return int
     */
    abstract public function getErrorNo();

    /**
     * @return string
     */
    abstract public function getErrorMsg();

    /**
     * @return string
     */
    public function getKey(): string
    {
        return $this->key;
    }

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
}
