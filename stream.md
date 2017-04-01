# Stream 系列函数

## stream_context_create

创建资源流上下文

```php
resource stream_context_create ([ array $options [, array $params ]] )
```

创建并返回一个资源流上下文，该资源流中包含了 options 中提前设定的所有参数的值。

eg:

```php
<?php
$opts = array(
  'http'=>array(
    'method'=>"GET",
    'header'=>"Accept-language: en\r\n" .
              "Cookie: foo=bar\r\n"
  )
);

$context = stream_context_create($opts);

/* Sends an http request to www.example.com
   with additional headers shown above */
$fp = fopen('http://www.example.com', 'r', false, $context);
fpassthru($fp);
fclose($fp);
```

## stream_get_contents

读取资源流到一个字符串

```php
string stream_get_contents ( resource $handle [, int $maxlength = -1 [, int $offset = -1 ]] )
```

与 `file_get_contents()` 一样，但是 `stream_get_contents()` 是对一个 **已经打开** 的资源流进行操作，并将其内容写入一个字符串返回。 
返回的内容取决于 `maxlength` 字节长度和 `offset` 指定的起始位置。

参数:

- `handle (resource)` 一个资源流（例如 `fopen()` 操作之后返回的结果）
- `maxlength (integer)` 需要读取的最大的字节数。默认是-1（读取全部的缓冲数据）。
- `offset (integer)` 在读取数据之前先查找指定的偏移量。如果这个数字是负数，就不进行查找，直接从当前位置开始读取。

## stream_get_line

从资源流里读取一行直到给定的定界符

```php
string stream_get_line ( resource $handle , int $length [, string $ending ] )
```

从给定的资源流里读取一行。

当读取到 `length` 个字节数就结束，或者当在读取的字符串中发现 `ending` （不包含到返回值里）也结束，又或者遇到了 `EOF` 也结束（总之以上条件中哪个先出现就以哪个为准）。

这个函数与 `fgets()` 几乎是相同的，唯一的区别是在这个函数里面允许指定行尾的定界符，而不是使用标准的 `\n`， `\r` 还有 `\r\n` ，并且返回值中不包含定界符。（翻译注：也可以把 `\n` 等作为定界符传入 ending ）


## stream_get_meta_data 

从封装协议文件指针中取得报头／元数据

```php
array stream_get_meta_data ( int $fp )
```

返回现有 stream 的信息。可以是任何通过 `fopen()`，`fsockopen()` 和 `pfsockopen()` 建立的流。返回的数组包含以下项目

> Note: 本函数不能作用于通过 Socket 扩展库创建的流。

返回的数组包含以下项目:

- `timed_out (bool)` - 如果在上次调用 `fread()` 或者 `fgets()` 中等待数据时流超时了则为 TRUE。
- `blocked (bool)` - 如果流处于阻塞 IO 模式时为 TRUE。参见 stream_set_blocking()。
- `eof (bool)` - 如果流到达文件末尾时为 TRUE。注意对于 socket 流甚至当 `unread_bytes` 为非零值时也可以为 TRUE。要测定是否有更多数据可读，用 `feof()` 替代读取本项目的值。
- `unread_bytes (int)` - 当前在 PHP 自己的内部缓冲区中的字节数。


## stream_get_transports

获取已注册的套接字传输协议列表

```php
array stream_get_transports ( void )
```

返回一个包含当前运行系统中所有套接字传输协议名称的索引数组。

Example:

```php
<?php
$xPortList = stream_get_transports();
print_r($xPortList);
```

```php
// OUT:
Array (
  [0] => tcp
  [1] => udp
  [2] => unix
  [3] => udg
)
```

## stream_get_wrappers

 获取已注册的流类型

```php
array stream_get_wrappers ( void )
```

获取在当前运行系统中已经注册并可使用的流类型列表。

Example：

```php
<?php
print_r(stream_get_wrappers());
?>
```

输出类似于：

```php
Array
(
    [0] => php
    [1] => file
    [2] => http
    [3] => ftp
    [4] => compress.bzip2
    [5] => compress.zlib
)
```

## stream_set_blocking

为资源流设置阻塞或者阻塞模式

```php
bool stream_set_blocking ( resource $stream , int $mode )
```

此函数适用于支持非阻塞模式的任何资源流（常规文件，套接字资源流等）。

- `stream` 资源流。
- `mode` 如果 mode 为0，资源流将会被转换为非阻塞模式；如果是1，资源流将会被转换为阻塞模式。 
该参数的设置将会影响到像 `fgets()` 和 `fread()` 这样的函数从资源流里读取数据。 
在非阻塞模式下，调用 `fgets()` 总是会立即返回；而在阻塞模式下，将会一直等到从资源流里面获取到数据才能返回。

## stream_set_chunk_size 
 
设置资源流区块大小

```php
int stream_set_chunk_size ( resource $fp , int $chunk_size )
```

## stream_set_timeout

设置资源流的超时时间

```php
bool stream_set_timeout ( resource $stream , int $seconds [, int $microseconds = 0 ] )
```

## stream_socket_accept

接受由 `stream_socket_server()` 创建的套接字连接

```php
resource stream_socket_accept ( resource $server_socket [, float $timeout = ini_get("default_socket_timeout") [, string &$peername ]] )
```

> Warning
  该函数不能被用于 UDP 套接字。可以使用 `stream_socket_recvfrom()` 和 `stream_socket_sendto()` 来取而代之。

## stream_socket_client

打开互联网或Unix域套接字连接

```php
resource stream_socket_client ( string $remote_socket [, int &$errno [, string &$errstr [, float $timeout = ini_get("default_socket_timeout") [, int $flags = STREAM_CLIENT_CONNECT [, resource $context ]]]]] )
```

- flags `STREAM_CLIENT_CONNECT` (default), `STREAM_CLIENT_ASYNC_CONNECT` and `STREAM_CLIENT_PERSISTENT`.

> 流在默认情况下会在阻塞模式下打开。您可以通过使用 `stream_set_blocking()` 切换到非阻塞模式

TCP client:

```php
<?php
$fp = stream_socket_client('tcp://www.example.com:80', $errno, $errstr, 30);
if (!$fp) {
    echo "$errstr ($errno)<br />\n";
} else {
    fwrite($fp, "GET / HTTP/1.0\r\nHost: www.example.com\r\nAccept: */*\r\n\r\n");
    while (!feof($fp)) {
        echo fgets($fp, 1024);
    }
    fclose($fp);
}
```

UDP client:

```php
$fp = stream_socket_client("udp://127.0.0.1:13", $errno, $errstr);
if (!$fp) {
    echo "ERROR: $errno - $errstr<br />\n";
} else {
    fwrite($fp, "\n");
    echo fread($fp, 26);
    fclose($fp);
}
```

## stream_socket_enable_crypto

```php
mixed stream_socket_enable_crypto ( resource $stream , bool $enable [, int $crypto_type [, resource $session_stream ]] )
```

启用或者关闭加密传输

## stream_socket_get_name

```php
string stream_socket_get_name ( resource $handle , bool $want_peer )
```

返回给定的本地或者远程套接字连接的名称。

- `$want_peer` 如果设置为 TRUE ，那么将返回 remote 套接字连接名称；如果设置为 FALSE 则返回 local 套接字连接名称。

## stream_socket_pair

```php
array stream_socket_pair ( int $domain , int $type , int $protocol )
```

创建一对完全一样的网络套接字连接，这个函数通常会被用在进程间通信(Inter-Process Communication)

参数：

- `domain` 使用的协议族： STREAM_PF_INET, STREAM_PF_INET6 or STREAM_PF_UNIX
- `type` 通信类型: STREAM_SOCK_DGRAM, STREAM_SOCK_RAW, STREAM_SOCK_RDM, STREAM_SOCK_SEQPACKET or STREAM_SOCK_STREAM
- `protocol` 使用的传输协议: STREAM_IPPROTO_ICMP, STREAM_IPPROTO_IP, STREAM_IPPROTO_RAW, STREAM_IPPROTO_TCP or STREAM_IPPROTO_UDP

如果成功将返回一个数组包括了两个socket资源，错误时返回FALSE

> 这个函数在windows平台不可用

example:

```php
<?php

$sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
$pid     = pcntl_fork();

if ($pid == -1) {
     die('could not fork');

} else if ($pid) {
     /* parent */
    fclose($sockets[0]);

    fwrite($sockets[1], "child PID: $pid\n");
    echo fgets($sockets[1]);

    fclose($sockets[1]);

} else {
    /* child */
    fclose($sockets[1]);

    fwrite($sockets[0], "message from child\n");
    echo fgets($sockets[0]);

    fclose($sockets[0]);
}
```

## stream_socket_recvfrom

```php
string stream_socket_recvfrom ( resource $socket , int $length [, int $flags = 0 [, string &$address ]] )
```

accepts data from a remote socket(connected or not) up to length bytes.

```php
/* Open a server socket to port 1234 on localhost */
$server = stream_socket_server('tcp://127.0.0.1:1234');

/* Accept a connection */
$socket = stream_socket_accept($server);

/* Grab a packet (1500 is a typical MTU size) of OOB data */
echo "Received Out-Of-Band: '" . stream_socket_recvfrom($socket, 1500, STREAM_OOB) . "'\n";

/* Take a peek at the normal in-band data, but don't comsume it. */
echo "Data: '" . stream_socket_recvfrom($socket, 1500, STREAM_PEEK) . "'\n";

/* Get the exact same packet again, but remove it from the buffer this time. */
echo "Data: '" . stream_socket_recvfrom($socket, 1500) . "'\n";

/* Close it up */
fclose($socket);
fclose($server);
```

## stream_socket_sendto


```php
int stream_socket_sendto ( resource $socket , string $data [, int $flags = 0 [, string $address ]] )
```

 Sends a message to a socket, whether it is connected or not
 
example:
 
```php
<?php
/* Open a socket to port 1234 on localhost */
$socket = stream_socket_client('tcp://127.0.0.1:1234');

/* Send ordinary data via ordinary channels. */
fwrite($socket, "Normal data transmit.");

/* Send more data out of band. */
stream_socket_sendto($socket, "Out of Band data.", STREAM_OOB);

/* Close it up */
fclose($socket);
``` 

## stream_socket_server

创建一个网络或Unix域服务器套接字

```php
resource stream_socket_server ( string $local_socket [, int &$errno [, string &$errstr [, int $flags = STREAM_SERVER_BIND | STREAM_SERVER_LISTEN [, resource $context ]]]] )
```

Creates a stream or datagram socket on the specified local_socket.

This function only creates a socket, to begin accepting connections use `stream_socket_accept()`.


> For UDP sockets, you must use `STREAM_SERVER_BIND` as the `flags` parameter.

TCP server sockets:

```php
<?php
$socket = stream_socket_server("tcp://0.0.0.0:8000", $errno, $errstr);
if (!$socket) {
  echo "$errstr ($errno)<br />\n";
} else {
  while ($conn = stream_socket_accept($socket)) {
    fwrite($conn, 'The local time is ' . date('n/j/Y g:i a') . "\n");
    fclose($conn);
  }
  fclose($socket);
}
```


UDP server sockets:

```php
<?php
$socket = stream_socket_server("udp://127.0.0.1:1113", $errno, $errstr, STREAM_SERVER_BIND);
if (!$socket) {
    die("$errstr ($errno)");
}

do {
    $pkt = stream_socket_recvfrom($socket, 1, 0, $peer);
    echo "$peer\n";
    stream_socket_sendto($socket, date("D M j H:i:s Y\r\n"), 0, $peer);
} while ($pkt !== false);
```

## stream_socket_shutdown

```php
bool stream_socket_shutdown ( resource $stream , int $how )
```

Shutdowns (partially or not) a full-duplex connection.

> 相关的缓冲区数据 可能会也可能不会被清空