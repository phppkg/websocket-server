<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-04-01
 * Time: 12:05
 */

namespace inhere\webSocket\server;

/**
 * Class StreamsServer
 * @package inhere\webSocket\server
 */
class StreamsServer extends ServerAbstracter
{
    /**
     * @var string
     */
    protected $name = 'streams';

    /**
     * @inheritdoc
     */
    public static function isSupported()
    {
        return function_exists('stream_socket_accept');
    }

    /**
     * create and prepare socket resource
     */
    protected function prepareWork()
    {
        // Set the stream context options if they're already set in the config
        if ($context = $this->getOption('context')) {
            // Suppress the error since we'll catch it below
            if ( is_resource($context) && get_resource_type($context) !== 'stream-context') {
                throw new \InvalidArgumentException("Stream context in options[context] isn't a valid context resource");
            }
        } else {
            $context = stream_context_create();
        }

        $host = $this->getHost();
        $port = $this->getPort();
        $this->socket = stream_socket_server(
            "tcp://$host:$port",
            $errNo,
            $errStr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context
        );

        if ( !is_resource($this->socket) ) {
            $this->print('[ERROR] Could not listen on socket: '. $errStr, true, $errNo);
        }

        $this->setTimeout($this->getOption('timeout', 2.2));
        // $this->listening = true;

        $max = $this->getOption('max_conn', 20);

        $this->log("Started WebSocket server on {$this->host}:{$this->port} (max allow connection: $max)");
    }

    protected function doStart()
    {
        $maxLen = (int)$this->getOption('max_data_len', 2048);

        // interval time
        $setTime = (int)$this->getOption('sleep_ms', 800);
        $sleepTime = $setTime > 50 ? $setTime : 800;
        $sleepTime *= 1000; // ms -> us

        while(true) {
            $write = $except = null;
            // copy， 防止 $this->clients 的变动被 socket_select() 接收到
            $read = $this->clients;
            $read[] = $this->socket;

            // 会监控 $read 中的 socket 是否有变动
            // $tv_sec =0 时此函数立即返回，可以用于轮询机制
            // $tv_sec =null 将会阻塞程序执行，直到有新连接时才会继续向下执行
            if ( false === stream_select($read, $write, $except, null) ) {
                $this->log('stream_select() failed, reason: unknown', 'error');
                continue;
            }

            // handle ...
            foreach ($read as $sock) {
                $this->handleSocket($sock, $maxLen);
            }

            //sleep(1);
            usleep($sleepTime);
        }
    }

    /**
     * @param resource $sock
     * @param int $dataLen
     * @return bool
     */
    protected function handleSocket($sock, $dataLen)
    {
        // 每次循环检查到 $this->socket 时，都会用 stream_socket_accept() 去检查是否有新的连接进入，有就加入连接列表
        if($sock === $this->socket) {
            // 从已经监控的socket中接受新的客户端请求
            if ( false === ($newSock = stream_socket_accept($sock)) ) {
                $this->error('accept new socket connection failed');

                return false;
            }

            $name = stream_socket_get_name($this->socket, false);
            $name1 = stream_socket_get_name($newSock, true);

            $this->log("Local name: $name, Accepted name: $name1");

            $this->connect($newSock);
            return true;
        }

        $cid = (int)$sock;

        // 不在已经记录的client列表中
        if ( !isset($this->metas[$cid], $this->clients[$cid])) {
            return $this->close($cid, $sock);
        }

        // 函数 stream_socket_recvfrom () 从 socket 中接受长度为 len 字节的数据。
        $data = stream_socket_recvfrom($sock, $dataLen, 0);
        $bytes = strlen($data);

        // 没有发送数据或者小于7字节
        if ($bytes < 7 || !$data ) {
            $this->log("Failed to receive data or not received data(client close connection) from #$cid client, will close the socket.");
            return $this->close($cid, $sock);
        }

        // 是否已经握手
        if ( !$this->metas[$cid]['handshake'] ) {
            return $this->handshake($sock, $data, $cid);
        }

        $this->message($cid, $data, $bytes, $this->metas[$cid]);

        return true;
    }

    /**
     * 增加一个初次连接的客户端 同时记录到握手列表，标记为未握手
     * @param resource $socket
     */
    protected function connect($socket)
    {
        $cid = (int)$socket;
        $data = $this->getPeerName($socket);

        // 初始化客户端信息
        $this->metas[$cid] = $meta = [
            'host' => $data['host'],
            'port' => $data['port'],
            'handshake' => false,
            'path' => '/',
        ];
        // 客户端连接单独保存
        $this->clients[$cid] = $socket;

        $this->log("A new client connected, ID: $cid, From {$meta['host']}:{$meta['port']}. Count: " . $this->count());

        // 触发 connect 事件回调
        $this->trigger(self::ON_CONNECT, [$this, $cid]);
    }

    /**
     * Closing a connection
     * @param resource $socket
     * @return bool
     */
    protected function doClose($socket)
    {
        // close socket connection
        if ( is_resource($socket)  ) {
            fclose($socket);
        }

        return true;
    }

    /**
     * response data to client by socket connection
     * @param resource $socket
     * @param string $data
     * @param int $length
     * @return int      Return socket last error number code. gt 0 on failure, eq 0 on success
     */
    public function writeTo($socket, string $data, int $length = 0)
    {
        return stream_socket_sendto($socket, $data);
    }

    public function enableSSL()
    {
        $pem_passphrase = 'mykey';
        $pemfile = './server.pem';
        $ca = './server.crt';

        $context = stream_context_create ();

        // local_cert must be in PEM format
        stream_context_set_option ( $context, 'ssl', 'local_cert', $pemfile );
        stream_context_set_option ( $context, 'ssl', 'cafile', $ca );
        stream_context_set_option ( $context, 'ssl', 'capath', './');

        // Pass Phrase (password) of private key
        stream_context_set_option ( $context, 'ssl', 'passphrase', $pem_passphrase );

        stream_context_set_option ( $context, 'ssl', 'allow_self_signed', true );
        stream_context_set_option ( $context, 'ssl', 'verify_peer', true );
    }

    public function setTimeout($timeout = 2.2)
    {
        if (strpos($timeout, '.')) {
            [$s, $us] = explode('.', $timeout);
            $s = $s < 1 ? 1 : (int)$s;
            $us = (int)($us * 1000 * 1000);
        } else {
            $s = (int)$timeout;
            $us = null;
        }

        // Set timeout on the stream as well.
        stream_set_timeout($this->socket, $s, $us);
    }

    /**
     * 设置buffer区
     * @param int $sendBufferSize
     * @param int $rcvBufferSize
     */
    public function setBufferSize($sendBufferSize, $rcvBufferSize)
    {
        stream_set_write_buffer($this->socket, $sendBufferSize);
        stream_set_read_buffer($this->socket, $rcvBufferSize);
    }

    /**
     * 获取对端socket的IP地址和端口
     * @param resource $socket
     * @return array
     */
    public function getPeerName($socket)
    {
        $name = stream_socket_get_name($socket, true);
        $data = [
            'host' => '',
            'port' => 0,
        ];

        [$data['host'], $data['port']] = explode(':', $name);

        return $data;
    }

    /**
     * @param null|resource $socket
     * @return int
     */
    public function getErrorNo($socket = null)
    {
        return 0;
    }

    /**
     * @param null|resource $socket
     * @return string
     */
    public function getErrorMsg($socket = null)
    {
        return 'unknown';
    }
}
