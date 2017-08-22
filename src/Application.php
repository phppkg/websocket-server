<?php
/**
 * Created by PhpStorm.
 * User: Inhere
 * Date: 2017/3/24 0024
 * Time: 23:13
 */

namespace inhere\webSocket;

use inhere\console\io\Input;
use inhere\console\io\Output;
use inhere\library\helpers\PhpHelper;
use inhere\library\helpers\ProcessHelper;
use inhere\library\traits\EventTrait;
use inhere\library\traits\OptionsTrait;
use inhere\library\log\FileLogger;
use inhere\webSocket\module\ModuleInterface;
use inhere\webSocket\module\RootModule;
use inhere\webSocket\http\WSResponse;
use inhere\webSocket\http\Request;
use inhere\webSocket\http\Response;
use inhere\webSocket\server\ClientMetadata;
use inhere\webSocket\server\ServerAbstracter;
use inhere\webSocket\server\ServerFactory;
use inhere\webSocket\server\ServerInterface;

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
    use OptionsTrait;
    use EventTrait;

    // some events
    const ON_WS_CONNECT = 'wsConnect';
    const ON_WS_OPEN = 'wsOpen';
    const ON_WS_MESSAGE = 'wsMessage';
    const ON_WS_CLOSE = 'wsClose';
    const ON_WS_ERROR = 'wsError';
    const ON_NO_MODULE = 'noModule';
    const ON_PARSE_ERROR = 'parseError';

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
     * @var FileLogger
     */
    private $logger;

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
        'daemon' => false,
        'driver' => '', // allow: sockets, swoole, streams. if not set, will auto select.

        // pid file
        'pid_file' => './tmp/ws_server.pid',

        // request and response data type: json text
        'data_type' => 'json',

        // allowed accessed Origins. e.g: [ 'localhost', 'site.com' ]
        'allowedOrigins' => '*',

        // 日志配置
        'log_service' => [
            'name' => 'ws_app_log',
            'basePath' => './tmp/logs/app',
            'logConsole' => false,
            'logThreshold' => 0,
        ],

        // server options
        'server' => [

        ]
    ];

    /**
     * @var ModuleInterface[]
     * [
     *  // path => ModuleInterface,
     *  '/'  => RootHandler,
     * ]
     */
    private $modules;

    /**
     * Application constructor.
     * @param string $host
     * @param int $port
     * @param array $options
     * @internal param null|ServerInterface $ws
     */
    public function __construct(string $host = '0.0.0.0', $port = 8080, array $options = [])
    {
        $this->host = $host;
        $this->port = $port;
        $this->wsHandlers = new \SplFixedArray(5);

        $this->cliIn = new Input();
        $this->cliOut = new Output();

        $this->setOptions($options);

        $this->init();
    }

    protected function init()
    {
        // create log service instance
        if ($config = $this->getOption('log_service')) {
            $this->logger = FileLogger::make($config);
        }
    }

    /**
     * run
     */
    public function run()
    {
        // handle input command
        $this->handleCliCommand();

    }

    /**
     * Handle Command
     * e.g
     *     `php bin/test_server.php start -d`
     * @return bool
     */
    protected function handleCliCommand()
    {
        $command = $this->cliIn->getCommand(); // e.g 'start'
        $this->checkInputCommand($command);

        $masterPid = 0;
        $masterIsStarted = false;

        if (!PhpHelper::isWin()) {
            $masterPid = ProcessHelper::getPidFromFile($this->getPidFIle());
            $masterIsStarted = ($masterPid > 0) && @posix_kill($masterPid, 0);
        }

        // start: do Start Server
        if ($command === 'start') {
            // check master process is running
            if ($masterIsStarted) {
                $this->cliOut->error("The ws application server have been started. (PID:{$masterPid})", true);
            }

            // run as daemon
            $asDaemon = $this->cliIn->boolOpt('d', $this->isDaemon());
            $this->setOption('daemon', $asDaemon);

            return true;
        }

        // check master process
        if (!$masterIsStarted) {
            $this->cliOut->error('The websocket server is not running.', true);
        }

        // switch command
        switch ($command) {
            case 'stop':
            case 'restart':
                // stop: stop and exit. restart: stop and start
                //$this->doStopServer($masterPid, $command === 'stop');
                break;
            case 'reload':
                //$this->doReloadWorkers($masterPid, $this->cliIn->boolOpt('task'));
                break;
            case 'info':
                //$this->showInformation();
                exit(0);
                break;
            case 'status':
                //$this->showRuntimeStatus();
                break;
            default:
                $this->cliOut->error("The command [{$command}] is don't supported!");
                $this->showHelpInfo();
                break;
        }

        return true;
    }

    /**
     * prepare server instance
     */
    protected function prepareServer()
    {
        $opts = $this->getOption('server', []);

        // append some options
        $opts['debug'] = $this->cliIn->lBoolOpt('debug', $this->getOption('debug', false));
        $opts['driver'] = $this->cliIn->lOpt('driver') ?: $this->getOption('driver');

        $this->ws = ServerFactory::make($this->host, $this->port, $opts);

        // override ws's `cliIn` `cliOut`
        $this->ws->setCliIn($this->cliIn);
        $this->ws->setCliOut($this->cliOut);
    }

    protected function checkInputCommand($command)
    {
        $supportCommands = ['start', 'reload', 'restart', 'stop', 'info', 'status'];

        // show help info
        if (
            // no input
            !$command ||
            // command equal to 'help'
            $command === 'help' ||
            // has option -h|--help
            $this->cliIn->sameOpt(['h','help'])
        ) {
            $this->showHelpInfo();
        }

        // is an not supported command
        if (!in_array($command, $supportCommands, true)) {
            $this->cliOut->error("the command [$command] is not supported. please see the help information.");
            $this->showHelpInfo();
        }
    }

    public function start($daemon = null)
    {
        // prepare server instance
        $this->prepareServer();

        // register server events
        $this->ws->on(WSInterface::ON_HANDSHAKE, [$this, 'handleHandshake']);
        $this->ws->on(WSInterface::ON_OPEN, [$this, 'handleOpen']);
        $this->ws->on(WSInterface::ON_MESSAGE, [$this, 'handleMessage']);
        $this->ws->on(WSInterface::ON_CLOSE, [$this, 'handleClose']);
        $this->ws->on(WSInterface::ON_ERROR, [$this, 'handleError']);

        // if not register route, add root path route handler
        if (0 === count($this->modules)) {
            $this->module('/', new RootModule);
        }

        // start server
        $this->ws->start();
    }

    public function restart($daemon = null)
    {
        // prepare server instance
        $this->prepareServer();

        if ($this->ws->isRunning()) {
            $this->stop();
        }

        $this->start($daemon);
    }

    public function reload($onlyTask = false)
    {

    }

    public function stop()
    {
        if ($this->ws->isRunning()) {
            $this->cliOut->error('server is not running!');
        }

        ProcessHelper::kill($this->ws->getPid());
    }

    /**
     * Show help
     * @param  boolean $showHelpAfterQuit
     */
    public function showHelpInfo($showHelpAfterQuit = true)
    {
        $scriptName = $this->cliIn->getScriptName();

        // 'bin/test_server.php'
        if (strpos($scriptName, '.') && 'php' === pathinfo($scriptName, PATHINFO_EXTENSION)) {
            $scriptName = 'php ' . $scriptName;
        }

        $this->cliOut->helpPanel([
            'description' => 'webSocket server tool, Version <comment>' . ServerAbstracter::VERSION .
                '</comment> Update time ' . ServerAbstracter::UPDATE_TIME,
            'usage' => "$scriptName {start|reload|restart|stop|status} [-d]",
            'commands' => [
                'start' => 'Start the websocket application server',
                'reload' => 'Reload all workers of the started application server',
                'restart' => 'Stop the application server, After start the server.',
                'stop' => 'Stop the application server',
                'info' => 'Show the application server information for current project',
                'status' => 'Show the started application server status information',
                'help' => 'Display this help message',
            ],
            'options' => [
                '-d' => 'Run the application server on the background.(<comment>not supported on windows</comment>)',
                '--task' => 'Only reload task worker, when reload server',
                '--debug' => 'Run the application server on the debug mode',
                '--driver' => 'You can custom webSocket driver, allow: <comment>sockets, swoole, streams</comment>',
                '-h, --help' => 'Display this help message',
            ],
            'examples' => [
                "<info>$scriptName start -d</info> Start server on daemonize mode.",
                "<info>$scriptName start --driver={name}</info> custom webSocket driver, allow: sockets, swoole, streams"
            ]
        ], $showHelpAfterQuit);
    }

    /**
     * webSocket 只会在连接握手时会有 request, response
     * @param Request $request
     * @param Response $response
     * @param int $cid
     * @return bool
     */
    public function handleHandshake(Request $request, Response $response, int $cid)
    {
        $path = $request->getPath();

        // check route. if not exists, response 404 error
        if (!$module = $this->getModule($path, false)) {
            $this->log("The #$cid request's path [$path] route handler not exists.", 'error');

            // call custom route-not-found handler
            if ($rnfHandler = $this->wsHandlers[self::ROUTE_NOT_FOUND]) {
                $rnfHandler($cid, $path, $this);
            }

            $response
                ->setStatus(404)
                ->setHeaders(['Connection' => 'close'])
                ->setBody("You request route path [$path] not found!");

            return false;
        }

        $origin = $request->getOrigin();

        // check `Origin`
        // Access-Control-Allow-Origin: *
        if (!$module->checkIsAllowedOrigin($origin)) {
            $this->log("The #$cid Origin [$origin] is not in the 'allowedOrigins' list.", 'error');

            $response
                ->setStatus(403)
                ->setHeaders(['Connection' => 'close'])
                ->setBody('Deny Access!');

            return false;
        }

        // application/json
        // text/plain
        $response->setHeader('Server', $this->ws->getName() . '-websocket-server');
        // $response->setHeader('Access-Control-Allow-Origin', '*');

        $module->setApp($this);
        $module->setRequest($request);
        $module->onHandshake($request, $response);

        return true;
    }

    /**
     * @param ServerInterface $ws
     * @param Request $request
     * @param int $cid
     */
    public function handleOpen(ServerInterface $ws, Request $request, int $cid)
    {
        $this->log("A new user #$cid open connection. Now, user count: " . $ws->count());
        // $this->log("SERVER Data: \n" . var_export($_SERVER, 1), 'info');

        if ($openHandler = $this->wsHandlers[self::OPEN_HANDLER]) {
            $openHandler($this, $request, $cid);
        }

        // $path = $ws->getClient($cid)['path'];
        $path = $request->getPath();
        $this->getModule($path)->onOpen($cid);
    }

    /**
     * @param ServerInterface $ws
     * @param string $data
     * @param int $cid
     * @param ClientMetadata $meta
     */
    public function handleMessage(ServerInterface $ws, string $data, int $cid, ClientMetadata $meta)
    {
        $this->log("Received user #$cid sent message. MESSAGE: $data, LENGTH: " . mb_strlen($data) . ', Meta: ', 'info', $meta->all());

        // call custom message handler
        if ($msgHandler = $this->wsHandlers[self::MESSAGE_HANDLER]) {
            $msgHandler($ws, $this);
        }

        // dispatch command

        // $path = $ws->getClient($cid)['path'];
        $result = $this->getModule($meta['path'])->dispatch($data, $cid);

        if ($result && is_string($result)) {
            $ws->send($result);
        }
    }

    /**
     * @param ServerInterface $ws
     * @param int $cid
     * @param ClientMetadata $client
     */
    public function handleClose(ServerInterface $ws, int $cid, ClientMetadata $client)
    {
        $this->log("The #$cid user disconnected. Now, connected user count: " . $ws->count());

        if ($closeHandler = $this->wsHandlers[self::CLOSE_HANDLER]) {
            $closeHandler($this, $cid, $client);
        }

        $this->getModule($client['path'])->onClose($cid, $client);
    }

    /**
     * @param ServerInterface $ws
     * @param string $msg
     */
    public function handleError(string $msg, ServerInterface $ws)
    {
        $this->log('Accepts a connection on a socket error: ' . $msg, 'error');

        if ($errHandler = $this->wsHandlers[self::ERROR_HANDLER]) {
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

    /**
     * @param $event
     * @param callable $handler
     * @param bool $once
     */
    public function addListener($event, callable $handler, $once = false)
    {
        $this->on($event, $handler, $once);
    }

    /////////////////////////////////////////////////////////////////////////////////////////
    /// handle request route module
    /////////////////////////////////////////////////////////////////////////////////////////

    /**
     * register a route and it's handler module
     * @param string $path route path
     * @param ModuleInterface $module the route path module
     * @param bool $replace replace exists's route
     * @return ModuleInterface
     */
    public function addModule(string $path, ModuleInterface $module, $replace = false)
    {
        return $this->module($path, $module, $replace);
    }
    public function module(string $path, ModuleInterface $module, $replace = false)
    {
        $path = trim($path) ?: '/';
        $pattern = '/^\/[a-zA-Z][\w-]+$/';

        if ($path !== '/' && preg_match($pattern, $path)) {
            throw new \InvalidArgumentException("The route path format must be match: $pattern");
        }

        if (!$replace && $this->hasModule($path)) {
            throw new \InvalidArgumentException("The route path [$path] have been registered!");
        }

        $this->modules[$path] = $module;

        return $module;
    }

    /**
     * @param $path
     * @return bool
     */
    public function hasModule(string $path): bool
    {
        return isset($this->modules[$path]);
    }

    /**
     * @param string $path
     * @param bool $throwError
     * @return ModuleInterface
     */
    public function getModule(string $path = '/', $throwError = true): ModuleInterface
    {
        if (!$this->hasModule($path)) {
            if ($throwError) {
                throw new \RuntimeException("The route handler not exists for the path: $path");
            }

            return null;
        }

        return $this->modules[$path];
    }

    /**
     * @return array
     */
    public function getModulePaths(): array
    {
        return array_keys($this->modules);
    }

    /**
     * @return array
     */
    public function getModules(): array
    {
        return $this->modules;
    }

    /**
     * @param array $modules
     */
    public function setModules(array $modules)
    {
        foreach ($modules as $route => $module) {
            $this->module($route, $module);
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
            'msg' => $msg,
            'code' => $code,
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
        if ($this->isJsonType()) {
            $data = $this->fmtJson($data, $msg ?: 'success', $code);

            // text
        } else {
            if ($data && is_array($data)) {
                $data = json_encode($data);
            }

            $data = $data ?: $msg;
        }

        return $data;
    }

    /**
     * response data to client, will auto build formatted message by 'data_type'
     * @param mixed $data
     * @param string $msg
     * @param int $code
     * @param bool $doSend
     * @return int|WSResponse
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
     * @return int|WSResponse
     */
    public function respondText($data, bool $doSend = true)
    {
        if (is_array($data)) {
            $data = implode('', $data);
        }

        $mr = WSResponse::make($data)->setWs($this->ws);

        if ($doSend) {
            $mr->send();
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
        if (is_array($data)) {
            $data = implode('', $data);
        }

        $mr = WSResponse::make($data)->setWs($this->ws);

        if ($afterMakeMR) {
            $status = $afterMakeMR($mr);

            // If the message have been sent
            if (is_int($status)) {
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

    /////////////////////////////////////////////////////////////////////////////////////////
    /// helper method
    /////////////////////////////////////////////////////////////////////////////////////////

    /**
     * @return bool
     */
    public function isJsonType(): bool
    {
        return $this->getOption('data_type') === self::DATA_JSON;
    }

    /**
     * @return string
     */
    public function getDataType(): string
    {
        return $this->getOption('data_type');
    }

    /**
     * @return string
     */
    public function getPidFIle(): string
    {
        return $this->getOption('pid_file', '');
    }

    /**
     * @return bool
     */
    public function isDaemon(): bool
    {
        return (bool)$this->getOption('daemon', false);
    }

    /**
     * @return bool
     */
    public function isDebug(): bool
    {
        return (bool)$this->getOption('debug', false);
    }

    /**
     * output and record application log message
     * @param  string $msg
     * @param  array $data
     * @param string $type
     */
    public function log(string $msg, string $type = 'info', array $data = [])
    {
        // if close debug, don't output debug log.
        if ($type !== 'debug' || $this->isDebug()) {
            if (!$this->isDaemon()) {
                list($time, $micro) = explode('.', microtime(1));
                $time = date('Y-m-d H:i:s', $time);
                $json = $data ? json_encode($data) : '';
                $type = strtoupper($type);

                $this->cliOut->write("[{$time}.{$micro}] [$type] $msg {$json}");
            }

            if ($logger = $this->getLogger()) {
                $logger->$type(strip_tags($msg), $data);
            }
        }
    }

    /**
     * output debug log message
     * @param string $message
     * @param array $data
     */
    public function debug(string $message, array $data = [])
    {
        $this->log($message, 'debug', $data);
    }

    /**
     * @param mixed $messages
     * @param bool $nl
     * @param bool|int $exit
     */
    public function print($messages, $nl = true, $exit = false)
    {
        $this->cliOut->write($messages, $nl, $exit);
    }

    /////////////////////////////////////////////////////////////////////////////////////////
    /// getter/setter
    /////////////////////////////////////////////////////////////////////////////////////////

    /**
     * get Logger service
     * @return FileLogger
     */
    public function getLogger()
    {
        return $this->logger;
    }

    /**
     * @return ServerInterface
     */
    public function getWs(): ServerInterface
    {
        return $this->ws;
    }

    /**
     * @param string $host
     */
    public function setHost(string $host)
    {
        $this->host = $host;
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
     * @param int $port
     */
    public function setPort(int $port)
    {
        $this->port = $port;
    }


}
