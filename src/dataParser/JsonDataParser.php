<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-03-27
 * Time: 9:27
 */

namespace inhere\webSocket\dataParser;

use inhere\webSocket\Application;

/**
 * Class JsonDataParser
 * @package inhere\webSocket\dataParser
 */
class JsonDataParser implements IDataParser
{
    // default cmd key in the request json data.
    const DEFAULT_CMD_KEY = 'cmd';

    /**
     * @var string
     */
    public $cmdKey = 'cmd';

    /**
     * @param string $data
     * @param int $index
     * @param Application $app
     * @return array|false
     */
    public function parse(string $data, int $index, Application $app)
    {
        // json parser
        // format: {"cmd": "value", ... ...}
        // eg: {"cmd": "login", "name":"john","pwd":123456}
        $temp = $data;
        $command = '';
        $to = $app->getOption('jsonParseTo') ?: self::JSON_TO_RAW;
        $cmdKey = $this->cmdKey ?: self::DEFAULT_CMD_KEY;

        $app->log("The #{$index} request command: $command, data: $data");

        $data = json_decode(trim($data), $toAssoc = $to === self::JSON_TO_ARRAY);

        // parse error
        if (json_last_error() > 0) {
            $errMsg = json_last_error_msg();

            $app->log("The #{$index} request data parse to json failed! MSG: $errMsg Data: {$temp}", 'error');

            return false;
        }

        if ($toAssoc) {
            if ( isset($data[$cmdKey]) && $data[$cmdKey]) {
                $command = $data[$cmdKey];
                unset($data[$cmdKey]);
            }
        } elseif ($to === self::JSON_TO_OBJECT) {
            if ( isset($data->{$cmdKey}) && $data->{$cmdKey}) {
                $command = $data->{$cmdKey};
                unset($data->{$cmdKey});
            }
        } else {
            // revert
            $data = $temp;
        }

        unset($temp);
        return [$command, $data];
    }
}