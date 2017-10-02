<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-04-01
 * Time: 15:55
 */

namespace Inhere\WebSocket\Client;

use Inhere\Exceptions\ConnectException;
use Inhere\Library\Utils\LiteLogger;
use Inhere\WebSocket\WSAbstracter;
use Inhere\Http\Request;
use Inhere\Http\Response;
use Inhere\Http\Uri;

/**
 * Class ClientAbstracter
 * @package Inhere\WebSocket\Client
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
     * the ws state
     * @var integer
     */
    private $state = 0;

    /**
     * @return array
     */
    public function getSupportedEvents(): array
    {
        return [self::ON_OPEN, self::ON_MESSAGE, self::ON_TICK, self::ON_CLOSE, self::ON_ERROR];
    }

    /**
     * @return array
     */
    public function appendDefaultConfig()
    {
        return [
            // stream context
            'context' => null,

            // swoole config
            'swoole' => [],

            'http_auth' => [
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
    }

    /**
     * WebSocket constructor.
     * @param string $url `ws://127.0.0.1:9501/chat`
     * @param array $config
     */
    public function __construct(string $url = 'ws://127.0.0.1:9501', array $config = [])
    {
        $this->url = $url;

        parent::__construct($config);

        if (!static::isSupported()) {
            $this->cliOut->error("Your system is not supported the driver: {$this->driver}, by " . static::class, -200);
        }

        $this->cliOut->write("The webSocket client power by [<info>{$this->driver}</info>], remote server is <info>{$url}</info>");
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
        parent::init();

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

        if ($csHeaders = $this->get('headers')) {
            $this->request->setHeaders($csHeaders);
        }

        if ($csCookies = $this->get('cookies')) {
            $this->request->setCookies($csCookies);
        }
    }

    // start a keep-live client
    public function start()
    {
        if (!$this->isConnected()) {
            $this->connect();
        }

        $tickHandler = $this->getEventHandler(self::ON_TICK);
        $msgHandler = $this->getEventHandler(self::ON_MESSAGE);

        // interval time
        $setTime = (int)$this->get('sleep_ms', 500);
        $sleepTime = ($setTime > 50 ? $setTime : 500) * 1000; // ms -> us

        while (true) {
            if ($tickHandler && (call_user_func($tickHandler, $this) === false)) {
                $this->close();
                break;
            }

            $write = $except = null;
            $changed = [$this->socket];

            if (stream_select($changed, $write, $except, null) > 0) {
                //foreach ($changed as $socket) {
                $message = $this->receive();

                if ($message !== false && $msgHandler) {
                    call_user_func($msgHandler, $message, $this);
                }
                //}
            }

            usleep($sleepTime);
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

        $this->state = self::STATE_CONNECTED;

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
        $fragmentSize = $this->get('fragment_size') ?: self::DEFAULT_FRAGMENT_SIZE;

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
     * @param string $data
     * @param null|int $flag
     * @return mixed
     */
    public function send($data, $flag = null)
    {
        $this->log("Send data to server, Data: $data");

        return $this->sendByFragment($data);
    }

    /**
     * @param string $payload
     * @param string $opCode
     * @param bool $masked
     * @return int
     */
    protected function sendByFragment($payload, $opCode = 'text', $masked = true)
    {
        if (!$this->isConnected()) {
            $this->connect();
        }

        if (!isset(self::$opCodes[$opCode])) {
            throw new \InvalidArgumentException("Bad opcode '$opCode'.  Try 'text' or 'binary'.");
        }

        // record the length of the payload
        $payloadLength = strlen($payload);
        $fragmentCursor = 0;
        $fragmentSize = $this->get('fragment_size') ?: self::DEFAULT_FRAGMENT_SIZE;

        // while we have data to send
        while ($payloadLength > $fragmentCursor) {
            // get a fragment of the payload
            $sub_payload = substr($payload, $fragmentCursor, $fragmentSize);

            // advance the cursor
            $fragmentCursor += $fragmentSize;

            // is this the final fragment to send?
            $final = $payloadLength <= $fragmentCursor;

            // send the fragment
            $encoded = $this->encode($sub_payload, $opCode, $masked, $final);
            $this->write($encoded);

            // all fragments after the first will be marked a continuation
            $opcode = 'continuation';
        }

        return $payloadLength;
    }

    public function sendFile(string $filename)
    {
    }

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
        $body = '';
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
        if ($this->socket) {
            $this->socket = null;
        }

        $this->state = self::STATE_CLOSED;
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

        $final = (bool)(ord($data[0]) & 1 << 7);
        $rsv1 = (bool)(ord($data[0]) & 1 << 6);
        $rsv2 = (bool)(ord($data[0]) & 1 << 5);
        $rsv3 = (bool)(ord($data[0]) & 1 << 4);

        $opcode = ord($data[0]) & 31;
        $masked = (bool)(ord($data[1]) >> 7);
        $payload = '';
        $length = (int)(ord($data[1]) & 127); // Bits 1-7 in byte 1

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
            $fragmentSize = $this->get('fragment_size');
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

    /**
     * output and record websocket log message
     * @param  string $msg
     * @param  array $data
     * @param string $type
     * @return bool|void
     */
    public function log(string $msg, $type = 'debug', array $data = [])
    {
        // if close debug, don't output debug log.
        if ($this->isDebug() || $type !== 'debug') {

            list($time, $micro) = explode('.', microtime(1));

            $time = date('Y-m-d H:i:s', $time);
            $json = $data ? json_encode($data) : '';
            $type = strtoupper(trim($type));

            $this->cliOut->write("[{$time}.{$micro}] [$type] $msg {$json}");

            if ($logger = $this->getLogger()) {
                $logger->$type(strip_tags($msg), $data);
            }
        }
    }

    /////////////////////////////////////////////////////////////////////////////////////////
    /// getter/setter method
    /////////////////////////////////////////////////////////////////////////////////////////

    /**
     * @return int
     */
    public function getState(): int
    {
        return $this->state;
    }

    /**
     * is waiting
     * @return boolean
     */
    public function isWaiting(): bool
    {
        return $this->state === self::STATE_WAITING;
    }

    /**
     * is Connected
     * @return boolean
     */
    public function isConnected(): bool
    {
        return $this->state === self::STATE_CONNECTED;
    }

    /**
     * is closing
     * @return boolean
     */
    public function isClosing(): bool
    {
        return $this->state === self::STATE_CLOSING;
    }

    /**
     * is closed
     * @return boolean
     */
    public function isClosed(): bool
    {
        return $this->state === self::STATE_CLOSED;
    }

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
            'Upgrade' => 'websocket',
            'Sec-WebSocket-Key' => $this->key,
            'Sec-WebSocket-Version' => self::WS_VERSION,
            'Sec-WebSocket-Protocol' => 'sws',
        ];
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
}
