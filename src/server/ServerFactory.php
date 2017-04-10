<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-03-27
 * Time: 9:14
 */
namespace inhere\webSocket\server;

/**
 * Class ServerFactory
 * @package inhere\webSocket\server
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
    protected static $availableDrivers = [
        'swoole' => SwooleServer::class,
        'sockets' => SocketsServer::class,
        'streams' => StreamsServer::class,
    ];

    /**
     * make a client
     * @param string $host
     * @param int $port
     * @param array $options
     * @return ServerInterface
     */
    public static function make(string $host = '0.0.0.0', int $port = 8080, array $options = [])
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
}
