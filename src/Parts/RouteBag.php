<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-08-17
 * Time: 9:29
 */

namespace Inhere\WebSocket\Parts;

use Inhere\WebSocket\Module\ModuleInterface;

/**
 * Class RouteBag
 * @package Inhere\WebSocket\Parts
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
