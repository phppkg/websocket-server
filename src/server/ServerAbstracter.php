<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-03-30
 * Time: 13:01
 */

namespace inhere\webSocket\server;

use inhere\webSocket\BaseAbstracter;

/**
 * Class AServerDriver
 * @package inhere\webSocket\server
 */
abstract class ServerAbstracter extends BaseAbstracter implements ServerInterface
{
    /**
     * Websocket blob type.
     */
    const BINARY_TYPE_BLOB = "\x81";

    /**
     * Websocket array buffer type.
     */
    const BINARY_TYPE_ARRAY_BUFFER = "\x82";

    const SIGN_KEY = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

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
     * the master socket
     * @var resource
     */
    private $master;

    /**
     * 连接的客户端列表
     * @var resource[]
     * [
     *  id => socket,
     * ]
     */
    private $sockets = [];

    /**
     * 连接的客户端信息列表
     * @var array
     * [
     *  cid => [ ip=> string , port => int, handshake => bool ], // bool: handshake status.
     * ]
     */
    private $clients = [];

    /**
     * default client info data
     * @var array
     */
    protected $defaultInfo = [
        'ip' => '',
        'port' => 0,
        'handshake' => false,
        'path' => '/',
    ];

    /**
     * @var string
     */
    protected $host;

    /**
     * @var int
     */
    protected $port;

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
     * @param string $host
     * @param int $port
     * @param array $options
     */
    public function __construct(string $host = '0.0.0.0', int $port = 8080, array $options = [])
    {
        $this->host = $host;
        $this->port = $port;

        $this->setOptions($options, true);
    }

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

        if ( !($socket = $this->getSocket($receiver)) ) {
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

            foreach ($this->sockets as $socket) {
                $this->writeTo($socket, $res, $len);
            }

            // to receivers
        } elseif ($receivers) {
            $this->log("(broadcast)The #{$fromUser} gave some specified user sending a message. Data: {$data}");
            foreach ($receivers as $receiver) {
                if ( $socket = $this->getSocket($receiver) ) {
                    $this->writeTo($socket, $res, $len);
                }
            }

            // to all
        } else {
            $this->log("(broadcast)The #{$fromUser} send the message to everyone except some people. Data: {$data}");
            foreach ($this->sockets as $cid => $socket) {
                if ( isset($expected[$cid]) ) {
                    continue;
                }

                if ( $receivers && !isset($receivers[$cid]) ) {
                    continue;
                }

                $this->writeTo($socket, $res, $len);
            }
        }

        return $this->getLastErrorNo();
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
     * @return bool
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
    /// helper method
    /////////////////////////////////////////////////////////////////////////////////////////

    /**
     *  check it is a accepted client
     * @notice maybe don't complete handshake
     * @param $cid
     * @return bool
     */
    public function hasClient(int $cid)
    {
        return isset($this->clients[$cid]);
    }

    /**
     * get client info data
     * @param int $cid
     * @return mixed
     */
    public function getClient(int $cid)
    {
        return $this->clients[$cid] ?? $this->defaultInfo;
    }

    /**
     * @return array
     */
    public function getClients(): array
    {
        return $this->clients;
    }

    /**
     * @return int
     */
    public function countClient(): int
    {
        return $this->count();
    }
    public function count(): int
    {
        return count($this->clients);
    }

    /**
     * check it a accepted client and handshake completed  client
     * @param int $cid
     * @return bool
     */
    public function hasHandshake(int $cid): bool
    {
        if ( $this->hasClient($cid) ) {
            return $this->getClient($cid)['handshake'];
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

        foreach ($this->clients as $info) {
            if ($info['handshake']) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * check it is a accepted client
     * @notice maybe don't complete handshake
     * @param  resource $socket
     * @return bool
     */
    public function isClientSocket($socket)
    {
        return in_array($socket, $this->sockets, true);
    }

    /**
     * get client socket connection by index
     * @param $cid
     * @return resource|false
     */
    public function getSocket($cid)
    {
        if ( $this->hasClient($cid) ) {
            return $this->sockets[$cid];
        }

        return false;
    }

    /**
     * @return array
     */
    public function getSockets(): array
    {
        return $this->sockets;
    }

    /**
     * @return resource
     */
    public function getMaster(): resource
    {
        return $this->master;
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
}
