<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017/5/14
 * Time: 上午12:16
 */

namespace inhere\webSocket\traits;

/**
 * Class SocketsTrait
 * @package inhere\webSocket\traits
 */
trait SocketsTrait
{
    /**
     * 设置超时
     * @param $socket
     * @param float $timeout
     */
    public function setTimeout($socket, $timeout = 2.2)
    {
        if (strpos($timeout, '.')) {
            list($s, $us) = explode('.', $timeout);
            $s = $s < 1 ? 3 : (int)$s;
            $us = (int)($us * 1000 * 1000);
        } else {
            $s = (int)$timeout;
            $us = null;
        }

        $timeoutAry = [
            'sec' => $s,
            'usec' => $us
        ];

        $this->setSocketOption($socket, SO_RCVTIMEO, $timeoutAry);
        $this->setSocketOption($socket, SO_SNDTIMEO, $timeoutAry);
    }

    /**
     * 设置buffer区
     * @param resource $socket
     * @param int $writeBufferSize
     * @param int $readBufferSize
     */
    public function setBufferSize($socket, int $writeBufferSize, int $readBufferSize)
    {
        if ($writeBufferSize > 0) {
            $this->setSocketOption($socket, SO_SNDBUF, $writeBufferSize);
        }

        if ($readBufferSize > 0) {
            $this->setSocketOption($socket, SO_RCVBUF, $readBufferSize);
        }
    }


    /**
     * 设置socket参数
     * @param resource $socket
     * @param string $opt
     * @param string $val
     */
    public function setSocketOption($socket, string $opt, $val)
    {
        socket_set_option($socket, SOL_SOCKET, $opt, $val);
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


    /**
     * 用于获取客户端socket的本地host:port，必须在连接之后才可以使用
     * @param $socket
     * @return array
     */
    public function getSockName($socket)
    {
        socket_getsockname($socket, $host, $port);

        return [
            'host' => $host,
            'port' => $port,
        ];
    }

    /**
     * 获取对端(远端)socket的IP地址和端口
     * @param $socket
     * @return array
     */
    public function getPeerName($socket)
    {
        socket_getpeername($socket, $host, $port);

        return [
            'host' => $host,
            'port' => $port,
        ];
    }

    /**
     * fetch socket Error
     */
    protected function fetchError()
    {
        $this->errNo = socket_last_error($this->socket);
        $this->errMsg = socket_strerror($this->errNo);

        // clear error
        socket_clear_error($this->socket);
    }

}