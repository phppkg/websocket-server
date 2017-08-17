<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-08-17
 * Time: 18:23
 */

use inhere\library\di\ContainerManager;
use inhere\library\log\FileLogger;
use inhere\library\log\ProcessLogger;

$di = ContainerManager::make();

$di->set('proLogger', [
    'target' => ProcessLogger::class,
    [
        'toConsole' => false,
    ]
]);

$di->set('logger', [
    'target' => FileLogger::class . '::make',
    [
        'name' => 'test',
        'logConsole' => false,
        'logThreshold' => 10,
    ]
]);

//var_dump($di['logger']);

return $di;