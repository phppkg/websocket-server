<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-04-01
 * Time: 12:47
 */

namespace inhere\webSocket\server\drivers;

/**
 * Class SwooleDriver
 * @package inhere\webSocket\server\drivers
 */
class SwooleDriver implements IServerDriver
{
    /**
     * @return bool
     */
    public static function isSupported()
    {
        return extension_loaded('swoole');
    }
}