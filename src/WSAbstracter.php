<?php
/**
 * Created by PhpStorm.
 * User: Inhere
 * Date: 2017/4/8 0008
 * Time: 23:10
 */

namespace inhere\webSocket;

use inhere\console\io\Input;
use inhere\console\io\Output;
use inhere\library\helpers\PhpHelper;
use inhere\library\traits\TraitSimpleFixedEvent;
use inhere\library\traits\TraitSimpleOption;
use inhere\library\utils\SFLogger;

/**
 * Class WSAbstracter
 * @package inhere\webSocket
 */
abstract class WSAbstracter implements WSInterface
{
    use TraitSimpleOption;
    use TraitSimpleFixedEvent;

    const DEFAULT_HOST = '0.0.0.0';

    const DEFAULT_PORT = 8080;

    /**
     * the driver name
     * @var string
     */
    protected $name = '';

    /**
     * @var string
     */
    protected $host;

    /**
     * @var int
     */
    protected $port;

    /**
     * @var Output
     */
    protected $cliOut;

    /**
     * @var Input
     */
    protected $cliIn;

    /**
     * @var SFLogger
     */
    private $logger;

    /**
     * @var array
     */
    protected $options = [];

    /**
     * @return array
     */
    public function getDefaultOptions()
    {
        return [
            'debug' => false,
            'as_daemon' => false,

            // enable ssl
            'enable_ssl' => false,

            // 设置写(发送)缓冲区 最大2m @see `StreamsServer::setBufferSize()`
            'write_buffer_size' => 2097152,

            // 设置读(接收)缓冲区 最大2m
            'read_buffer_size' => 2097152,

            // 日志配置
            'log_service' => [
                // 'name' => 'ws_server_log'
                // 'basePath' => PROJECT_PATH . '/temp/logs/ws_server',
                // 'logConsole' => false,
                // 'logThreshold' => 0,
            ],
        ];
    }

    /**
     * WSAbstracter constructor.
     * @param array $options
     */
    public function __construct(array $options = [])
    {
        if ( !PhpHelper::isCli() ) {
            throw new \RuntimeException('Server must run in the CLI mode.');
        }

        $this->cliIn = new Input();
        $this->cliOut = new Output();
        $this->options = $this->getDefaultOptions();

        $this->setOptions($options, true);

        $this->init();
    }

    protected function init()
    {
        // create log service instance
        if ( $config = $this->getOption('log_service') ) {
            $this->logger = SFLogger::make($config);
        }
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getHost(): string
    {
        if ( !$this->host ) {
            $this->host = self::DEFAULT_HOST;
        }

        return $this->host;
    }

    /**
     * @return int
     */
    public function getPort(): int
    {
        if ( !$this->port || $this->port <= 0 ) {
            $this->port = self::DEFAULT_PORT;
        }

        return $this->port;
    }

    /**
     * @return Output
     */
    public function getCliOut(): Output
    {
        return $this->cliOut;
    }

    /**
     * @param Output $output
     */
    public function setCliOut(Output $output)
    {
        $this->cliOut = $output;
    }

    /**
     * @return Input
     */
    public function getCliIn(): Input
    {
        return $this->cliIn;
    }

    /**
     * @param Input $cliIn
     */
    public function setCliIn(Input $cliIn)
    {
        $this->cliIn = $cliIn;
    }

    /**
     * Generate a random string for WebSocket key.(for client)
     * @return string Random string
     */
    public function genKey(): string
    {
        $key = '';
        $chars = self::TOKEN_CHARS;
        $chars_length = strlen($chars);

        for ($i = 0; $i < 16; $i++) {
            $key .= $chars[random_int(0, $chars_length - 1)]; //mt_rand
        }

        return base64_encode($key);
    }

    /**
     * Generate WebSocket sign.(for server)
     * @param string $key
     * @return string
     */
    public function genSign(string $key): string
    {
        return base64_encode(sha1(trim($key) . self::SIGN_KEY, true));
    }

    /**
     * @return bool
     */
    public function isDebug(): bool
    {
        return (bool)$this->getOption('debug', false);
    }

    /**
     * get Logger service
     * @return SFLogger
     */
    public function getLogger()
    {
        return $this->logger;
    }

    /**
     * output and record websocket log message
     * @param  string $msg
     * @param  array $data
     * @param string $type
     */
    public function log(string $msg, string $type = 'debug', array $data = [])
    {
        // if close debug, don't output debug log.
        if ( $this->isDebug() || $type !== 'debug') {

            [$time, $micro] = explode('.', microtime(1));

            $time = date('Y-m-d H:i:s', $time);
            $json = $data ? json_encode($data) : '';
            $type = strtoupper(trim($type));

            $this->cliOut->write("[{$time}.{$micro}] [$type] $msg {$json}");

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
}
