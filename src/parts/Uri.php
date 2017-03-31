<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-03-31
 * Time: 14:31
 */

namespace inhere\webSocket\parts;

/**
 * Class Uri
 * @package inhere\webSocket\parts
 */
class Uri extends \inhere\webSocket\http\Uri
{
    /**
     * @var array
     */
    protected static $validScheme = [
        '' => true,
        'https' => true,
        'http' => true,
        'ws' => true,
        'wss' => true,
    ];
}