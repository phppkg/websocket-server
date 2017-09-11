<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-08-22
 * Time: 17:03
 */

/** @var \Inhere\Route\ORouter $router */
$router = $di->get('router');

$router->get('/', function () {
    echo "<h2>hello</h2>\n";
});

$router->get('/chat', function () {
    include __DIR__ . '/chatRoom/chat-room.html';
});
