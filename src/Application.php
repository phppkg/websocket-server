<?php
/**
 * Created by PhpStorm.
 * User: Inhere
 * Date: 2017/3/24 0024
 * Time: 23:13
 */

namespace inhere\webSocket;

use inhere\librarys\traits\TraitUseSimpleOption;
use inhere\webSocket\handlers\IRouteHandler;
use inhere\webSocket\handlers\RootHandler;
use inhere\webSocket\parts\MessageResponse;
use inhere\librarys\http\Request;
use inhere\librarys\http\Response;

/**
 * Class Application
 *  webSocket server application
 *
 * 1.
 * ```
 * $app = new Application;
 *
 * // register command handler
 * $app->add('test', function () {
 *
 *     return 'hello';
 * });
 *
 * // start server
 * $app->run();
 * ```
 * 2.
 * ```
 * $app = new Application($host, $port);
 *
 * // register command handler
 * $app->add('test', function () {
 *
 *     return 'hello';
 * });
 *
 * // start server
 * $app->parseOptRun();
 * ```
 */
class Application
{
    use TraitUseSimpleOption;

    // custom ws handler position
    const OPEN_HANDLER = 0;
    const MESSAGE_HANDLER = 1;
    const CLOSE_HANDLER = 2;
    const ERROR_HANDLER = 3;
    // route not found
    const ROUTE_NOT_FOUND = 4;

    const PARSE_ERROR = 'error';

    const OK = 0;

    const DATA_JSON = 'json';
    const DATA_TEXT = 'text';

    /**
     * default is '0.0.0.0'
     * @var string
     */
    private $host;
    /**
     * default is 8080
     * @var int
     */
    private $port;

    /**
     * @var WebSocketServer
     */
    private $ws;

    /**
     * save four custom ws handler
     * @var \SplFixedArray
     */
    private $wsHandlers;

    /**
     * @var array
     */
    protected $options = [
        // request and response data type: json text
        'dataType' => 'json',

        // allowed accessed Origins. e.g: [ 'localhost', 'site.com' ]
        'allowedOrigins' => [],
    ];

    /**
     * @var IRouteHandler[]
     * [
     *  // path => IRouteHandler,
     *  '/'  => RootHandler,
     * ]
     */
    private $routesHandlers;

    /**
     * WebSocketServerHandler constructor.
     * @param string $host
     * @param int $port
     * @param array $options
     * @internal param null|WebSocketServer $ws
     */
    public function __construct(string $host = '0.0.0.0', $port = 8080, array $options = [])
    {
        $this->host = $host ?: '0.0.0.0';
        $this->port = $port ?: 8080;
        $this->wsHandlers = new \SplFixedArray(5);

        $this->setOptions($options);
    }

    /**
     * run
     */
    public function run()
    {
        if (!$this->ws) {
            $this->ws = new WebSocketServer($this->host, $this->port);
        }

        // register events
        $this->ws->on(WebSocketServer::ON_HANDSHAKE, [$this, 'handleHandshake']);
        $this->ws->on(WebSocketServer::ON_OPEN, [$this, 'handleOpen']);
        $this->ws->on(WebSocketServer::ON_MESSAGE, [$this, 'handleMessage']);
        $this->ws->on(WebSocketServer::ON_CLOSE, [$this, 'handleClose']);
        $this->ws->on(WebSocketServer::ON_ERROR, [$this, 'handleError']);

        // if not register route, add root path route handler
        if ( 0 === count($this->routesHandlers) ) {
            $this->route('/', new RootHandler);
        }

        $this->ws->start();
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
     * parse cli Opt and Run
     */
    public function parseOptRun()
    {
        $opts = getopt('p::H::h', ['port::', 'host::', 'help']);

        if ( isset($opts['h']) || isset($opts['help']) ) {
            $help = <<<EOF
Start a webSocket Application Server.  

Options:
  -H,--host  Setting the webSocket server host.(default:9501)
  -p,--port  Setting the webSocket server port.(default:127.0.0.1)
  -h,--help  Show help information
  
EOF;

            fwrite(\STDOUT, $help);
            exit(0);
        }

        $this->host = $opts['H'] ?? $opts['host'] ?? $this->host;
        $this->port = $opts['p'] ?? $opts['port'] ?? $this->port;

        $this->run();
    }

    /**
     * webSocket 只会在连接握手时会有 request, response
     * @param Request   $request
     * @param Response  $response
     * @param int       $id
     * @return bool
     */
    public function handleHandshake(Request $request, Response $response, int $id)
    {
        $this->log('Parsed request data:');
        var_dump($request);

        $path = $request->getPath();

        // check route. if not exists, response 404 error
        if ( !$this->hasRoute($path) ) {
            $this->log("The #$id request's path [$path] route handler not exists.", 'error');

            // call custom route-not-found handler
            if ( $rnfHandler = $this->wsHandlers[self::ROUTE_NOT_FOUND] ) {
                $rnfHandler($id, $path, $this);
            }

            $response
                ->setStatus(404)
                ->setHeaders(['Connection' => 'close'])
                ->setBody("You request route path [$path] not found!");

            return false;
        }

        $response->setHeader('Server', 'websocket-server');

        $handler = $this->routesHandlers[$path];
        $handler->setApp($this);
        $handler->setRequest($request);
        $handler->onHandshake($request, $response);

        return true;
    }

    /**
     * @param WebSocketServer $ws
     * @param Request $request
     * @param int $id
     */
    public function handleOpen(WebSocketServer $ws, Request $request, int $id)
    {
        $this->log('A new user connection. Now, connected user count: ' . $ws->count());
        // $this->log("SERVER Data: \n" . var_export($_SERVER, 1), 'info');

        if ( $openHandler = $this->wsHandlers[self::OPEN_HANDLER] ) {
             $openHandler($this, $request, $id);
        }

        // $path = $ws->getClient($id)['path'];
        $path = $request->getPath();
        $this->getRouteHandler($path)->onOpen($id);
    }

    /**
     * @param WebSocketServer $ws
     * @param string $data
     * @param int $id
     * @param array $client
     */
    public function handleMessage(WebSocketServer $ws, string $data, int $id, array $client)
    {
        $this->log("Received user [$id] sent message. MESSAGE: $data, LENGTH: " . mb_strlen($data));

        // call custom message handler
        if ( $msgHandler = $this->wsHandlers[self::MESSAGE_HANDLER] ) {
            $msgHandler($ws, $this);
        }

        // dispatch command

        // $path = $ws->getClient($id)['path'];
        $result = $this->getRouteHandler($client['path'])->dispatch($data, $id);

        if ( $result && is_string($result) ) {
            $ws->send($result);
        }
    }

    /**
     * @param WebSocketServer $ws
     * @param int $id
     * @param array $client
     */
    public function handleClose(WebSocketServer $ws, int $id, array $client)
    {
        $this->log("The #$id user disconnected. Now, connected user count: " . $ws->count());

        if ( $closeHandler = $this->wsHandlers[self::CLOSE_HANDLER] ) {
            $closeHandler($this, $id, $client);
        }

        $this->getRouteHandler($client['path'])->onClose($id, $client);
    }

    /**
     * @param WebSocketServer $ws
     * @param string $msg
     */
    public function handleError(string $msg, WebSocketServer $ws)
    {
        $this->log('Accepts a connection on a socket error: ' . $msg, 'error');

        if ( $errHandler = $this->wsHandlers[self::ERROR_HANDLER] ) {
            $errHandler($ws, $this);
        }
    }

    /**
     * @param callable $openHandler
     */
    public function onOpen(callable $openHandler)
    {
        $this->wsHandlers[self::OPEN_HANDLER] = $openHandler;
    }

    /**
     * @param callable $closeHandler
     */
    public function onClose(callable $closeHandler)
    {
        $this->wsHandlers[self::CLOSE_HANDLER] = $closeHandler;
    }

    /**
     * @param callable $errorHandler
     */
    public function onError(callable $errorHandler)
    {
        $this->wsHandlers[self::ERROR_HANDLER] = $errorHandler;
    }

    /**
     * @param callable $messageHandler
     */
    public function onMessage(callable $messageHandler)
    {
        $this->wsHandlers[self::MESSAGE_HANDLER] = $messageHandler;
    }

    /////////////////////////////////////////////////////////////////////////////////////////
    /// handle request route
    /////////////////////////////////////////////////////////////////////////////////////////

    /**
     * register a route and it's handler
     * @param string        $path           route path
     * @param IRouteHandler $routeHandler   the route path handler
     * @param bool          $replace        replace exists's route
     * @return IRouteHandler
     */
    public function route(string $path, IRouteHandler $routeHandler, $replace = false)
    {
        $path = trim($path) ?: '/';
        $pattern = '/^\/[a-zA-Z][\w-]+$/';

        if ( $path !== '/' && preg_match($pattern, $path) ) {
            throw new \InvalidArgumentException("The route path format must be match: $pattern");
        }

        if ( $this->hasRoute($path) && !$replace ) {
            throw new \InvalidArgumentException("The route path [$path] have been registered!");
        }

        $this->routesHandlers[$path] = $routeHandler;

        return $routeHandler;
    }

    /**
     * @param $path
     * @return bool
     */
    public function hasRoute(string $path): bool
    {
        return isset($this->routesHandlers[$path]);
    }

    /**
     * @param string $path
     * @return IRouteHandler
     */
    public function getRouteHandler(string $path = '/'): IRouteHandler
    {
        if ( !$this->hasRoute($path) ) {
            throw new \RuntimeException("The route handler not exists for the path: $path");
        }

        return $this->routesHandlers[$path];
    }

    /**
     * @return array
     */
    public function getRoutes(): array
    {
        return array_keys($this->routesHandlers);
    }

    /**
     * @return array
     */
    public function getRoutesHandlers(): array
    {
        return $this->routesHandlers;
    }

    /**
     * @param array $routesHandlers
     */
    public function setRoutesHandlers(array $routesHandlers)
    {
        foreach ($routesHandlers as $route => $handler) {
            $this->route($route, $handler);
        }
    }

    /////////////////////////////////////////////////////////////////////////////////////////
    /// response
    /////////////////////////////////////////////////////////////////////////////////////////

    /**
     * @param string $data
     * @param string $msg
     * @param int $code
     * @return string
     */
    public function fmtJson($data, string $msg = 'success', int $code = 0): string
    {
        return json_encode([
            'data' => $data,
            'msg'  => $msg,
            'code' => (int)$code,
            'time' => time(),
        ]);
    }

    /**
     * @param $data
     * @param string $msg
     * @param int $code
     * @return string
     */
    public function buildMessage($data, string $msg = 'success', int $code = 0)
    {
        // json
        if ( $this->isJsonType() ) {
            $data = $this->fmtJson($data, $msg ?: 'success', $code);

            // text
        } else {
            if ( $data && is_array($data) ) {
                $data = json_encode($data);
            }

            $data = $data ?: $msg;
        }

        return $data;
    }

    /**
     * @param string $data
     * @param int $sender
     * @param array $receivers
     * @param array $excepted
     * @return MessageResponse
     */
    public function makeMR(string $data = '', int $sender = 0, array $receivers = [], array $excepted = []): MessageResponse
    {
        return MessageResponse::make($data, $sender, $receivers, $excepted)->setWs($this->ws);
    }

    /**
     * @param mixed $data
     * @param string $msg
     * @param int $code
     * @param bool $doSend
     * @return int|MessageResponse
     */
    public function respond($data, string $msg = '', int $code = 0, bool $doSend = true)
    {
        $data = $this->buildMessage($data, $msg, $code);
        $mr = MessageResponse::make($data)->setWs($this->ws);

        if ( $doSend ) {
            $mr->send(true);
        }

        return $mr;
    }

    /**
     * @param $data
     * @param string $msg
     * @param int $code
     * @param \Closure|null $afterMakeMR
     * @param bool $reset
     * @return int
     * @internal param MessageResponse $response
     */
    public function send($data, string $msg = '', int $code = 0, \Closure $afterMakeMR = null, bool $reset = true): int
    {
        $data = $this->buildMessage($data, $msg, $code);
        $mr = MessageResponse::make($data)->setWs($this->ws);

        if ( $afterMakeMR ) {
            $status = $afterMakeMR($mr);

            // If the message have bee sent
            if ( is_int($status) ) {
                return $status;
            }
        }

        return $mr->send($reset);
    }

    /////////////////////////////////////////////////////////////////////////////////////////
    /// a very simple's user storage
    /////////////////////////////////////////////////////////////////////////////////////////

    /**
     * @var array
     */
    private $users = [];

    public function getUser($index)
    {
        return $this->users[$index] ?? null;
    }

    public function userLogin($index, $data)
    {

    }

    public function userLogout($index, $data)
    {

    }

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
     * @return WebSocketServer
     */
    public function getWs(): WebSocketServer
    {
        return $this->ws;
    }

    /**
     * @param WebSocketServer $ws
     */
    public function setWs(WebSocketServer $ws)
    {
        $this->ws = $ws;
    }

    /**
     * @return string
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * @return int
     */
    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * @param string $message
     * @param string $type
     * @param array $data
     */
    public function log(string $message, string $type = 'info', array $data = [])
    {
        $date = date('Y-m-d H:i:s');
        $type = strtoupper(trim($type));

        $this->print("[$date] [$type] $message " . ( $data ? json_encode($data) : '' ) );
    }

    /**
     * @param mixed $messages
     * @param bool $nl
     * @param null|int $exit
     */
    public function print($messages, $nl = true, $exit = null)
    {
        $text = is_array($messages) ? implode(($nl ? "\n" : ''), $messages) : $messages;

        fwrite(\STDOUT, $text . ($nl ? "\n" : ''));

        if ( $exit !== null ) {
            exit((int)$exit);
        }
    }
}
