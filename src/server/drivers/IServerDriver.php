<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-04-01
 * Time: 12:41
 */

namespace inhere\webSocket\server\drivers;

/**
 * Interface IServerDriver
 * @package inhere\webSocket\server\drivers
 */
interface IServerDriver
{
    /**
     * @return bool
     */
    public static function isSupported();
}