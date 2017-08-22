<?php
/**
 * Created by PhpStorm.
 * User: Inhere
 * Date: 2017/3/26 0026
 * Time: 15:35
 */

namespace inhere\webSocket\server\dataParser;

use inhere\webSocket\module\ModuleInterface;

/**
 * Interface DataParserInterface
 * @package inhere\webSocket\server\dataParser
 *
 */
interface DataParserInterface
{
    //
    const JSON_TO_RAW = 1;
    const JSON_TO_ARRAY = 2;
    const JSON_TO_OBJECT = 3;

    /**
     * @param string $data
     * @param int $index
     * @param ModuleInterface $module
     * @return array|false
     */
    public function parse(string $data, int $index, ModuleInterface $module);
}
