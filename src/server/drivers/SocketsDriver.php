<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-04-01
 * Time: 12:03
 */

namespace inhere\webSocket\server\drivers;

/**
 * Class SocketsDriver
 * power by `sockets` extension
 * @package inhere\webSocket\server\drivers
 */
class SocketsDriver implements IServerDriver
{
    /**
     * @return bool
     */
    public static function isSupported()
    {
        return extension_loaded('sockets');
    }
}