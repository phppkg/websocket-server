<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-08-17
 * Time: 9:29
 */

namespace inhere\webSocket\parts;

use inhere\webSocket\module\ModuleInterface;

/**
 * Class RouteBag
 * @package inhere\webSocket\parts
 */
class RouteBag
{
    /**
     * @var array
     */
    public $data;

    /**
     * @var int
     */
    public $index;

    /**
     * @var ModuleInterface
     */
    public $handler;
}