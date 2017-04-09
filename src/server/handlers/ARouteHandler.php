<?php
/**
 * Created by PhpStorm.
 * User: Inhere
 * Date: 2017/3/27 0027
 * Time: 22:51
 */

namespace inhere\webSocket\server\handlers;

use inhere\library\traits\TraitSimpleOption;
use inhere\webSocket\server\Application;
use inhere\webSocket\server\dataParser\ComplexDataParser;
use inhere\webSocket\server\dataParser\IDataParser;
use inhere\webSocket\http\Request;
use inhere\webSocket\http\Response;
use inhere\webSocket\parts\MessageBag;

/**
 * Class ARouteHandler
 * @package inhere\webSocket\server\handlers
 */
abstract class ARouteHandler implements IRouteHandler
{
    use TraitSimpleOption;

    // custom ws handler position
    const OPEN_HANDLER = 0;
    const MESSAGE_HANDLER = 1;
    const CLOSE_HANDLER = 2;
    const ERROR_HANDLER = 3;

    /**
     * @var Application
     */
    private $app;

    /**
     * @var Request
     */
    private $request;

    /**
     * @var IDataParser
     */
    private $_dataParser;

    /**
     * @var array
     * [
     *   cmd => callback,
     * ]
     */
    protected $cmdHandlers = [];

    // default command name, if request data not define command name.
    const DEFAULT_CMD = 'index';
    const DEFAULT_CMD_SUFFIX = 'Command';

    const DENY_ALL = '!';
    const ALLOW_ALL = '*';

    /**
     * @var array
     */
    protected $options = [
        // request and response data type: json text
        'dataType' => 'json',

        // It is valid when `'dataType' => 'json'`, allow: 1 raw 2 array 3 object
        'jsonParseTo'    => IDataParser::JSON_TO_ARRAY,

        // default command name, if request data not define command name.
        'defaultCmd'     => self::DEFAULT_CMD,
        // default command suffix
        'cmdSuffix'     => self::DEFAULT_CMD_SUFFIX,

        // allowed request Origins. e.g: [ 'localhost', 'site.com' ]
        'allowedOrigins' => '*',
    ];

    /**
     * ARouteHandler constructor.
     * @param array $options
     * @param IDataParser|null $dataParser
     */
    public function __construct(array $options = [], IDataParser $dataParser = null)
    {
        $this->setOptions($options, true);

        $this->_dataParser = $dataParser;
    }

    /**
     * @inheritdoc
     */
    public function onHandshake(Request $request, Response $response)
    {
        $this->log('A new user connection. join the path(route): ' . $request->getPath());
    }

    /**
     * @inheritdoc
     */
    public function onOpen(int $cid)
    {
        $this->log('A new user open connection. route path: ' . $this->request->getPath());
    }

    /**
     * @inheritdoc
     */
    public function onClose(int $cid, array $client)
    {
        $this->log('A user has been disconnected. Path: ' . $client['path']);
    }

    /**
     * @inheritdoc
     */
    public function onError(Application $app, string $msg)
    {
        $this->log('Accepts a connection on a socket error, when request : ' . $msg, 'error');
    }

    /////////////////////////////////////////////////////////////////////////////////////////
    /// handle request command
    /////////////////////////////////////////////////////////////////////////////////////////

    /**
     * check client is allowed origin
     * `Origin: http://foo.example`
     * @param string $from\
     * @return bool
     */
    public function checkIsAllowedOrigin(string $from)
    {
        $allowed = $this->getOption('allowedOrigins');

        // deny all
        if ( !$allowed ) {
            return false;
        }

        // allow all
        if ( is_string($allowed) && $allowed === self::ALLOW_ALL ) {
            return true;
        }

        if ( !$from ) {
            return false;
        }

        $allowed = (array)$allowed;

        return true;
    }

    /**
     * parse and dispatch command
     * @param string $data
     * @param int $cid
     * @return mixed
     */
    public function dispatch(string $data, int $cid)
    {
        $route = $this->request->getPath();

        // parse: get command and real data
        if ( $results = $this->getDataParser()->parse($data, $cid, $this) ) {
            [$command, $data] = $results;
            $command = $command ?: $this->getOption('defaultCmd') ?? self::DEFAULT_CMD;
            $this->log("The #{$cid} request command is: $command, in route: $route, handler: " . static::class);
        } else {
            $command = self::PARSE_ERROR;
            $this->log("The #{$cid} request data parse failed in route: $route. Data: $data", 'error');
        }

        // dispatch command

        // is a outside command `by add()`
        if ( $this->isCommandName($command) ) {
            $handler = $this->getCmdHandler($command);
            return call_user_func_array($handler, [$data, $cid, $this]);
        }

        $suffix = 'Command';
        $method = $command . $suffix;

        // not found
        if ( !method_exists( $this, $method) ) {
            $this->log("The #{$cid} request command: $command not found, run 'notFound' command", 'notice');
            $method = self::NOT_FOUND . $suffix;
        }

        return $this->$method($data, $cid);
    }

    /**
     * register a command handler
     * @param string $command
     * @param callable $handler
     * @return IRouteHandler
     */
    public function command(string $command, callable $handler)
    {
        return $this->add($command, $handler);
    }
    public function add(string $command, $handler)
    {
        if ( $command && preg_match('/^[a-z][\w-]+$/', $command)) {
            $this->cmdHandlers[$command] = $handler;
        }

        return $this;
    }

    /**
     * @param $data
     * @param int $cid
     * @return int
     */
    public function pingCommand(string $data, int $cid)
    {
        return $this->respondText($data . '+PONG', false)->to($cid)->send();
    }

    /**
     * @param $data
     * @param int $cid
     * @return int
     */
    public function errorCommand(string $data, int $cid)
    {
        return $this
            ->respond($data, 'you send data format is error!', -200, false)
            ->to($cid)
            ->send();
    }

    /**
     * @param string $command
     * @param int $cid
     * @return int
     */
    public function notFoundCommand(string $command, int $cid)
    {
        $msg = "You request command [$command] not found in the route [{$this->request->getPath()}].";

        return $this->respond('', $msg, -404, false)->to($cid)->send();
    }

    /**
     * @param string $command
     * @return bool
     */
    public function isCommandName(string $command): bool
    {
        return array_key_exists($command, $this->cmdHandlers);
    }

    /**
     * @return array
     */
    public function getCommands(): array
    {
        return array_keys($this->cmdHandlers);
    }

    /**
     * @param string $command
     * @return callable|null
     */
    public function getCmdHandler(string $command)//: ?callable
    {
        if ( !$this->isCommandName($command) ) {
            return null;
        }

        return $this->cmdHandlers[$command];
    }

    /**
     * @return array
     */
    public function getCmdHandlers(): array
    {
        return $this->cmdHandlers;
    }

    /////////////////////////////////////////////////////////////////////////////////////////
    /// helper method
    /////////////////////////////////////////////////////////////////////////////////////////

    /**
     * @param $data
     * @param string $msg
     * @param int $code
     * @param bool $doSend
     * @return int|MessageBag
     */
    public function respond($data, string $msg = 'success', int $code = 0, bool $doSend = true)
    {
        return $this->app->respond($data, $msg, $code, $doSend);
    }

    /**
     * @param $data
     * @param bool $doSend
     * @return MessageBag|int
     */
    public function respondText($data, bool $doSend = true)
    {
        return $this->app->respondText($data, $doSend);
    }

    public function send($data, string $msg = '', int $code = 0, \Closure $afterMakeMR = null, bool $reset = true): int
    {
        return $this->app->send($data, $msg, $code, $afterMakeMR, $reset);
    }

    public function sendText($data, \Closure $afterMakeMR = null, bool $reset = true)
    {
        return $this->app->sendText($data, $afterMakeMR, $reset);
    }

    public function log(string $message, string $type = 'info', array $data = [])
    {
        $this->app->log($message, $type, $data);
    }

    /////////////////////////////////////////////////////////////////////////////////////////
    /// getter/setter method
    /////////////////////////////////////////////////////////////////////////////////////////

    /**
     * @return bool
     */
    public function isJsonType(): bool
    {
        return $this->getOption('dataType') === self::DATA_JSON;
    }

    /**
     * @return string
     */
    public function getDataType(): string
    {
        return $this->getOption('dataType');
    }

    /**
     * @return IDataParser
     */
    public function getDataParser(): IDataParser
    {
        // if not set, use default parser.
        return $this->_dataParser ?: new ComplexDataParser();
    }

    /**
     * @param IDataParser $dataParser
     */
    public function setDataParser(IDataParser $dataParser)
    {
        $this->_dataParser = $dataParser;
    }

    /**
     * @return Application
     */
    public function getApp(): Application
    {
        return $this->app;
    }

    /**
     * @param Application $app
     */
    public function setApp(Application $app)
    {
        $this->app = $app;
    }

    /**
     * @return Request
     */
    public function getRequest(): Request
    {
        return $this->request;
    }

    /**
     * @param Request $request
     */
    public function setRequest(Request $request)
    {
        $this->request = $request;
    }
}
