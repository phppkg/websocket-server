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
