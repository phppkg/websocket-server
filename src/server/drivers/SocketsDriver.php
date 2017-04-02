<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-04-01
 * Time: 12:03
 */

namespace inhere\webSocket\server\drivers;

use inhere\webSocket\server\AServerDriver;

/**
 * Class SocketsDriver
 * power by `sockets` extension
 * @package inhere\webSocket\server\drivers
 */
class SocketsDriver extends AServerDriver
{
    /**
     * @return bool
     */
    public static function isSupported()
    {
        return extension_loaded('sockets');
    }

    /**
     * response data to client by socket connection
     * @param resource  $socket
     * @param string    $data
     * @param int       $length
     * @return int      Return socket last error number code. gt 0 on failure, eq 0 on success
     */
    public function writeTo($socket, string $data, int $length = 0)
    {
        // response data to client
        socket_write($socket, $data, $length > 0 ? $length : strlen($data));

        return $this->getLastErrorNo($socket);
    }

    /**
     * @param null|resource $socket
     * @return bool
     */
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
