<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-04-01
 * Time: 12:44
 */

namespace Inhere\WebSocket\Client;

use inhere\exceptions\ConnectException;
use Inhere\WebSocket\Traits\StreamsTrait;

/**
 * Class StreamsClient
 * power by `streams` extension(php built-in)
 * @package Inhere\WebSocket\Client
 */
class StreamsClient extends ClientAbstracter
{
    use StreamsTrait;

    /**
     * @var string
     */
    protected $driver = 'streams';

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
    protected function doConnect($timeout = self::TIMEOUT, $flag = 0)
    {
        $uri = $this->getUri();
        $scheme = $uri->getScheme() ?: self::PROTOCOL_WS;

        if (!in_array($scheme, [self::PROTOCOL_WS, self::PROTOCOL_WSS], true)) {
            throw new \InvalidArgumentException("Url should have scheme ws or wss, you setting is: $scheme");
        }

        // Set the stream context options if they're already set in the config
        if ($context = $this->get('context')) {
            // Suppress the error since we'll catch it below
            if (is_resource($context) && get_resource_type($context) !== 'stream-context') {
                throw new \InvalidArgumentException("Stream context in options[context] isn't a valid context resource");
            }
        } else if ($this->get('enable_ssl')) {
            $context = $this->enableSSL();
        } else {
            $context = stream_context_create();
        }

        $timeout = $timeout ?: $this->get('timeout');

        $host = $this->getHost();
        $port = $this->getPort();
        $schemeHost = ($scheme === self::PROTOCOL_WSS ? 'ssl' : 'tcp') . "://$host"; // 'ssl', 'tls', 'wss'
        $remote = $schemeHost . ($port ? ":$port" : '');

        // Open the socket.  @ is there to suppress warning that we will catch in check below instead.
        $this->socket = stream_socket_client(
            $remote,
            $errNo,
            $errStr,
            (int)$timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        // can also use: fsockopen — 打开一个网络连接或者一个Unix套接字连接
        // $this->socket = fsockopen($schemeHost, $port, $errNo, $errStr, $timeout);

        if ($this->socket === false) {
            throw new ConnectException("Could not connect socket to $remote, Error: $errStr ($errNo).");
        }

        // Set timeout on the stream as well.
        $this->setTimeout($this->socket, $timeout);

        // 设置缓冲区大小
        $this->setBufferSize(
            $this->socket,
            (int)$this->get('write_buffer_size'),
            (int)$this->get('read_buffer_size')
        );
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
        $fragmentSize = $this->get('fragment_size') ?: self::DEFAULT_FRAGMENT_SIZE;

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

    /**
     * {@inheritDoc}
     */
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

    /**
     * @param bool $force
     */
    public function close(bool $force = false)
    {
        if ($this->socket) {
            if (get_resource_type($this->socket) === 'stream') {
                stream_socket_shutdown($this->socket, STREAM_SHUT_WR);
                fclose($this->socket);
            }

            $this->socket = null;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getErrorNo()
    {
        return 0;
    }

    /**
     * {@inheritDoc}
     */
    public function getErrorMsg()
    {
        // TODO: Implement getErrorNo() method.
    }
}
