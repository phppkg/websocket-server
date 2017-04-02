<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-04-01
 * Time: 11:13
 */

namespace inhere\webSocket\client\drivers;

/**
 * Class SocketDriver
 * @package inhere\webSocket\client\drivers
 */
class SocketsDriver extends AClientDriver
{
    /**
     * @var resource
     */
    protected $socket;

    /**
     * @return bool
     */
    public static function isSupported()
    {
        return extension_loaded('sockets');
    }

    public function init()
    {
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        if ( !is_resource($this->socket) ) {
            $this->print('[ERROR] Unable to create socket: '. $this->getLastError(), true, socket_last_error());
        }

        parent::init();
    }

    /**
     * @inheritdoc
     */
    public function connect($timeout = 1, $flag = 0)
    {
        if (!socket_set_nonblock($this->socket)) {
            $this->print('[ERROR] Unable to set non-block on socket: '. $this->getLastError($this->socket), true, socket_last_error());
        }

        $host = $this->getHost();
        $port = $this->getPort();
        $timeout = $timeout ?: $this->getOption('timeout');
        $time = time();

        while (!socket_connect($this->socket, $host, $port)) {
            $errNo = socket_last_error($this->socket);

            if ($errNo === SOCKET_EINPROGRESS || $errNo === SOCKET_EALREADY) {
                if ((time() - $time) >= $timeout) {
                    socket_close($this->socket);
                    $this->log('Connection timed out.', 'warning');
                }

                sleep(1);
                continue;
            }

            $this->print('[ERROR] Unable to set block on socket: '. socket_strerror($errNo) , true, $errNo);
        }

        if ( !socket_set_block($this->socket) ) {
            $this->print('[ERROR] Unable to set block on socket: '. $this->getLastError($this->socket), true, socket_last_error());
        }
    }

    /**
     * @param bool $force
     */
    public function close(bool $force = false)
    {
        if ( $this->socket ) {
            socket_close($this->socket);

            $this->socket = null;
        }
    }

    /**
     * 用于获取客户端socket的本地host:port，必须在连接之后才可以使用
     * @return array
     */
    public function getSockName()
    {
        socket_getsockname($this->socket, $host, $port);

        return [
            'host' => $host,
            'port' => $port,
        ];
    }

    /**
     * 获取对端(远端)socket的IP地址和端口
     * @return array
     */
    public function getPeerName()
    {
        socket_getpeername($this->socket, $host, $port);

        return [
            'host' => $host,
            'port' => $port,
        ];
    }

    public function getLastErrorNo($socket = null)
    {
        return socket_last_error($socket);
    }

    /**
     * @param null|resource $socket
     * @return string
     */
    public function getLastError($socket = null)
    {
        return socket_strerror(socket_last_error($socket));
    }

}
