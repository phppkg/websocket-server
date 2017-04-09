<?php
/**
 * Created by PhpStorm.
 * User: Inhere
 * Date: 2017/4/8 0008
 * Time: 23:10
 */

namespace inhere\webSocket;

use inhere\library\traits\TraitSimpleFixedEvent;
use inhere\library\traits\TraitSimpleOption;

/**
 * Class BaseAbstracter
 * @package inhere\webSocket
 */
abstract class BaseAbstracter
{
    use TraitSimpleOption;
    use TraitSimpleFixedEvent;

    /**
     * version
     */
    const VERSION = '0.5.1';

    /**
     * Websocket version
     */
    const WS_VERSION = '13';

    const SIGN_KEY = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

//    const TOKEN_CHARS = ' abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!"§$%&/()=[]{}';
    const TOKEN_CHARS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!"$&/()=[]{}0123456789';

    const DEFAULT_HOST = '0.0.0.0';

    const DEFAULT_PORT = 8080;

    /**
     * @var string
     */
    protected $host;

    /**
     * @var int
     */
    protected $port;

    /**
     * @return string
     */
    public function getHost(): string
    {
        if ( !$this->host ) {
            $this->host = self::DEFAULT_HOST;
        }

        return $this->host;
    }

    /**
     * @return int
     */
    public function getPort(): int
    {
        if ( !$this->port || $this->port <= 0 ) {
            $this->port = self::DEFAULT_PORT;
        }

        return $this->port;
    }

    /**
     * Generate a random string for WebSocket key.(for client)
     * @return string Random string
     */
    public function genKey(): string
    {
        $key = '';
        $chars = self::TOKEN_CHARS;
        $chars_length = strlen($chars);

        for ($i = 0; $i < 16; $i++) {
            $key .= $chars[random_int(0, $chars_length - 1)]; //mt_rand
        }

        return base64_encode($key);
    }

    /**
     * Generate WebSocket sign.(for server)
     * @param string $key
     * @return string
     */
    public function genSign(string $key): string
    {
        return base64_encode(sha1(trim($key) . self::SIGN_KEY, true));
    }
}
