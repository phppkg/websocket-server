<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-04-01
 * Time: 12:47
 */

namespace inhere\webSocket\server;
use Swoole\Websocket\Server;

/**
 * Class Server
 * @package inhere\webSocket\server
 */
class SwooleServer extends ServerAbstracter
{
    /**
     * @var string
     */
    protected $name = 'swoole';

    /**
     * @return bool
     */
    public static function isSupported()
    {
        return extension_loaded('swoole');
    }

    /**
     * create and prepare socket resource
     */
    protected function prepareWork()
    {
        // TODO: Implement prepareWork() method.
    }

    /**
     * do start server
     */
    protected function doStart()
    {
        $host = $this->getHost();
        $port = $this->getPort();
        $server = new Server($host, $port, $mode, $socketType);

    }

    protected function connect($socket)
    {
        // TODO: Implement connect() method.
    }

    /**
     * Closing a connection
     * @param resource $socket
     * @return bool
     */
    protected function doClose($socket)
    {
        // TODO: Implement doClose() method.
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
     * @return int
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
