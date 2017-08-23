<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-03-27
 * Time: 9:14
 */

namespace inhere\webSocket\helpers\exceptions;

use inhere\webSocket\protocols\Protocol;

/**
 * Class BadRequestException
 * @package inhere\webSocket\client
 */
class BadRequestException extends \Exception
{
    /**
     * @param string    $message
     * @param int       $code
     * @param \Exception $previous
     */
    public function __construct($message = null, $code = null, $previous = null)
    {
        if ($code === null) {
            $code = Protocol::HTTP_BAD_REQUEST;
        }
        parent::__construct($message, $code, $previous);
    }
}
