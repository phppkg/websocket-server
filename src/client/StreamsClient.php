<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-04-01
 * Time: 12:44
 */

namespace inhere\webSocket\client;

use inhere\exceptions\ConnectException;

/**
 * Class StreamsClient
 * @package inhere\webSocket\client
 */
class StreamsClient extends ClientAbstracter
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
     * @param float $timeout
     * @param int $flag
     * @throws ConnectException
     */
    protected function doConnect($timeout = 1.3, $flag = 0)
    {
        $uri = $this->getUri();
        $scheme = $uri->getScheme() ?: self::PROTOCOL_WS;

        if (!in_array($scheme, [self::PROTOCOL_WS, self::PROTOCOL_WSS], true)) {
            throw new \InvalidArgumentException("Url should have scheme ws or wss, you setting is: $scheme");
        }

        // Set the stream context options if they're already set in the config
        if ($context = $this->getOption('context')) {
            // Suppress the error since we'll catch it below
            if ( is_resource($context) && get_resource_type($context) !== 'stream-context') {
                throw new \InvalidArgumentException("Stream context in options[context] isn't a valid context resource");
            }
        } else {
            $context = stream_context_create();
        }

        $timeout = $timeout ?: $this->getOption('timeout', 2.2);

        $host = $this->getHost();
        $port = $this->getPort();
        $schemeHost = ($scheme === self::PROTOCOL_WSS ? 'ssl' : 'tcp') . "://$host"; // 'ssl', 'tls', 'wss'
        $remote = $schemeHost . ($port ? ":$port" : '');

        // Open the socket.  @ is there to suppress warning that we will catch in check below instead.
        $this->socket = stream_socket_client(
            $remote,
            $errNo,
            $errStr,
            (int)$timeout < 1 ? 1: (int)$timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        // can also use: fsockopen — 打开一个网络连接或者一个Unix套接字连接
        // $this->socket = fsockopen($schemeHost, $port, $errNo, $errStr, $timeout);

        if ($this->socket === false) {
            throw new ConnectException("Could not connect socket to $remote, Error: $errStr ($errNo).");
        }

        // Set timeout on the stream as well.
        $this->setTimeout($timeout);
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
     * @param int $length
     * @return string
     */
    public function readResponseHeader($length = 2048)
    {
        if ($length < 1024) {
            $length = 1024;
        }

        // Get server response header (terminated with double CR+LF).
        return stream_get_line($this->socket, $length, self::HEADER_END) . self::HEADER_END;
    }

    /**
     * @param $length
     * @return string
     * @throws ConnectException
     */
    protected function read($length = null)
    {
        if ($length > 0) {
            return $this->readLength($length);
        }

        $data = '';
        $fragmentSize = $this->getOption('fragment_size') ?: self::DEFAULT_FRAGMENT_SIZE;

        do {
            $buff = fread($this->socket, $fragmentSize);
            $meta = stream_get_meta_data($this->socket);

            if ($buff === false) {
                $this->log('read data is failed. Stream state: ', 'error', $meta);
                return false;
            }

            $data .= $buff;
            $fragmentSize = min((int)$meta['unread_bytes'], $fragmentSize);
            usleep(1000);

        } while (!feof($this->socket) && (int)$meta['unread_bytes'] > 0);

        return $data;
    }

    protected function readLength($length)
    {
        $data = '';

        while (strlen($data) < $length) {
            $buffer = fread($this->socket, $length - strlen($data));

            if ($buffer === false) {
                $metadata = stream_get_meta_data($this->socket);

                throw new ConnectException('Broken frame, read ' . strlen($data) . ' of stated '
                    . $length . ' bytes.  Stream state: ' . json_encode($metadata)
                );
            }

            if ($buffer === '') {
                $metadata = stream_get_meta_data($this->socket);
                throw new ConnectException('Empty read; connection dead?  Stream state: ' . json_encode($metadata));
            }
            $data .= $buffer;
        }

        return $data;
    }

    public function close(bool $force = false)
    {
        if ( $this->socket ) {
            if (get_resource_type($this->socket) === 'stream') {
                stream_socket_shutdown($this->socket, STREAM_SHUT_WR);
                fclose($this->socket);
            }

            $this->socket = null;
        }

        $this->setConnected(false);
    }

    /**
     * 用于获取客户端socket的本地host:port，必须在连接之后才可以使用
     * @return array
     */
    public function getSockName()
    {
        $name = stream_socket_get_name($this->socket, false);
        $data = [
            'host' => '',
            'port' => 0,
        ];

        [$data['host'], $data['port']] = explode(':', $name);

        return $data;
    }

    /**
     * 获取对端(远端)socket的IP地址和端口
     * @return array
     */
    public function getPeerName()
    {
        $name = stream_socket_get_name($this->socket, true);
        $data = [
            'host' => '',
            'port' => 0,
        ];

        [$data['host'], $data['port']] = explode(':', $name);

        return $data;
    }

    public function getErrorNo()
    {
        // TODO: Implement getErrorNo() method.
    }

    public function getErrorMsg()
    {
        // TODO: Implement getErrorNo() method.
    }
}
