<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-04-01
 * Time: 11:13
 */

namespace inhere\webSocket\client;

use inhere\library\helpers\PhpHelper;
use inhere\webSocket\traits\SocketsTrait;
use inhere\webSocket\WSHelper;

/**
 * Class SocketsClient
 * power by `sockets` extension
 * @package inhere\webSocket\client
 */
class SocketsClient extends ClientAbstracter
{
    use SocketsTrait;

    /**
     * @var string
     */
    protected $driver = 'sockets';

    /**
     * @var resource
     */
    protected $socket;

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
    public static function isSupported()
    {
        return extension_loaded('sockets');
    }

    /**
     * @inheritdoc
     */
    protected function doConnect($timeout = 2.1, $flag = 0)
    {
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        if (!is_resource($this->socket)) {
            $this->fetchError();
            $this->cliOut->error('Unable to create socket: ' . $this->errMsg, $this->errNo);
        }

        $timeout = $timeout ?: $this->get('timeout', self::TIMEOUT);

        // 设置connect超时
        $this->setTimeout($this->socket, $timeout);
        $this->setSocketOption($this->socket, SO_REUSEADDR, 1);
        // 设置缓冲区大小
        $this->setBufferSize(
            $this->socket,
            (int)$this->get('write_buffer_size'),
            (int)$this->get('read_buffer_size')
        );

        if (!PhpHelper::isWin() && !socket_set_nonblock($this->socket)) {
            $this->fetchError();
            $this->cliOut->error('Unable to set non-block on socket: ' . $this->errMsg, $this->errNo);
        }

        $host = $this->getHost();
        $port = $this->getPort();
        $time = time();

        while (!socket_connect($this->socket, $host, $port)) {
            $this->fetchError();
            $errNo = $this->errNo;

            if ($errNo === SOCKET_EINPROGRESS || $errNo === SOCKET_EALREADY) {
                if ((time() - $time) >= $timeout) {
                    $this->log('Connection timed out.', 'warning');
                    $this->close();
                }

                sleep(1);
                continue;
            }

            $this->cliOut->error('Unable to set block on socket: ' . $this->errMsg, $errNo);
        }

        if (!PhpHelper::isWin() && !socket_set_block($this->socket)) {
            $this->fetchError();
            $this->cliOut->error('Unable to set block on socket: ' . $this->errMsg, $this->errNo);
        }
    }

    /**
     * @param int $length
     * @return string
     */
    public function readResponseHeader($length = 2048)
    {
        $headerBuffer = '';

        while (true) {
            socket_recv($this->socket, $_tmp, $length, null);

            if (!$_tmp) {
                return '';
            }

            $headerBuffer .= $_tmp;

            if (strpos($headerBuffer, self::HEADER_END) !== false) {
                break;
            }
        }

        return $headerBuffer;
    }

    /**
     * 发送数据
     * @param string $data
     * @return int
     */
    public function write($data)
    {
        // socket_write($this->socket, $data, strlen($data));
        $length = strlen($data);
        $written = 0;
        $timeoutSend = $this->get('timeout_send', 0.3);
        $lastTime = $timeoutSend + microtime(true);

        // 总超时，for循环中计时
        while ($written < $length) {
            $n = socket_write($this->socket, substr($data, $written), $length - $written);

            //超过总时间
            if (microtime(true) > $lastTime) {
                return false;
            }

            if ($n === false) {
                $this->fetchError();
                $errNo = $this->errNo;

                //判断错误信息，EAGAIN EINTR，重写一次
                if ($errNo === SOCKET_EAGAIN || $errNo === SOCKET_EINTR) {
                    continue;
                }

                return false;
            }

            $written += $n;
        }

        return $written;
    }

    /**
     * 发送数据
     * @param string $data
     * @param null $flags allow: 1 MSG_OOB 128 MSG_EOR 512 MSG_EOF 4 MSG_DONTROUTE
     * @return bool|int
     */
    public function rawSend($data, $flags = null)
    {
        $length = strlen($data);
        $written = 0;
        $timeoutSend = $this->get('timeout_send', 0.3);
        $lastTime = $timeoutSend + microtime(true);

        // 总超时，for循环中计时
        while ($written < $length) {
            $n = socket_send($this->socket, substr($data, $written), $length - $written, $flags);

            //超过总时间
            if (microtime(true) > $lastTime) {
                return false;
            }

            if ($n === false) {
                $this->fetchError();
                $errNo = $this->errNo;

                //判断错误信息，EAGAIN EINTR，重写一次
                if ($errNo === SOCKET_EAGAIN || $errNo === SOCKET_EINTR) {
                    continue;
                }

                return false;
            }

            $written += $n;
        }

        return $written;
    }

//    public function sendTo($socket, $data)
//    {
    // socket_sendto 针对udp套接字发送数据
//        socket_sendto($socket, $data);
//    }

    /**
     * 接收数据
     * @param int $length 接收数据的长度
     * @param bool $waitAll 等待接收到全部数据后再返回，注意这里超过包长度会阻塞住
     * @return string | bool
     */
    public function receive($length = 65535, $waitAll = null)
    {
        // 1 MSG_OOB 2 MSG_PEEK 256 MSG_WAITALL 64 MSG_DONTWAIT
        $flags = $waitAll ? MSG_WAITALL : 0;
        $bytes = socket_recv($this->socket, $data, $length, $flags);

        if ($bytes === false) {
            $this->fetchError();

            // 重试一次，这里为防止意外，不使用递归循环
            if ($this->errNo === MSG_DONTROUTE) {
                socket_recv($this->socket, $data, $length, $flags);
            } else {
                return '';
            }
        }

        return WSHelper::hybi10Decode($data);
    }

    /**
     * @param bool $force
     */
    public function close(bool $force = false)
    {
        if ($this->socket) {
            socket_close($this->socket);

            $this->socket = null;
        }
    }

    /**
     * @return int
     */
    public function getErrorNo()
    {
        return $this->errNo;
    }

    /**
     * @return string
     */
    public function getErrorMsg()
    {
        return $this->errMsg;
    }

    /**
     * 设置socket参数
     * @param resource $socket
     * @param string $opt
     * @param string $set
     */
    public function setSocketOption($socket, string $opt, $set)
    {
        socket_set_option($socket, SOL_SOCKET, $opt, $set);
    }

    /**
     * 获取socket参数
     * @param resource $socket
     * @param string $opt
     * @return mixed
     */
    public function getSocketOption($socket, string $opt)
    {
        return socket_get_option($socket, SOL_SOCKET, $opt);
    }
}
