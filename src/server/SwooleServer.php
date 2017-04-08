<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-04-01
 * Time: 12:47
 */

namespace inhere\webSocket\server;

use inhere\webSocket\server\ServerAbstracter;

/**
 * Class Server
 * @package inhere\webSocket\server
 */
class SwooleServer extends ServerAbstracter
{
    /**
     * @return bool
     */
    public static function isSupported()
    {
        return extension_loaded('swoole');
    }
}
