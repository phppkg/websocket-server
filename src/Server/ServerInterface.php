<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-04-01
 * Time: 12:41
 */

namespace Inhere\WebSocket\Server;

use inhere\console\io\Input;
use inhere\console\io\Output;

/**
 * Interface ServerInterface
 * @package Inhere\WebSocket\Server
 */
interface ServerInterface
{
    const WS_KEY_PATTEN  = '#^[+/0-9A-Za-z]{21}[AQgw]==$#';

    /**
     * some MIN values
     */
    const MIN_LIFETIME = 1800;
    const MIN_REQUEST = 1000;
    const MIN_TIMEOUT = 1;
    const MIN_WATCH_INTERVAL = 120;

    /**
     * some default values
     */
    const WORKER_NUM   = 1;
    const MAX_CONNECT = 200;
    const MAX_LIFETIME = 3600;
    const MAX_REQUEST  = 2000;
    const RESTART_SPLAY = 600;
    const WATCH_INTERVAL = 300;
    const MAX_DATA_LEN = 2048;
    const SLEEP_TIME = 100; // 100 ms
    const TIMEOUT = 3.2;

    /**
     * process exit status code.
     */
    const CODE_MANUAL_KILLED = -500;
    const CODE_NORMAL_EXITED = 0;
    const CODE_CONNECT_ERROR = 170;
    const CODE_FORK_FAILED   = 171;
    const CODE_UNKNOWN_ERROR = 180;

    /**
     * @return bool
     */
    public static function isSupported(): bool;

    public function reset();

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
    public function send(string $data, int $sender = 0, $receiver = null, array $expected = []): int;

    /**
     * get all client number
     * @return int
     */
    public function count(): int;

    public function setCliOut(Output $output);

    public function setCliIn(Input $input);

    public function getName(): string ;
}
