<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017/5/14
 * Time: 上午12:17
 */

namespace inhere\webSocket\traits;

/**
 * Class StreamsTrait
 * @package inhere\webSocket\traits
 */
trait StreamsTrait
{
    /**
     * 设置超时
     * @param resource $stream
     * @param float $timeout
     */
    public function setTimeout($stream, $timeout = self::TIMEOUT)
    {
        if (strpos($timeout, '.')) {
            [$s, $us] = explode('.', $timeout);
            $s = $s < 1 ? self::TIMEOUT : (int)$s;
            $us = (int)($us * 1000 * 1000);
        } else {
            $s = (int)$timeout;
            $us = null;
        }

        // Set timeout on the stream as well.
        stream_set_timeout($stream, $s, $us);
    }

    /**
     * 设置buffer区
     * @param resource $stream
     * @param int $writeBufferSize
     * @param int $readBufferSize
     */
    protected function setBufferSize($stream, int $writeBufferSize, int $readBufferSize)
    {
        if ($writeBufferSize > 0) {
            stream_set_write_buffer($stream, $writeBufferSize);
        }

        if ($readBufferSize > 0) {
            stream_set_read_buffer($stream, $readBufferSize);
        }
    }

    public function enableSSL()
    {
        return stream_context_create([
            'ssl' => [
                'local_cert' => $this->get('ssl_key_file'),
                'peer_fingerprint' => openssl_x509_fingerprint(file_get_contents($this->get('ssl_cert_file'))),
                'allow_self_signed' => true,
                'verify_depth' => 0,
                'verify_peer' => false,
                'verify_peer_name' => false,
            ]
        ]);
    }

    public function enableSSL1()
    {
        $pem_passphrase = 'mykey';
        $pemFile = './server.pem';
        $caFile = './server.crt';

        return stream_context_create([
            'ssl' => [
                // local_cert must be in PEM format
                'local_cert' => $pemFile,
                'cafile' => $caFile,
                'capath' => './',

                // Pass Phrase (password) of private key
                'passphrase' => $pem_passphrase,
                'allow_self_signed' => true,
                'verify_peer' => false,
            ]
        ]);
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
            'ip' => '',
            'port' => 0,
        ];

        list($data['ip'], $data['port']) = explode(':', $name);

        return $data;
    }

    /**
     * 用于获取客户端socket的本地host:port，必须在连接之后才可以使用
     * @return array
     */
    public function getSockName()
    {
        $name = stream_socket_get_name($this->socket, false);
        $data = [
            'ip' => '',
            'port' => 0,
        ];

        list($data['ip'], $data['port']) = explode(':', $name);

        return $data;
    }

}