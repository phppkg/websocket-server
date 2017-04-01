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

    public function connect($host, $port, $timeout = 0.1, $flag = 0)
    {
        $host = "127.0.0.1";
        $port = "80";
        $timeout = 15;  //timeout in seconds

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP) or die("Unable to create socket\n");

        socket_set_nonblock($socket) or die("Unable to set nonblock on socket\n");

        $time = time();
        while ( !socket_connect($socket, $host, $port) ) {
            $err = socket_last_error($socket);

            if ($err === SOCKET_EINPROGRESS || $err === SOCKET_EALREADY) {
                if ((time() - $time) >= $timeout) {
                    socket_close($socket);
                    die("Connection timed out.\n");
                }

                sleep(1);
                continue;
            }

            die(socket_strerror($err) . "\n");
        }

        socket_set_block($socket) or die("Unable to set block on socket\n");
    }
}