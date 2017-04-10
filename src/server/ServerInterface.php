<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-04-01
 * Time: 12:41
 */

namespace inhere\webSocket\server;


/**
 * Interface ServerInterface
 * @package inhere\webSocket\server
 */
interface ServerInterface
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
     * send message
     * @param string $data
     * @param int $sender
     * @param int|array|null $receiver
     * @param int[] $expected
     * @return int
     */
    public function send(string $data, int $sender = 0, $receiver = null, array $expected = []);

    /**
     * get all client number
     * @return int
     */
    public function count();
}
