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
use inhere\sroute\ORouter;

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

$di->set('router', ORouter::make([
    'ignoreLastSep' => true,
    'tmpCacheNumber' => 100,
]));

//$di->set('request', new inhere\webSocket\http\Request());
//$di->set('response', new inhere\webSocket\http\Response());

//var_dump($di['logger']);

return $di;