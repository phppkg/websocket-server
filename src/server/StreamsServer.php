<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-04-01
 * Time: 12:05
 */

namespace inhere\webSocket\server;

use inhere\exceptions\ConnectException;
use inhere\webSocket\server\ServerAbstracter;

/**
 * Class StreamsServer
 * @package inhere\webSocket\server
 */
class StreamsServer extends ServerAbstracter
{
    /**
     * @inheritdoc
     */
    public static function isSupported()
    {
        return function_exists('stream_socket_accept');
    }

    public function doStart()
    {
        $this->master = stream_socket_server(
            $this->getUri(),
            $errNo,
            $errStr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $this->getStreamContext()
        );

        if (!$this->master) {
            throw new ConnectException(sprintf(
                'Could not listen on socket: %s (%d)',
                $errStr,
                $errNo
            ));
        }

        $this->listening = true;
    }

    /**
     * create and prepare socket resource
     */
    protected function prepareWork()
    {
        // TODO: Implement prepareWork() method.
    }

    /**
     * Closing a connection
     * @param int $cid
     * @param null|resource $socket
     * @param bool $triggerEvent
     * @return bool
     */
    public function close(int $cid, $socket = null, bool $triggerEvent = true)
    {
        // TODO: Implement close() method.
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
        // TODO: Implement writeTo() method.
    }

    /**
     * @param null|resource $socket
     * @return bool
     */
    public function getErrorNo($socket = null)
    {
        // TODO: Implement getErrorNo() method.
    }

    /**
     * @param null|resource $socket
     * @return string
     */
    public function getErrorMsg($socket = null)
    {
        // TODO: Implement getErrorMsg() method.
    }
}
