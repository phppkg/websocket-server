# 一些socket函数知识


## socket 函数

doc: http://php.net/manual/zh/ref.sockets.php

### socket_create

创建一个套接字（通讯节点）socket

``` 
resource socket_create ( int $domain , int $type , int $protocol )
```

创建并返回一个套接字，也称作一个通讯节点。
一个典型的网络连接由 2 个套接字构成，一个运行在客户端，另一个运行在服务器端。

eg:

```php
$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
```

- AF_INET: IPv4 网络协议。TCP 和 UDP 都可使用此协议。
- AF_UNIX: 使用 Unix 套接字. 例如 /tmp/my.sock

### socket_set_option

给已创建的socket设置选项

```php
socket_set_option ($socket, $level, $optname, $optval)
```

eg:

```php
// 设置IP和端口重用,在重启服务器后能重新使用此端口;
socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, TRUE);
```

### socket_bind

给已创建的 socket 绑定名字

```php
socket_bind ($socket, $address, $port = 0)
```

eg:

```php
socket_bind($socket, '127.0.0.1', '8080');
```

### socket_listen

监听socket上的连接. 最多允许 $backlog 个连接，超过的客户端连接会返回 WSAECONNREFUSED 错误

```php
socket_listen ($socket, $backlog = 0)
```

eg:

```php
socket_listen($socket, 50);
```

### socket_select

```php
socket_select (array &$read, array &$write, array &$except, $tv_sec, $tv_usec = 0)
```

会监控 $read 中的 socket 是否有变动

eg:

```php
$write = $except = null;
$read = [$socket];

socket_select($read, $write, $except, null)
```

- `$tv_sec =0` 时此函数立即返回，可以用于轮询机制
- `$tv_sec =null` 将会阻塞程序执行，直到有连接变动(新客户端加入、收到消息)时才会继续向下执行

### socket_accept

```php
resource socket_accept ($socket)
```

从已经监控的socket中接受新的客户端请求连接


```php
if ( false === ($newSock = socket_accept($socket)) ) {
    echo socket_strerror(socket_last_error($socket));

    return false;
}

// $this->connect($newSock);
```

### socket_recv

```php
int socket_recv ($socket, &$buf, $len, $flags)
```

从 socket 中接受长度为 $len 字节的数据，并保存在 $buf 中。返回收到的数据字节长度

```php
$data = null;
$bytes = socket_recv($sock, $data, $len, 0);
```

> 推荐使用 socket_recv 替代 socket_read

### socket_write

```php
socket_write ($socket, $buffer, $length = 0)
```

将 $buffer 中的数据写入到 $socket

eg：

```php
$msg = 'hello, welcome';
socket_write ($socket, $msg, strlen($msg))
```

### socket_close

```php
socket_close($socket)
```

关闭socket连接