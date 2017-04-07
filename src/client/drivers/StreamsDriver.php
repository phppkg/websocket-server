<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-04-01
 * Time: 12:44
 */

namespace inhere\webSocket\client\drivers;

use inhere\exceptions\ConnectException;
use inhere\webSocket\http\Request;

/**
 * Class StreamsDriver
 * @package inhere\webSocket\client\drivers
 */
class StreamsDriver extends AClientDriver
{
    /**
     * @inheritdoc
     */
    public static function isSupported()
    {
        return function_exists('stream_socket_accept');
    }


    public function start()
    {
        if (!$this->isConnected()) {
            $this->connect();
        }

        $tickHandler = $this->callbacks[self::ON_TICK];
        $msgHandler = $this->callbacks[self::ON_MESSAGE];

        while (true) {
            if ($tickHandler && (call_user_func($tickHandler, $this) === false)) {
                break;
            }

            $write = $except = null;
            $changed = [$this->socket];

            if (stream_select($changed, $write, $except, null) > 0) {
                foreach ($changed as $socket) {
                    $message = $this->receive();

                    if ($message !== false && $msgHandler) {
                        call_user_func($msgHandler, $message, $this);
                    }
                }
            }

            usleep(5000);
        }
    }

    public function connect($timeout = 3, $flag = 0)
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

        $host = $this->getHost();
        $port = $this->getPort();
        $timeout = $timeout ?: $this->getOption('timeout');
        $schemeHost = ($scheme === self::PROTOCOL_WSS ? 'ssl' : 'tcp') . "://$host";// 'ssl', 'tls', 'wss'
        $remote = $schemeHost . ($port ? ":$port" : '');

        // Open the socket.  @ is there to suppress warning that we will catch in check below instead.
        $this->socket = @stream_socket_client($remote, $errNo, $errStr, $timeout, STREAM_CLIENT_CONNECT, $context);

        // can also use: fsockopen — 打开一个网络连接或者一个Unix套接字连接
        // $this->socket = fsockopen($schemeHost, $port, $errNo, $errStr, $timeout);

        if ($this->socket === false) {
            throw new ConnectException("Could not connect socket to $remote, Error: $errStr ($errNo).");
        }

        // Set timeout on the stream as well.
        stream_set_timeout($this->socket, $timeout);

        $this->setConnected(true);

        $request = $this->request->toString();
        $this->log("Request header: \n$request");
        $this->write($request);

        // Get server response header
        $header = $this->readResponseHeader();

        // handshake
        $this->doHandShake($header);
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
        return stream_get_line($this->socket, $length, self::HEADER_END);
    }

    /**
     * @param $length
     * @return string
     * @throws ConnectException
     */
    protected function read($length)
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
    public function disconnect(bool $force = false)
    {
        $this->close($force);
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
        return [
            'host' => '',
            'port' => 0,
        ];
    }

    /**
     * 获取对端(远端)socket的IP地址和端口
     * @return array
     */
    public function getPeerName()
    {
        return [
            'host' => '',
            'port' => 0,
        ];
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
