<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-03-30
 * Time: 13:01
 */

namespace inhere\webSocket;

use inhere\library\traits\TraitSimpleFixedEvent;
use inhere\library\traits\TraitUseSimpleOption;

/**
 * Class BaseWebSocket
 * @package inhere\webSocket
 */
abstract class BaseWebSocket
{
    use TraitUseSimpleOption;
    use TraitSimpleFixedEvent;

    /**
     * version
     */
    const VERSION = '0.5.1';

    /**
     * Websocket blob type.
     */
    const BINARY_TYPE_BLOB = "\x81";

    /**
     * Websocket array buffer type.
     */
    const BINARY_TYPE_ARRAY_BUFFER = "\x82";


    /**
     * Websocket version
     */
    const WS_VERSION = '13';

    const PROTOCOL_WS = 'ws';
    const PROTOCOL_WSS = 'wss';

    const SIGN_KEY = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    // abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!"§$%&/()=[]{}
    const TOKEN_CHARS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!"$&/()=[]{}0123456789';


    const DEFAULT_HOST = '0.0.0.0';
    const DEFAULT_PORT = 8080;

    // 事件的回调函数名
    const ON_CONNECT   = 'connect';
    const ON_HANDSHAKE = 'handshake';
    const ON_OPEN      = 'open';
    const ON_MESSAGE   = 'message';
    const ON_CLOSE     = 'close';
    const ON_ERROR     = 'error';

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
     * @var array
     */
    protected $options = [
        'debug'    => false,

        'open_log' => true,
        'log_file' => '',
    ];

    /**
     * WebSocket constructor.
     * @param array $options
     */
    public function __construct(array $options = [])
    {
        $this->setOptions($options, true);
    }

    /////////////////////////////////////////////////////////////////////////////////////////
    /// helper method
    /////////////////////////////////////////////////////////////////////////////////////////

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
     * @param $s
     * @return string
     */
    public function frame($s)
    {
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
                $mask .= chr(rand(0, 255));
            }

            $frame .= $mask;
        }

        for ($i = 0; $i < $length; ++$i) {
            $frame .= ($masked === true) ? $data[$i] ^ $mask[$i % 4] : $data[$i];
        }

        return $frame;
    }

    /**
     * @return array
     */
    public static function getOpCodes(): array
    {
        return self::$opCodes;
    }

    /**
     * Generate WebSocket sign.(for server)
     * @param string $key
     * @return string
     */
    public function genSign(string $key): string
    {
        return base64_encode(sha1(trim($key) . self::SIGN_KEY, true));
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
            $key .= $chars[mt_rand(0, $chars_length - 1)];
        }

        return base64_encode($key);
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
