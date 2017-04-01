# Sockets 扩展

doc http://php.net/manual/zh/book.sockets.php

- Example #1 Socket举例：简单的TCP/IP服务器

```php
#!/usr/bin/env php
<?php
error_reporting(E_ALL);

/* Allow the script to hang around waiting for connections. */
set_time_limit(0);

/* Turn on implicit output flushing so we see what we're getting
 * as it comes in. */
ob_implicit_flush();

$address = '127.0.0.1';
$port = 10000;

if (($sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)) === false) {
    echo "socket_create() failed: reason: " . socket_strerror(socket_last_error()) . "\n";
}

if (socket_bind($sock, $address, $port) === false) {
    echo "socket_bind() failed: reason: " . socket_strerror(socket_last_error($sock)) . "\n";
}

if (socket_listen($sock, 5) === false) {
    echo "socket_listen() failed: reason: " . socket_strerror(socket_last_error($sock)) . "\n";
}

//clients array
$clients = array();

do {
    $read = array();
    $read[] = $sock;
    
    $read = array_merge($read,$clients);
    
    // Set up a blocking call to socket_select
    if(socket_select($read,$write = NULL, $except = NULL, $tv_sec = 5) < 1)
    {
        //    SocketServer::debug("Problem blocking socket_select?");
        continue;
    }
    
    // Handle new Connections
    if (in_array($sock, $read)) {        
        
        if (($msgsock = socket_accept($sock)) === false) {
            echo "socket_accept() failed: reason: " . socket_strerror(socket_last_error($sock)) . "\n";
            break;
        }
        $clients[] = $msgsock;
        $key = array_keys($clients, $msgsock);
        
        /* Send instructions. */
        $msg = "\nWelcome to the PHP Test Server. \n" .
                "your client number: {$key[0]}\n" .
                "To quit, type 'quit'. To shut down the server type 'shutdown'.\n";
        socket_write($msgsock, $msg, strlen($msg));
        
    }
    
    // Handle Input
    foreach ($clients as $key => $client) { // for each client        
        if (in_array($client, $read)) {
            if (false === ($buf = socket_read($client, 2048, PHP_NORMAL_READ))) {
                echo "socket_read() failed: reason: " . socket_strerror(socket_last_error($client)) . "\n";
                break 2;
            }
            if (!$buf = trim($buf)) {
                continue;
            }
            if ($buf == 'quit') {
                unset($clients[$key]);
                socket_close($client);
                break;
            }
            if ($buf == 'shutdown') {
                socket_close($client);
                break 2;
            }
            $talkback = "Client {$key}: You said '$buf'.\n";
            socket_write($client, $talkback, strlen($talkback));
            echo "$buf\n";
        }
        
    }        
} while (true);

socket_close($sock);
```

- Example #2 Socket举例：简单的TCP/IP客户端

这个例子展示了一个简单的，一次性的HTTP客户端。 它只是连接到一个页面，提交一个HEAD请求，输出回复，然后退出。

```php
<?php
error_reporting(E_ALL);

echo "<h2>TCP/IP Connection</h2>\n";

/* Get the port for the WWW service. */
$service_port = getservbyname('www', 'tcp');

/* Get the IP address for the target host. */
$address = gethostbyname('www.example.com');

/* Create a TCP/IP socket. */
$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if ($socket === false) {
    echo "socket_create() failed: reason: " . socket_strerror(socket_last_error()) . "\n";
} else {
    echo "OK.\n";
}

echo "Attempting to connect to '$address' on port '$service_port'...";
$result = socket_connect($socket, $address, $service_port);
if ($result === false) {
    echo "socket_connect() failed.\nReason: ($result) " . socket_strerror(socket_last_error($socket)) . "\n";
} else {
    echo "OK.\n";
}

$in = "HEAD / HTTP/1.1\r\n";
$in .= "Host: www.example.com\r\n";
$in .= "Connection: Close\r\n\r\n";
$out = '';

echo "Sending HTTP HEAD request...";
socket_write($socket, $in, strlen($in));
echo "OK.\n";

echo "Reading response:\n\n";
while ($out = socket_read($socket, 2048)) {
    echo $out;
}

echo "Closing socket...";
socket_close($socket);
echo "OK.\n\n";
```

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

### socket_connect

开启一个套接字连接

```php
bool socket_connect ( resource $socket , string $address [, int $port = 0 ] )
```

用 `socket_create()` 创建的有效的套接字资源来连接到 address 。

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

### socket_read

Reads a maximum of length bytes from a socket

```php
string socket_read ( resource $socket , int $length [, int $type = PHP_BINARY_READ ] )
```

The function `socket_read()` reads from the socket resource socket created by the `socket_create()` or `socket_accept()` functions.

### socket_recv

从已连接的socket接收数据

```php
int socket_recv ($socket, &$buf, $len, $flags)
```

函数 `socket_recv()` 从 socket 中接受长度为 len 字节的数据，并保存在 buf 中。 
`socket_recv()` 用于从已连接的socket中接收数据。除此之外，可以设定一个或多个 flags 来控制函数的具体行为。

buf 以引用形式传递，因此必须是一个以声明的有效的变量。从 socket 中接收到的数据将会保存在 buf 中。

```php
$data = null;
$bytes = socket_recv($sock, $data, $len, 0);
```

> 推荐使用 socket_recv 替代 socket_read

### socket_recvfrom

```php
int socket_recvfrom ( resource $socket , string &$buf , int $len , int $flags , string &$name [, int &$port ] )
```

### socket_recvmsg

Read a message

```php
int socket_recvmsg ( resource $socket , string $message [, int $flags ] )
```

### socket_close

```php
socket_close($socket)
```

关闭socket连接

### socket_send

```php
int socket_send ( resource $socket , string $buf , int $len , int $flags )
```

The function socket_send() sends len bytes to the socket socket from buf.

### socket_sendto

 Sends a message to a socket, whether it is connected or not

```php
int socket_sendto ( resource $socket , string $buf , int $len , int $flags , string $addr [, int $port = 0 ] )
```

The function `socket_sendto()` sends len bytes from buf through the socket socket to the port at the address addr.

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

### socket_set_block

设置套接字资源为阻塞模式

### socket_set_nonblock

设置套接字资源为非阻塞模式
 
 