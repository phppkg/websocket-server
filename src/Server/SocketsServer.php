<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-04-01
 * Time: 12:03
 */

namespace Inhere\WebSocket\Server;

use Inhere\WebSocket\Traits\ProcessControlTrait;
use Inhere\WebSocket\Traits\SocketsTrait;

/**
 * Class SocketsServer
 * power by `sockets` extension
 * @package Inhere\WebSocket\Server
 */
class SocketsServer extends ServerAbstracter
{
    use ProcessControlTrait;
    use SocketsTrait;

    /**
     * @var string
     */
    protected $driver = 'sockets';

    /**
     * @var int
     */
    private $errNo = 0;

    /**
     * @var string
     */
    private $errMsg = '';

    /**
     * @return bool
     */
    public static function isSupported(): bool
    {
        return \extension_loaded('sockets');
    }

    /**
     * {@inheritDoc}
     */
    protected function init()
    {
        parent::init();

        $this->checkEnvironment();
    }

    /**
     * create and prepare socket resource
     */
    protected function prepare()
    {
        if ($this->getEventCount() < 1) {
            $sup = implode(',', $this->getSupportedEvents());
            $this->cliOut->error('Please register event handle callback before start. supported events: ' . $sup, -500);
        }

        // reset
        socket_clear_error();
        $this->metas = $this->clients = [];

        // 创建一个 TCP socket
        // AF_INET: IPv4 网络协议。TCP 和 UDP 都可使用此协议。
        // AF_UNIX: 使用 Unix 套接字. 例如 /tmp/my.sock
        // more see http://php.net/manual/en/function.socket-create.php
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        if (!\is_resource($this->socket)) {
            $this->fetchError();
            $this->cliOut->error('Unable to create socket: ' . $this->errMsg, $this->errNo);
        }

        // 设置IP和端口重用,在重启服务器后能重新使用此端口;
        $this->setSocketOption($this->socket, SO_REUSEADDR, TRUE);

        // 设置超时
        $this->setTimeout($this->socket, $this->config['timeout']);

        // 设置缓冲区大小
        $this->setBufferSize($this->socket, $this->config['write_buffer_size'], $this->config['read_buffer_size']);

        // 给套接字绑定名字
        socket_bind($this->socket, $this->getHost(), $this->getPort());

        // 监听套接字上的连接. 最多允许 $max 个连接，超过的客户端连接会返回 WSAECONNREFUSED 错误
        socket_listen($this->socket, $this->config['max_connect']);
    }

    /**
     * startDriverWorker
     */
    protected function startDriverWorker()
    {
        // interval time
        $sleepTime = $this->config['sleep_time'] * 1000; // ms -> us
        $maxLen = $this->config['max_data_len'];

        while (!$this->stopWork) {
            $this->dispatchSignals();

            $write = $except = null;
            // copy， 防止 $this->clients 的变动被 socket_select() 接收到
            $read = $this->clients;
            $read[] = $this->socket;

            // 会监控 $read 中的 socket 是否有变动
            // $tv_sec =0 时此函数立即返回，可以用于轮询机制
            // $tv_sec =null 将会阻塞程序执行，直到有新连接时才会继续向下执行
            if (false === socket_select($read, $write, $except, null)) {
                $this->fetchError();
                $this->log('socket_select() failed, reason: ' . $this->errMsg, 'error');
                continue;
            }

            // handle ...
            foreach ($read as $sock) {
                $this->handleSocket($sock, $maxLen);
            }

            //sleep(1);
            usleep($sleepTime);
        }
    }

    /**
     * @param resource $sock
     * @param int $len
     * @return bool
     */
    protected function handleSocket($sock, $len): bool
    {
        // 每次循环检查到 $this->socket 时，都会用 socket_accept() 去检查是否有新的连接进入，有就加入连接列表
        if ($sock === $this->socket) {
            // 从已经监控的socket中接受新的客户端请求
            if (false === ($newSock = socket_accept($sock))) {
                $this->fetchError();
                $this->error($this->errMsg);

                return false;
            }

            $this->connect($newSock);
            return true;
        }

        $cid = (int)$sock;

        // 不在已经记录的client列表中
        if (!isset($this->metas[$cid], $this->clients[$cid])) {
            return $this->close($cid, $sock);
        }

        $data = null;
        // 函数 socket_recv() 从 socket 中接受长度为 len 字节的数据，并保存在 $data 中。
        $bytes = socket_recv($sock, $data, $len, 0);

        // 没有发送数据或者小于7字节
        if (false === $bytes) {
            $this->fetchError();
            // $this->log("Failed to receive data or not received data(client close connection) from #$cid client, will close the socket.");
            $errNo = $this->getErrorNo();

            switch ($errNo) {
                case 102: // ENETRESET    -- Network dropped connection because of reset
                case 103: // ECONNABORTED -- Software caused connection abort
                case 104: // ECONNRESET   -- Connection reset by peer
                case 108: // ESHUTDOWN    -- Cannot send after transport endpoint shutdown -- probably more of an error on our part, if we're trying to write after the socket is closed.  Probably not a critical error, though.
                case 110: // ETIMEDOUT    -- Connection timed out
                case 111: // ECONNREFUSED -- Connection refused -- We shouldn't see this one, since we're listening... Still not a critical error.
                case 112: // EHOSTDOWN    -- Host is down -- Again, we shouldn't see this, and again, not critical because it's just one connection and we still want to listen to/for others.
                case 113: // EHOSTUNREACH -- No route to host
                case 121: // EREMOTEIO    -- Rempte I/O error -- Their hard drive just blew up.
                case 125: // ECANCELED    -- Operation canceled

                    $this->stderr('Unusual disconnect on socket ' . $sock);
                    break;
                default:
                    $this->stderr('Socket error: ' . $this->getErrorMsg());
            }

            return $this->close($cid, $sock);
        }

        if (0 === $bytes || $bytes < 7) {
            $this->close($cid, $sock);
            $this->stderr('Client disconnected. TCP connection lost: ' . $sock);
            return false;
        }

        // 是否已经握手
        if (!$this->metas[$cid]['handshake']) {
            return $this->handshake($sock, $data, $cid);
        }

        $this->message($cid, $data, $bytes, $this->metas[$cid]);

        return true;
    }

    // protected function handshake($socket, string $data, int $cid)
    // protected function message(int $cid, string $data, int $bytes, array $client)
    // public function close(int $cid, $socket = null, bool $triggerEvent = true)

    /**
     * @param int $cid
     * @param resource $socket
     * @return bool
     */
    protected function doClose(int $cid, $socket = null)
    {
        if (!\is_resource($socket) && !($socket = $this->clients[$cid] ?? null)) {
            $this->log("Close the client socket connection failed! #$cid client socket not exists", 'error');
        }

        // close socket connection
        if ($socket && \is_resource($socket)) {
            $result = socket_shutdown($socket);
            socket_close($socket);

            return $result;
        }

        return false;
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
        // response data to client
        socket_write($socket, $data, $length > 0 ? $length : \strlen($data));

        return $this->getErrorNo($socket);
    }

    /**
     * @param null|resource $socket
     * @return bool
     */
    public function getErrorNo($socket = null)
    {
        if ($socket) {
            socket_last_error($socket);
        }

        return $this->errNo;
    }

    /**
     * @param null|resource $socket
     * @return string
     */
    public function getErrorMsg($socket = null)
    {
        if ($socket) {
            return socket_strerror($this->getErrorNo($socket));
        }

        return $this->errMsg;
    }
}
