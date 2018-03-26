<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-03-27
 * Time: 9:14
 */

namespace Inhere\WebSocket\Server;

/**
 * Class ServerFactory
 * @package Inhere\WebSocket\Server
 */
final class ServerFactory
{
    /**
     * version
     */
    const VERSION = '0.5.1';

    /**
     * @var array
     */
    private static $availableDrivers = [
        'swoole' => SwooleServer::class,
        'sockets' => SocketsServer::class,
        'streams' => StreamsServer::class,
    ];

    /**
     * @param string $name
     * @param string $driverClass
     */
    public static function registerDriver(string $name, string $driverClass)
    {
        if (!is_subclass_of($driverClass, ServerInterface::class)) {
            throw new \InvalidArgumentException("The server driver class must be subclass of ServerInterface. You want register: $driverClass");
        }

        self::$availableDrivers[$name] = $driverClass;
    }

    /**
     * make a client
     * @param string $host
     * @param int $port
     * @param array $options
     * @return ServerInterface
     */
    public static function make(string $host = '0.0.0.0', int $port = 8080, array $options = []): ServerInterface
    {
        $driver = '';

        // if defined the driver name
        if (isset($options['driver'])) {
            $driver = $options['driver'];
            unset($options['driver']);
        }

        if ($driverClass = self::$availableDrivers[$driver] ?? '') {
            return new $driverClass($host, $port, $options);
        }

        // auto choice
        $client = null;
        $names = [];

        /** @var ServerInterface $driverClass */
        foreach (self::$availableDrivers as $name => $driverClass) {
            $names[] = $name;

            if ($driverClass::isSupported()) {
                $client = new $driverClass($host, $port, $options);
                break;
            }
        }

        if (!$client) {
            $nameStr = implode(',', $names);

            throw new \RuntimeException("You system [$nameStr] is not available. please install relative extension.");
        }

        return $client;
    }

    /*
        getopt($options, $longOpts)
        options 可能包含了以下元素：
        - 单独的字符（不接受值）
        - 后面跟随冒号的字符（此选项需要值）
        - 后面跟随两个冒号的字符（此选项的值可选）

        ```
        $shortOpts = "f:";  // Required value
        $shortOpts .= "v::"; // Optional value
        $shortOpts .= "abc"; // These options do not accept values
        $longOpts  = array(
            "required:",     // Required value
            "optional::",    // Optional value
            "option",        // No value
            "opt",           // No value
        );
        $options = getopt($shortOpts, $longOpts);
        ```
        */
    /**
     * parse cli Opt and make
     * eg:
     *  examples/base_server --driver sockets -d
     * @param array $options
     * @return ServerInterface
     */
    public static function parseOptMake(array $options = []): ServerInterface
    {
        $opts = getopt('p::H::dh', ['port::', 'host::', 'driver:', 'help', 'debug']);

        if (isset($opts['h']) || isset($opts['help'])) {
            $help = <<<EOF
Start a webSocket Server.

Options:
  -d         Run the server on the background.
  --debug    You can custom server driver. allow: swoole, sockets, streams.
  --driver   You can custom server driver. allow: swoole, sockets, streams.
  -H,--host  Setting the webSocket server host.(default:9501)
  -p,--port  Setting the webSocket server port.(default:127.0.0.1)
  -h,--help  Show help information

EOF;

            fwrite(\STDOUT, $help);
            exit(0);
        }

        $host = $opts['H'] ?? $opts['host'] ?? '0.0.0.0';
        $port = $opts['p'] ?? $opts['port'] ?? 9501;
        $options['driver'] = $opts['driver'] ?? $options['driver'] ?? '';
        $options['debug'] = $opts['debug'] ?? $options['debug'] ?? false;

        return self::make($host, $port, $options);
    }

}
