<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-04-01
 * Time: 15:55
 */

namespace inhere\webSocket\client\drivers;

use inhere\library\traits\TraitSimpleFixedEvent;
use inhere\library\traits\TraitUseSimpleOption;

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
     * @var string
     */
    protected $host;

    /**
     * @var int
     */
    protected $port;

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
        'debug' => false,

        'open_log' => true,
        'log_file' => '',

        'timeout' => 3,
        'protocol' => 'ws', // wss

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
     * @param string $host
     * @param int $port
     * @param array $options
     */
    public function __construct(string $host = '127.0.0.1', int $port = 8080, array $options = [])
    {
        $this->host = $host;
        $this->port = $port;

        $this->setOptions($options, true);

        $this->init();
    }

    protected function init()
    {
        // ...
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