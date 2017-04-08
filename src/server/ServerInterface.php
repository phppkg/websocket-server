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
}
