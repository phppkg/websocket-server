<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-03-27
 * Time: 9:14
 */
namespace inhere\webSocket\client;

/**
 * Class WebSocketClient
 * @package inhere\webSocket\client
 */
final class ClientFactory
{
    /**
     * version
     */
    const VERSION = '0.5.1';

    const TOKEN_LENGTH = 16;
    const MSG_CONNECTED = 1;
    const MSG_DISCONNECTED = 2;
    const MSG_LOST_CONNECTION = 3;

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

    /**
     * @var array
     */
    protected static $availableDrivers = [
        'swoole' => SwooleClient::class,
        'sockets' => SocketsClient::class,
        'streams' => StreamsClient::class,
    ];

    /**
     * make a client
     * @param string $url
     * @param array $options
     * @return ClientInterface
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

        /** @var ClientInterface $driverClass */
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

    /**
     * parse cli Opt and make
     * eg:
     *  examples/base_server --driver sockets -d
     * @param array $options
     * @return ServerInterface
     */
    public static function parseOptMake(array $options = [])
    {
        $opts = getopt('dh', ['url:', 'driver:', 'help', 'debug']);

        if ( isset($opts['h']) || isset($opts['help']) ) {
            $help = <<<EOF
Start a webSocket Server.

Options:
  -d         Run the server on the background.
  --url      Setting the webSocket server url.(default ws://127.0.0.1:9501)
  --debug    Run the server on the debug mode.
  --driver   You can custom server driver. allow: swoole, sockets, streams.
  -h,--help  Show help information

EOF;

            fwrite(\STDOUT, $help);
            exit(0);
        }

        $url = $opts['url'] ?? 'ws://127.0.0.1:9501';
        $options['driver'] = $opts['driver'] ?? $options['driver'] ?? '';
        $options['debug'] = $opts['debug'] ?? $options['debug'] ?? false;

        return self::make($url, $options);
    }
}
