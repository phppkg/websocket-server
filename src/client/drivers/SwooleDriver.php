<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-04-01
 * Time: 12:46
 */

namespace inhere\webSocket\client\drivers;

use inhere\exceptions\UnknownCalledException;
use Swoole\Client;

/**
 * Class SwooleDriver
 * @package inhere\webSocket\client\drivers
 */
class SwooleDriver extends AClientDriver
{
    /**
     * @var Client
     */
    private $client;

    /**
     * @return bool
     */
    public static function isSupported()
    {
        return extension_loaded('swoole');
    }

    public function init()
    {
        $this->client = new Client(SWOOLE_SOCK_TCP);
    }

    public function connect($host, $port, $timeout = 0.1, $flag = 0)
    {
        if ( !$this->client->connect($host, $port, $timeout) ) {
            exit("connect failed. Error: {$this->client->errCode}\n");
        }
    }

    public function send($message, $flag = null)
    {
        $this->client->send($message, $flag);
    }

    public function receive($size = null, $flag = null)
    {
        $this->client->recv($size, $flag);
    }

    public function close(bool $force = false)
    {
        $this->client->close();
    }

    public function __call($method, array $args = [])
    {
        if (method_exists($this->client, $method)) {
            return call_user_func_array([$this->client, $method], $args);
        }

        throw new UnknownCalledException("Call the method [$method] not exists!");
    }
}
