<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-03-27
 * Time: 9:14
 */
namespace inhere\webSocket\client;

use inhere\exceptions\ConnectException;
use inhere\webSocket\BaseWebSocket;
use inhere\webSocket\client\drivers\IClientDriver;
use inhere\webSocket\client\drivers\SocketsDriver;
use inhere\webSocket\client\drivers\StreamsDriver;
use inhere\webSocket\client\drivers\SwooleDriver;
use inhere\webSocket\http\Request;
use inhere\webSocket\http\Response;
use inhere\webSocket\parts\Uri;

/**
 * Class WebSocketClient
 * @package inhere\webSocket
 */
class ClientFactory
{
    /**
     * version
     */
    const VERSION = '0.5.1';

    const TOKEN_LENGTH = 16;
    const MSG_CONNECTED = 1;
    const MSG_DISCONNECTED = 2;
    const MSG_LOST_CONNECTION = 3;

    const ON_TICK = 'tick';

    const OPCODE_CONTINUE = 0x0;
    const OPCODE_TEXT = 0x1;
    const OPCODE_BINARY = 0x2;
    const OPCODE_NON_CONTROL_RESERVED_1 = 0x3;
    const OPCODE_NON_CONTROL_RESERVED_2 = 0x4;
    const OPCODE_NON_CONTROL_RESERVED_3 = 0x5;
    const OPCODE_NON_CONTROL_RESERVED_4 = 0x6;
    const OPCODE_NON_CONTROL_RESERVED_5 = 0x7;
    const OPCODE_CLOSE = 0x8;
    const OPCODE_PING = 0x9;
    const OPCODE_PONG = 0xA;
    const OPCODE_CONTROL_RESERVED_1 = 0xB;
    const OPCODE_CONTROL_RESERVED_2 = 0xC;
    const OPCODE_CONTROL_RESERVED_3 = 0xD;
    const OPCODE_CONTROL_RESERVED_4 = 0xE;
    const OPCODE_CONTROL_RESERVED_5 = 0xF;

    const DEFAULT_HOST = '127.0.0.1';

    /**
     * @var array
     */
    protected static $availableDrivers = [
        'swoole' => SwooleDriver::class,
        'sockets' => SocketsDriver::class,
        'streams' => StreamsDriver::class,
    ];

    /**
     * @param string $url
     * @param array $options
     * @return IClientDriver
     */
    public static function make(string $url, array $options = [])
    {
        $driver = '';

        // if defined the driver name
        if (isset($options['driver'])) {
            $driver = $options['driver'];
            unset($options['driver']);
        }

        if ($driverClass = self::$availableDrivers[$driver] ?? '') {
            return new $driverClass($url, $options);
        }

        // auto choice
        $client = null;
        $names = [];

        /** @var IClientDriver $driverClass */
        foreach (self::$availableDrivers as $name => $driverClass) {
            $names[] = $name;

            if ($driverClass::isSupported()) {
                $client = new $driverClass($url, $options);
                break;
            }
        }

        if (!$client) {
            $nameStr = implode(',', $names);

            throw new \RuntimeException("You system [$nameStr] is not available. please install relative extension.");
        }

        return $client;
    }
}
