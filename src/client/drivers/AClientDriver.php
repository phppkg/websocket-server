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
    const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

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

        'timeout' => 3,
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

    /**
     * @param string $header
     * @return bool
     * @throws ConnectException
     */
    public function doHandShake($header)
    {
        $this->log("Response header: \n$header");

        // Validate response.
        if (!preg_match('#Sec-WebSocket-Accept:\s(.*)$#mUi', $header, $matches)) {
            $this->close();

            // $address = $scheme . '://' . $host . $uri->getPathAndQuery();
            throw new ConnectException("Connection to '{$this->url}' failed: Server sent invalid upgrade response header:\n$header");
        }

        $secAccept = $matches[1];

        if ($secAccept !== base64_encode(pack('H*', sha1($this->key . self::GUID)))) {
            $this->close();

            return false;
        }

        return true;
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

    const SEND_ALL_ONCE = 1;
    const SEND_ALL_FRAGMENT = 2;

    /**
     * @inheritdoc
     */
    public function send($data, $flag = null)
    {
        if ($flag === self::SEND_ALL_ONCE) {
            return $this->write($data);
        }

        return $this->sendByFragment($data);
    }

    /**
     * @param $length
     * @return string
     * @throws ConnectException
     */
    protected function read($length)
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

    /**
     * @param string $payload
     * @param string $opcode
     * @param bool $masked
     * @return int
     */
    public function sendByFragment($payload, $opcode = 'text', $masked = true)
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
        $fragmentSize = $this->getOption('fragment_size');

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
        $header = $body = '';

        // fgets() 从文件指针中读取一行。 从 handle 指向的文件中读取一行并返回长度最多为 length - 1 字节的字符串。
        // 碰到换行符（包括在返回值中）、EOF 或者已经读取了 length - 1 字节后停止（看先碰到那一种情况）。
        // 如果没有指定 length，则默认为 1K，或者说 1024 字节。
        while ($str = trim(fgets($this->socket, 1024))) {
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
     * @param null $size
     * @param null $flag
     * @return bool|string
     */
    public function receive($size = null, $flag = null)
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

        return $final ? $payload : $payload . $this->receive();
    }
    /**
     * @param $payload
     * @param string $type
     * @param bool $masked
     * @return bool|string
     */
    private function hybi10Encode($payload, $type = 'text', $masked = true)
    {
        $frameHead = array();
        $payloadLength = strlen($payload);

        switch ($type) {
            //文本内容
            case 'text':
                // first byte indicates FIN, Text-Frame (10000001):
                $frameHead[0] = 129;
                break;
            //二进制内容
            case 'binary':
            case 'bin':
                // first byte indicates FIN, Text-Frame (10000010):
                $frameHead[0] = 130;
                break;
            case 'close':
                // first byte indicates FIN, Close Frame(10001000):
                $frameHead[0] = 136;
                break;
            case 'ping':
                // first byte indicates FIN, Ping frame (10001001):
                $frameHead[0] = 137;
                break;
            case 'pong':
                // first byte indicates FIN, Pong frame (10001010):
                $frameHead[0] = 138;
                break;
        }

        // set mask and payload length (using 1, 3 or 9 bytes)
        if ($payloadLength > 65535) {
            $payloadLengthBin = str_split(sprintf('%064b', $payloadLength), 8);
            $frameHead[1] = ($masked === true) ? 255 : 127;

            for ($i = 0; $i < 8; $i++) {
                $frameHead[$i + 2] = bindec($payloadLengthBin[$i]);
            }

            // most significant bit MUST be 0 (close connection if frame too big)
            if ($frameHead[2] > 127) {
                $this->close(); // todo ...
                return false;
            }
        } elseif ($payloadLength > 125) {
            $payloadLengthBin = str_split(sprintf('%016b', $payloadLength), 8);
            $frameHead[1] = ($masked === true) ? 254 : 126;
            $frameHead[2] = bindec($payloadLengthBin[0]);
            $frameHead[3] = bindec($payloadLengthBin[1]);
        } else {
            $frameHead[1] = ($masked === true) ? $payloadLength + 128 : $payloadLength;
        }

        // convert frame-head to string:
        foreach (array_keys($frameHead) as $i) {
            $frameHead[$i] = chr($frameHead[$i]);
        }

        // generate a random mask:
        $mask = array();
        if ($masked === true) {
            for ($i = 0; $i < 4; $i++) {
                $mask[$i] = chr(rand(0, 255));
            }

            $frameHead = array_merge($frameHead, $mask);
        }

        $frame = implode('', $frameHead);

        // append payload to frame:
        for ($i = 0; $i < $payloadLength; $i++) {
            $frame .= $masked ? $payload[$i] ^ $mask[$i % 4] : $payload[$i];
        }

        return $frame;
    }
    /**
     * @param $data
     * @return string
     * @throws \InvalidArgumentException
     */
    private function hybi10Decode($data)
    {
        if (!$data) {
            throw new \InvalidArgumentException("data is empty");
        }

        $bytes = $data;
        $secondByte = sprintf('%08b', ord($bytes[1]));
        $masked = ($secondByte[0] == '1') ? true : false;
        $dataLength = ($masked === true) ? ord($bytes[1]) & 127 : ord($bytes[1]);

        //服务器不会设置mask
        if ($dataLength === 126) {
            $decodedData = substr($bytes, 4);
        } elseif ($dataLength === 127) {
            $decodedData = substr($bytes, 10);
        } else {
            $decodedData = substr($bytes, 2);
        }

        $this->log("len=".$dataLength);

        return $decodedData;
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
}
