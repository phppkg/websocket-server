<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-04-01
 * Time: 12:41
 */

namespace inhere\webSocket\client;

/**
 * Interface ClientInterface
 * @package inhere\webSocket\client
 */
interface ClientInterface
{
    /**
     * @return bool
     */
    public static function isSupported();

    public function start();

    /**
     * @param string $event
     * @param callable $cb
     * @param bool $replace
     * @return mixed
     */
    public function on(string $event, callable $cb, bool $replace = false);

    /**
     * @param float $timeout
     * @param int $flag
     * @return void
     */
    public function connect($timeout = 0.1, $flag = 0);

    /**
     * @return bool
     */
    public function isConnected();

    /**
     * @return resource
     */
    public function getSocket();

    /**
     * 用于获取客户端socket的本地host:port，必须在连接之后才可以使用
     * @return array
     */
    public function getSockName();

    /**
     * 获取对端(远端)socket的IP地址和端口
     * 函数必须在$client->receive() 之后调用
     * @return mixed
     */
    public function getPeerName();

    /**
     * 获取服务器端证书信息
     * @return mixed
     */
//    public function getPeerCert();

    /**
     * @param string $data
     * @param null|int $flag
     * @return mixed
     */
    public function send($data, $flag = null);

    /*
     * @param string $ip
     * @param int $port
     * @param string $data
     * @return mixed
     */
    //public function sendTo(string $ip, int $port, string $data);

    public function sendFile(string $filename);

    public function receive($size = 65535, $flag = null);

    public function close(bool $force = false);

//    public function sleep();
//
//    public function wakeUp();

//    public function enableSSL();
}
