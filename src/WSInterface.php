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

    const UPDATE_TIME = '2017-04-14';

    /**
     * Websocket version
     */
    const WS_VERSION = '13';

    const HEADER_END     = "\r\n\r\n";

    const SIGN_KEY = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    // const TOKEN_CHARS = ' abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!"§$%&/()=[]{}';

    const TOKEN_CHARS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!"$&/()=[]{}0123456789';

    // internal, see rfc
    const WS_FIN =  128;
    const WS_MASK = 128;

    const OPCODE_CONTINUATION = 0;
    const OPCODE_TEXT         = 1;
    const OPCODE_BINARY       = 2;
    const OPCODE_CLOSE        = 8;
    const OPCODE_PING         = 9;
    const OPCODE_PONG         = 10;

    // websocket 在任何时候都会处于下面4种状态中的其中一种
    const STATE_CONNECTING =  0; // 表示还没建立连接；
    const STATE_OPEN       =  1; // 已经建立连接，可以进行通讯；
    const STATE_CLOSING    =  2; // 通过关闭握手，正在关闭连接；
    const STATE_CLOSED     =  3; // 连接已经关闭或无法打开；

    const STATUS_NORMAL_CLOSE             = 1000;
    const STATUS_GONE_AWAY                = 1001;
    const STATUS_PROTOCOL_ERROR           = 1002;
    const STATUS_UNSUPPORTED_MESSAGE_TYPE = 1003;
    const STATUS_MESSAGE_TOO_BIG          = 1004;
    const STATUS_TIMEOUT                  = 3000;

    // 事件的回调函数名
    const ON_CONNECT   = 'connect';
    const ON_HANDSHAKE = 'handshake';
    // websocket api都至少要提供下面三个事件的api接口
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

    const TIMEOUT_FLOAT = 2.2;

    const TIMEOUT_INT = 3;
}
