<?php
/**
 * Created by PhpStorm.
 * User: Inhere
 * Date: 2017/4/10 0010
 * Time: 22:15
 */

namespace inhere\webSocket;

/**
 * Interface WSInterface
 * @package inhere\webSocket
 */
interface WSInterface
{
    /**
     * version
     */
    const VERSION = '0.5.1';

    /**
     * Websocket version
     */
    const WS_VERSION = '13';

    const HEADER_END     = "\r\n\r\n";

    const SIGN_KEY = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    // const TOKEN_CHARS = ' abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!"§$%&/()=[]{}';

    const TOKEN_CHARS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!"$&/()=[]{}0123456789';

    // 事件的回调函数名
    const ON_CONNECT   = 'connect';
    const ON_HANDSHAKE = 'handshake';
    const ON_OPEN      = 'open';
    const ON_MESSAGE   = 'message';
    const ON_CLOSE     = 'close';
    const ON_ERROR     = 'error';
    const ON_TICK      = 'tick';

    /**
     * Websocket blob type.
     */
    const BINARY_TYPE_BLOB = "\x81";

    /**
     * Websocket array buffer type.
     */
    const BINARY_TYPE_ARRAY_BUFFER = "\x82";
}
