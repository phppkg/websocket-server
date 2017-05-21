<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017/5/21
 * Time: 下午9:54
 */

namespace inhere\webSocket\traits;

/**
 * Class OptionsConfigTrait
 * @package inhere\webSocket\traits
 */
trait OptionsConfigTrait
{
    /**
     * if you setting name, will display on the process name.
     * @var string
     */
    protected $name;

    /**
     * handle CLI command and load options
     * @return bool
     */
    protected function handleCommandAndConfig()
    {
        $command = $this->cliIn->getCommand() ?: 'start';
        $supported = ['start', 'stop', 'restart', 'reload', 'status'];

        if (!in_array($command, $supported, true)) {
            $this->showHelp("The command [{$command}] is don't supported!");
        }

        $options = $this->cliIn->getOpts();

        // load CLI Options
        $this->loadCommandOptions($options);

        // init Config And Properties
        $this->initConfigAndProperties($this->config);

        // Debug option to dump the config and exit
        if (isset($options['D']) || isset($options['dump'])) {
            $val = isset($options['D']) ? $options['D'] : (isset($options['dump']) ? $options['dump'] : '');
            $this->dumpInfo($val === 'all');
        }

        $masterPid = ProcessUtil::getPidFromFile($this->pidFile);
        $isRunning = ProcessUtil::isRunning($masterPid);

        // start: do Start Server
        if ($command === 'start') {
            // check master process is running
            if ($isRunning) {
                $this->stderr("The worker manager has been running. (PID:{$masterPid})\n", true, -__LINE__);
            }

            return true;
        }

        // check master process
        if (!$isRunning) {
            $this->stderr("The worker manager is not running. can not execute the command: {$command}\n", true, -__LINE__);
        }

        // switch command
        switch ($command) {
            case 'stop':
            case 'restart':
                // stop: stop and exit. restart: stop and start
                $this->stopServer($masterPid, $command === 'stop');
                break;
            case 'reload':
                // reload workers
                $this->reloadWorkers($masterPid);
                break;
            case 'status':
                $cmd = isset($options['cmd']) ? $options['cmd']: 'status';
                $this->showStatus($cmd, isset($options['watch-status']));
                break;
            default:
                $this->showHelp("The command [{$command}] is don't supported!");
                break;
        }

        return true;
    }

    /**
     * load the command line options
     * @param array $opts
     */
    protected function loadCommandOptions(array $opts)
    {
        $map = [
            'c' => 'conf_file', // config file
            's' => 'server',    // server address

            'n' => 'worker_num',  // worker number do all jobs
            'u' => 'user',
            'g' => 'group',

            'l' => 'log_file',
            'p' => 'pid_file',

            'r' => 'max_request', // max request for a worker
            'x' => 'max_lifetime',// max lifetime for a worker
            't' => 'timeout',
        ];

        // show help
        if (isset($opts['h']) || isset($opts['help'])) {
            $this->showHelp();
        }
        // show version
        if (isset($opts['V']) || isset($opts['version'])) {
            $this->showVersion();
        }

        // load opts values to config
        foreach ($map as $k => $v) {
            if (isset($opts[$k]) && $opts[$k]) {
                $this->config[$v] = $opts[$k];
            }
        }

        // load Custom Config File
        if ($file = $this->config['conf_file']) {
            if (!file_exists($file)) {
                $this->showHelp("Custom config file {$file} not found.");
            }

            $config = require $file;
            $this->setConfig($config);
        }

        // watch modify
//        if (isset($opts['w']) || isset($opts['watch'])) {
//            $this->config['watch_modify'] = $opts['w'];
//        }

        // run as daemon
        if (isset($opts['d']) || isset($opts['daemon'])) {
            $this->config['daemon'] = true;
        }

        if (isset($opts['v'])) {
            $opts['v'] = $opts['v'] === true ? '' : $opts['v'];

            switch ($opts['v']) {
                case '':
                    $this->config['log_level'] = self::LOG_INFO;
                    break;
                case 'v':
                    $this->config['log_level'] = self::LOG_PROC_INFO;
                    break;
                case 'vv':
                    $this->config['log_level'] = self::LOG_WORKER_INFO;
                    break;
                case 'vvv':
                    $this->config['log_level'] = self::LOG_DEBUG;
                    break;
                case 'vvvv':
                    $this->config['log_level'] = self::LOG_CRAZY;
                    break;
                default:
                    // $this->config['log_level'] = self::LOG_INFO;
                    break;
            }
        }
    }

    /**
     * @param array $config
     */
    protected function initConfigAndProperties(array $config)
    {
        // init config attributes

        $this->config['daemon'] = (bool)$config['daemon'];
        $this->config['pid_file'] = trim($config['pid_file']);
        $this->config['enable_ssl'] = (bool)$config['enable_ssl'];
        $this->config['worker_num'] = (int)$config['worker_num'];
        $this->config['log_level'] = (int)$config['log_level'];

        $logFile = trim($config['log_file']);

        if ($logFile === 'syslog') {
            $this->config['log_syslog'] = true;
            $this->config['log_file'] = '';
        } else {
            $this->config['log_file'] = $logFile;
        }

        $this->config['timeout'] = (int)$config['timeout'];
        $this->config['max_connect'] = (int)$config['max_connect'];
        $this->config['max_lifetime'] = (int)$config['max_lifetime'];
        $this->config['max_request'] = (int)$config['max_request'];
        $this->config['restart_splay'] = (int)$config['restart_splay'];
        $this->config['max_data_len'] = (int)$config['max_data_len'];

//        $this->config['watch_modify'] = (bool)$config['watch_modify'];
//        $this->config['watch_modify_interval'] = (int)$config['watch_modify_interval'];

        $this->config['write_buffer_size'] = (int)$config['write_buffer_size'];
        $this->config['read_buffer_size'] = (int)$config['read_buffer_size'];

        // config value fix ... ...

        if ($this->config['worker_num'] <= 0) {
            $this->config['worker_num'] = self::WORKER_NUM;
        }

        if ($this->config['max_lifetime'] < self::MIN_LIFETIME) {
            $this->config['max_lifetime'] = self::MAX_LIFETIME;
        }

        if ($this->config['max_request'] < self::MIN_REQUEST) {
            $this->config['max_request'] = self::MAX_REQUEST;
        }

        if ($this->config['restart_splay'] <= 100) {
            $this->config['restart_splay'] = self::RESTART_SPLAY;
        }

        if ($this->config['timeout'] <= self::MIN_TIMEOUT) {
            $this->config['timeout'] = self::TIMEOUT;
        }

//        if ($this->config['watch_modify_interval'] <= self::MIN_WATCH_INTERVAL) {
//            $this->config['watch_modify_interval'] = self::WATCH_INTERVAL;
//        }

        $setTime = (int)$config['sleep_time'];
        $this->config['sleep_time'] = $setTime >= 10 ? $setTime : 50;

        // init properties

        if ($server = trim($config['server'])) {
            if (strpos($server, ':')) {
                [$this->host, $this->port] = explode(':', $server, 2);
            } else {
                $this->host = $server;
            }
        }

        if (!$this->host) {
            $this->host = self::DEFAULT_HOST;
        }

        if (!$this->port || $this->port <= 0) {
            $this->port = self::DEFAULT_PORT;
        }

        $this->config['server'] = "{$this->host}:{$this->port}";

        $this->name = trim($config['name']);
        $this->maxLifetime = $this->config['max_lifetime'];
        $this->logLevel = $this->config['log_level'];
        $this->pidFile = $this->config['pid_file'];

        unset($config);
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getShowName()
    {
        return $this->name ? "({$this->name})" : '';
    }

    /**
     * @return bool
     */
    public function isDaemon(): bool
    {
        return (bool)$this->get('daemon', false);
    }
}