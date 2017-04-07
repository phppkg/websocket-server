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

    public function connect($timeout = 0.1, $flag = 0)
    {
        $type = SWOOLE_SOCK_TCP;

        if ( $this->getOption('ssl') ) {
            $type |= SWOOLE_SSL;
        }

        $this->client = new Client($type);

        if ($keyFile = $this->getOption('ssl_key_file')) {
            $this->client->set([
                'ssl_key_file' => $keyFile,
                'ssl_cert_file' => $this->getOption('ssl_cert_file')
            ]);
        }

        if ( !$this->client->connect($this->getHost(), $this->getPort(), $timeout) ) {
            exit("connect failed. Error: {$this->client->errCode}\n");
        }

        $headerBuffer = '';
        while(true) {
            $_tmp = $this->client->recv();
            if ($_tmp) {
                $headerBuffer .= $_tmp;

                if (substr($headerBuffer, -4, 4) !== self::HEADER_END) {
                    continue;
                }
            } else {
                return false;
            }

            return $this->doHandShake($headerBuffer);
        }

        return false;
    }

    public function readResponseHeader($length = 2048)
    {
        $headerBuffer = '';

        while(true) {
            $_tmp = $this->client->recv();
            if ($_tmp) {
                $headerBuffer .= $_tmp;

                if (substr($headerBuffer, -4, 4) !== self::HEADER_END) {
                    break;
                }
            } else {
                return '';
            }
        }

        return $headerBuffer;
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
