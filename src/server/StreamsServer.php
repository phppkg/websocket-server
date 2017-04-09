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
}
