<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-04-01
 * Time: 12:05
 */

namespace inhere\webSocket\server\drivers;

/**
 * Class StreamDriver
 * @package inhere\webSocket\server\drivers
 */
class StreamDriver implements IServerDriver
{
    /**
     * @inheritdoc
     */
    public static function isSupported()
    {
        return function_exists('stream_socket_accept');
    }
}