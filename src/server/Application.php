<?php
/**
 * Created by PhpStorm.
 * User: Inhere
 * Date: 2017/3/24 0024
 * Time: 23:13
 */

namespace inhere\webSocket\server;

use inhere\console\io\Input;
use inhere\console\io\Output;
use inhere\library\traits\TraitSimpleOption;
use inhere\webSocket\server\handlers\IRouteHandler;
use inhere\webSocket\server\handlers\RootHandler;
use inhere\webSocket\parts\MessageBag;
use inhere\webSocket\http\Request;
use inhere\webSocket\http\Response;
use inhere\webSocket\WSInterface;

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
    use TraitSimpleOption;

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
     * @var Output
     */
    protected $cliOut;

    /**
     * @var Input
     */
    protected $cliIn;

    /**
     * @var ServerInterface
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
        'debug' => false,
        'driver' => '', // allow: sockets, swoole, streams

        // request and response data type: json text
        'dataType' => 'json',

        // allowed accessed Origins. e.g: [ 'localhost', 'site.com' ]
        'allowedOrigins' => '*',

        // server options
        'server' => [

        ]
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
     * Application constructor.
     * @param string $host
     * @param int $port
     * @param array $options
     * @internal param null|ServerInterface $ws
     */
    public function __construct(string $host = '0.0.0.0', $port = 8080, array $options = [])
    {
        $this->host = $host ?: '0.0.0.0';
        $this->port = $port ?: 8080;
        $this->wsHandlers = new \SplFixedArray(5);

        $this->cliIn = new Input();
        $this->cliOut = new Output();

        $this->setOptions($options, true);

        $opts = $this->getOption('server', []);
        $opts['debug'] = $this->getOption('debug', false);
        $opts['driver'] = $this->getOption('driver'); // allow: sockets, swoole, streams

        $this->ws = ServerFactory::make($this->host, $this->port, $opts);

        // override ws's `cliIn` `cliOut`
        $this->ws->setCliIn($this->cliIn);
        $this->ws->setCliOut($this->cliOut);
    }

    /**
     * run
     */
    public function run()
    {
        // register server events
        $this->ws->on(WSInterface::ON_HANDSHAKE, [$this, 'handleHandshake']);
        $this->ws->on(WSInterface::ON_OPEN, [$this, 'handleOpen']);
        $this->ws->on(WSInterface::ON_MESSAGE, [$this, 'handleMessage']);
        $this->ws->on(WSInterface::ON_CLOSE, [$this, 'handleClose']);
        $this->ws->on(WSInterface::ON_ERROR, [$this, 'handleError']);

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
        $opts = getopt('p::H::h', ['port::', 'host::', 'driver:', 'help']);

        if ( isset($opts['h']) || isset($opts['help']) ) {
            $help = <<<EOF
Start a webSocket Application Server.  

Options:
  -H,--host  Setting the webSocket server host.(default:9501)
  -p,--port  Setting the webSocket server port.(default:127.0.0.1)
  --driver   You can custom server driver. allow: swoole, sockets, streams.
  -h,--help  Show help information
  
EOF;

            fwrite(\STDOUT, $help);
            exit(0);
        }

        $this->host = $opts['H'] ?? $opts['host'] ?? $this->host;
        $this->port = $opts['p'] ?? $opts['port'] ?? $this->port;
        $this->options['driver'] = $opts['driver'] ?? '';

        $this->run();
    }

    /**
     * webSocket 只会在连接握手时会有 request, response
     * @param Request   $request
     * @param Response  $response
     * @param int       $cid
     * @return bool
     */
    public function handleHandshake(Request $request, Response $response, int $cid)
    {
        $path = $request->getPath();

        // check route. if not exists, response 404 error
        if ( !$this->hasRoute($path) ) {
            $this->log("The #$cid request's path [$path] route handler not exists.", 'error');

            // call custom route-not-found handler
            if ( $rnfHandler = $this->wsHandlers[self::ROUTE_NOT_FOUND] ) {
                $rnfHandler($cid, $path, $this);
            }

            $response
                ->setStatus(404)
                ->setHeaders(['Connection' => 'close'])
                ->setBody("You request route path [$path] not found!");

            return false;
        }

        $origin = $request->getOrigin();
        $handler = $this->routesHandlers[$path];

        // check `Origin`
        // Access-Control-Allow-Origin: *
        if ( !$handler->checkIsAllowedOrigin($origin) ) {
            $this->log("The #$cid Origin [$origin] is not in the 'allowedOrigins' list.", 'error');

            $response
                ->setStatus(403)
                ->setHeaders(['Connection' => 'close'])
                ->setBody('Deny Access!');

            return false;
        }

        // application/json
        // text/plain
        $response->setHeader('Server', 'websocket-server');
        // $response->setHeader('Access-Control-Allow-Origin', '*');

        $handler->setApp($this);
        $handler->setRequest($request);
        $handler->onHandshake($request, $response);

        return true;
    }

    /**
     * @param ServerInterface $ws
     * @param Request $request
     * @param int $cid
     */
    public function handleOpen(ServerInterface $ws, Request $request, int $cid)
    {
        $this->log('A new user connection. Now, connected user count: ' . $ws->count());
        // $this->log("SERVER Data: \n" . var_export($_SERVER, 1), 'info');

        if ( $openHandler = $this->wsHandlers[self::OPEN_HANDLER] ) {
             $openHandler($this, $request, $cid);
        }

        // $path = $ws->getClient($cid)['path'];
        $path = $request->getPath();
        $this->getRouteHandler($path)->onOpen($cid);
    }

    /**
     * @param ServerInterface $ws
     * @param string $data
     * @param int $cid
     * @param array $client
     */
    public function handleMessage(ServerInterface $ws, string $data, int $cid, array $client)
    {
        $this->log("Received user #$cid sent message. MESSAGE: $data, LENGTH: " . mb_strlen($data));

        // call custom message handler
        if ( $msgHandler = $this->wsHandlers[self::MESSAGE_HANDLER] ) {
            $msgHandler($ws, $this);
        }

        // dispatch command

        // $path = $ws->getClient($cid)['path'];
        $result = $this->getRouteHandler($client['path'])->dispatch($data, $cid);

        if ( $result && is_string($result) ) {
            $ws->send($result);
        }
    }

    /**
     * @param ServerInterface $ws
     * @param int $cid
     * @param array $client
     */
    public function handleClose(ServerInterface $ws, int $cid, array $client)
    {
        $this->log("The #$cid user disconnected. Now, connected user count: " . $ws->count());

        if ( $closeHandler = $this->wsHandlers[self::CLOSE_HANDLER] ) {
            $closeHandler($this, $cid, $client);
        }

        $this->getRouteHandler($client['path'])->onClose($cid, $client);
    }

    /**
     * @param ServerInterface $ws
     * @param string $msg
     */
    public function handleError(string $msg, ServerInterface $ws)
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
     * response data to client, will auto build formatted message by 'dataType'
     * @param mixed $data
     * @param string $msg
     * @param int $code
     * @param bool $doSend
     * @return int|MessageBag
     */
    public function respond($data, string $msg = '', int $code = 0, bool $doSend = true)
    {
        $data = $this->buildMessage($data, $msg, $code);

        return $this->respondText($data, $doSend);
    }

    /**
     * response text data to client
     * @param $data
     * @param bool $doSend
     * @return int|MessageBag
     */
    public function respondText($data, bool $doSend = true)
    {
        if ( is_array($data) ) {
            $data = implode('', $data);
        }

        $mr = MessageBag::make($data)->setWs($this->ws);

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
     */
    public function send($data, string $msg = '', int $code = 0, \Closure $afterMakeMR = null, bool $reset = true): int
    {
        $data = $this->buildMessage($data, $msg, $code);

        return $this->sendText($data, $afterMakeMR, $reset);
    }

    /**
     * response text data to client
     * @param $data
     * @param \Closure|null $afterMakeMR
     * @param bool $reset
     * @return int
     */
    public function sendText($data, \Closure $afterMakeMR = null, bool $reset = true)
    {
        if ( is_array($data) ) {
            $data = implode('', $data);
        }

        $mr = MessageBag::make($data)->setWs($this->ws);

        if ( $afterMakeMR ) {
            $status = $afterMakeMR($mr);

            // If the message have been sent
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
     * @return ServerInterface
     */
    public function getWs(): ServerInterface
    {
        return $this->ws;
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
