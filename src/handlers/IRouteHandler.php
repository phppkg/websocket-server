<?php
/**
 * Created by PhpStorm.
 * User: Inhere
 * Date: 2017/3/26 0026
 * Time: 15:35
 */

namespace inhere\webSocket\handlers;

use inhere\webSocket\Application;
use inhere\webSocket\parts\Request;
use inhere\webSocket\parts\Response;

/**
 * Interface IRouteHandler
 * @package inhere\webSocket\handlers
 */
interface IRouteHandler
{
    const PING = 'ping';
    const NOT_FOUND = 'notFound';
    const PARSE_ERROR = 'error';

    const DATA_JSON = 'json';
    const DATA_TEXT = 'text';

    /**
     * @param Request $request
     * @param Response $response
     */
    public function onHandshake(Request $request, Response $response);

    /**
     * @param int $id
     */
    public function onOpen(int $id);

    /**
     * @param int $id
     * @param array $client
     * @return
     */
    public function onClose(int $id, array $client);

    /**
     * @param Application $app
     * @param string $msg
     */
    public function onError(Application $app, string $msg);

    /**
     * @param string $data
     * @param int $id
     * @return mixed
     */
    public function dispatch(string $data, int $id);

    /**
     * @param string $command
     * @param $handler
     * @return static
     */
    public function add(string $command, $handler);

    public function log(string $message, string $type = 'info', array $data = []);

    public function isJsonType();

    /**
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getOption(string $key, $default = null);

    /**
     * @param Application $app
     */
    public function setApp(Application $app);

    /**
     * @return Application
     */
    public function getApp(): Application;

    /**
     * @return Request
     */
    public function getRequest(): Request;

    /**
     * @param Request $request
     */
    public function setRequest(Request $request);
}
