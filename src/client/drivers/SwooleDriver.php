<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-04-01
 * Time: 12:46
 */

namespace inhere\webSocket\client\drivers;

use inhere\exceptions\ConnectException;
use inhere\exceptions\UnknownCalledException;
use Swoole\Client;

/**
 * Class SwooleDriver
 * @package inhere\webSocket\client\drivers
 */
class SwooleDriver extends AClientDriver
{
    /**
     * @var string
     */
    protected $name = 'swoole';

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

    /**
     * SwooleDriver constructor.
     * @param string $url
     * @param array $options
     */
    public function __construct(string $url, array $options = [])
    {
        $this->options['client'] = [
            // 结束符检测
            'open_eof_check' => true,
            'package_eof' => self::HEADER_END,
            'package_max_length' => 1024 * 1024 * 2, //协议最大长度

            // 长度检测
//            'open_length_check'     => 1,
//            'package_length_type'   => 'N',
//            'package_length_offset' => 0,       //第N个字节是包长度的值
//            'package_body_offset'   => 4,       //第几个字节开始计算长度

            // Socket缓存区尺寸
            'socket_buffer_size'     => 1024*1024*2, //2M缓存区
        ];

        parent::__construct($url, $options);
    }

    /**
     * @inheritdoc
     */
    protected function doConnect($timeout = 0.1, $flag = 0)
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
            $this->print("[ERROR] connect failed. Error: {$this->client->errCode}", true, -404);
        }
    }

    /**
     * @param int $length
     * @return string
     */
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

    /**
     * @param string $data
     * @return bool|int
     * @throws ConnectException
     */
    protected function write($data)
    {
        $written = $this->client->send($data);

        if ($written < ($dataLen = strlen($data))) {
            throw new ConnectException("Could only write $written out of $dataLen bytes.");
        }

        return $written;
    }

    /**
     * @param string $message
     * @param null $flag
     * @return bool|int
     */
    public function send($message, $flag = null)
    {
        return $this->client->send($message, $flag);
    }

    public function sendFile(string $filename)
    {
        return $this->client->sendfile($filename);
    }

    /**
     * @param null $size
     * @param null $flag
     * @return mixed
     */
    public function receive($size = null, $flag = null)
    {
        return $this->client->recv($size, $flag);
    }

    /**
     * @param bool $force
     */
    public function close(bool $force = false)
    {
        $this->client->close($force);
    }

    /**
     * @return resource
     */
    public function getSocket(): resource
    {
        return $this->client->sock;
    }

    /**
     * @return array
     */
    public function getSockName()
    {
        return $this->client->getsockname();
    }

    /**
     * @return mixed
     */
    public function getPeerName()
    {
        return $this->client->getpeername();
    }

    /**
     * @param $name
     * @param $value
     */
    public function setClientOption($name, $value)
    {
        $this->setClientOptions([$name => $value]);
    }

    /**
     * @param array $options
     */
    public function setClientOptions(array $options)
    {
        $this->client->set($options);
    }

    /**
     * @return int
     */
    public function getErrorNo()
    {
        return $this->client->errCode;
    }

    /**
     * @return string
     */
    public function getErrorMsg()
    {
        return socket_strerror($this->client->errCode);
    }

    public function __call($method, array $args = [])
    {
        if (method_exists($this->client, $method)) {
            return call_user_func_array([$this->client, $method], $args);
        }

        throw new UnknownCalledException("Call the method [$method] not exists!");
    }
}
