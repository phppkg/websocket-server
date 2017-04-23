#!/usr/bin/env php
<?php

// 同步阻塞客户端
// php-fpm/apache环境下只能使用同步客户端
// apache环境下仅支持prefork多进程模式，不支持prework多线程

$client = new swoole_client(SWOOLE_SOCK_TCP);

if (!$client->connect('127.0.0.1', 9501, -1))
{
    exit("connect failed. Error: {$client->errCode}\n");
}

$client->send("hello world\n");

echo $client->recv();

$client->close();
