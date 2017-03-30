<?php
/**
 * Created by PhpStorm.
 * User: Inhere
 * Date: 2017/3/26 0026
 * Time: 15:34
 */

namespace inhere\webSocket\handlers;

use inhere\librarys\http\Request;
use inhere\librarys\http\Response;

/**
 * Class RootHandler
 *
 * handle the root '/' webSocket request
 *
 * @package inhere\webSocket\handlers
 */
class RootHandler extends ARouteHandler
{
    /**
     * @param Request $request
     * @param Response $response
     */
    public function onHandshake(Request $request, Response $response)
    {
        parent::onHandshake($request, $response);

        $response->setCookie('test', 'test-value');
    }

    public function indexCommand()
    {
        $this->getApp()->respond('hello, this is [/index]');
    }
}
