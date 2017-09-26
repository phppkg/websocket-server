<?php
/**
 * Created by PhpStorm.
 * User: Inhere
 * Date: 2017/3/26 0026
 * Time: 15:34
 */

namespace Inhere\WebSocket\Module;

use Inhere\Http\Request;
use Inhere\Http\Response;

/**
 * Class EchoModule
 *
 * handle the root '/echo' webSocket request
 *
 * @package Inhere\WebSocket\Module
 */
class EchoModule extends ModuleAbstracter
{
    /**
     * @param Request $request
     * @param Response $response
     */
    public function onHandshake(Request $request, Response $response)
    {
        parent::onHandshake($request, $response);

        $response->setCookie('test', 'test-value');
        $response->setCookie('test1', 'test-value1');
    }

    /**
     * index command
     * the default command
     */
    public function indexCommand()
    {
        $this->respond('hello, welcome to here!');
    }
}
