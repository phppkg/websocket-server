<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-03-27
 * Time: 9:27
 */

namespace inhere\webSocket\server\dataParser;

use inhere\webSocket\server\handlers\IRouteHandler;

/**
 * Class ComplexDataParser
 * @package inhere\webSocket\server\dataParser
 */
class ComplexDataParser implements IDataParser
{
    /**
     * @param string $data
     * @param int $index
     * @param IRouteHandler $handler
     * @return array|false
     */
    public function parse(string $data, int $index, IRouteHandler $handler)
    {
        // default format: [@command]data
        // eg:
        // [@test]hello
        // [@login]{"name":"john","pwd":123456}

        $command = '';

        if (preg_match('/^\[@([\w-]+)\](.+)/', $data, $matches)) {
            array_shift($matches);
            [$command, $realData] = $matches;

            // access default command
        } else {
            $realData = $data;
        }

        $to = $handler->getOption('jsonParseTo') ?: self::JSON_TO_RAW;
        $handler->log("The #{$index} request Command: $command, To-format: $to, Data: $realData");

        if ($handler->isJsonType() && $to !== self::JSON_TO_RAW) {
            $realData = json_decode(trim($realData), $to === self::JSON_TO_ARRAY);

            // parse error
            if (json_last_error() > 0) {
                $errMsg = json_last_error_msg();

                $handler->log("Request data parse to json failed! Error: {$errMsg}, Data: {$realData}", 'error');

                return false;
            }
        }

        return [$command, $realData];
    }
}
