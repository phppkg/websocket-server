<?php
/**
 * Created by PhpStorm.
 * User: Inhere
 * Date: 2017/4/9 0009
 * Time: 23:39
 */

namespace inhere\webSocket\traits;
use inhere\library\helpers\ProcessHelper;


/**
 * Class ProcessControlTrait
 * @package inhere\webSocket\traits
 *
 */
trait ProcessControlTrait
{
    /**
     * current support process control
     * @var bool
     */
    protected $supportPC = true;

    /**
     * The PID of the current running process. Set for parent and child processes
     * @var int
     */
    protected $pid = 0;

    /**
     * The PID of the parent(master) process, when running in the forked helper,worker.
     * @var int
     */
    protected $masterPid = 0;

    /**
     * @var bool
     */
    protected $isMaster = false;

    /**
     * @var bool
     */
    protected $isWorker = false;

    /**
     * @var string
     */
    protected $pidFile;

    /**
     * When true, workers will stop and the parent process will kill off all running workers
     * @var boolean
     */
    protected $stopWork = false;

    /**
     * The statistics info for server/worker
     * @var array
     */
    protected $stat = [
        'start_time' => 0,
        'stop_time'  => 0,
        'start_times' => 0,
    ];

    /**
     * workers
     * @var array
     * [
     *  pid => [
     *      'jobs' => [],
     *      'start_time' => int
     *  ]
     * ]
     */
    protected $workers = [];

    /////////////////////////////////////////////////////////////////////////////////////////
    /// process method
    /////////////////////////////////////////////////////////////////////////////////////////

    /*
    $ws = new WebSocketServer;
    $ws->asDaemon();
    $ws->changeIdentity(65534, 65534); // nobody/nogroup
    $ws->registerSignals();
    $pid = $ws->getMasterPID();
    ...
    $ws->start();
     */

    /**
     * fork multi process
     * @return array|int
     */
    public function startWorkers()
    {
        $num = (int)$number >= 0 ? $number : 0;

        if ($num < 2) {
            return posix_getpid();
        }

        $pids = array();

        for ($i = 0; $i < $num; $i++) {
            $pid = pcntl_fork();

            if ($pid > 0) {
                $pids[] = $pid;
            } else {
                break;
            }
        }

        return $pids;
    }

    /**
     * run as daemon process
     */
    public function runAsDaemon()
    {
        if (!$this->supportPC) {
            $this->log("Want to run process as daemon, require 'pcntl','posix' extension!", self::LOG_DEBUG);
        } else {
            ProcessHelper::runAsDaemon();

            // set pid
            $this->pid = getmypid(); // can also use: posix_getpid()
        }
    }

    /**
     * Do shutdown server
     * @param  int $masterPid Master Pid
     * @param  boolean $quit Quit, When stop success?
     */
    protected function stopServer(int $masterPid, $quit = false)
    {
        ProcessHelper::killAndWait($masterPid, SIGTERM, 'server');

        if ($quit) {
            $this->quit();
        }

        // clear file info
        clearstatcache();

        $this->stdout('Begin restart server ...');
    }

    /**
     * reloadWorkers
     * @param $masterPid
     */
    protected function reloadWorkers($masterPid)
    {
        $this->stdout("Workers reloading ...");

        $this->sendSignal($masterPid, SIGHUP);

        $this->quit();
    }

    /**
     * Stops all running workers
     * @param int $signal
     * @return bool
     */
    protected function stopWorkers($signal = SIGTERM)
    {
        if (!$this->workers) {
            $this->log('No child process(worker) need to stop', self::LOG_PROC_INFO);
            return false;
        }

        static $stopping = false;

        if ($stopping) {
            $this->log('Workers stopping ...', self::LOG_PROC_INFO);
            return true;
        }

        $signals = [
            SIGINT => 'SIGINT',
            SIGTERM => 'SIGTERM',
            SIGKILL => 'SIGKILL',
        ];

        $this->log("Stopping workers(signal:{$signals[$signal]}) ...", self::LOG_PROC_INFO);

        foreach ($this->workers as $pid => $worker) {
            $this->log("Stopping worker (PID:$pid) (Jobs:".implode(",", $worker['jobs']).")", self::LOG_PROC_INFO);

            // send exit signal.
            $this->killProcess($pid, $signal);
        }

        if ($signal === SIGKILL) {
            $stopping = true;
        }

        return true;
    }

    public function installSignals($isMaster = true)
    {
        if (!$this->supportPC) {
            return false;
        }

        // ignore
        pcntl_signal(SIGPIPE, SIG_IGN, false);

        if ($isMaster) {
            // $signals = ['SIGTERM' => 'close worker', ];
            $this->log('Registering signal handlers for master(parent) process', self::LOG_DEBUG);

            pcntl_signal(SIGTERM, [$this, 'signalHandler'], false);
            pcntl_signal(SIGINT, [$this, 'signalHandler'], false);
            pcntl_signal(SIGUSR1, [$this, 'signalHandler'], false);
            pcntl_signal(SIGUSR2, [$this, 'signalHandler'], false);
            pcntl_signal(SIGHUP, [$this, 'signalHandler'], false);

            pcntl_signal(SIGCHLD, [$this, 'signalHandler'], false);

        } else {
            $this->log("Registering signal handlers for current worker process", self::LOG_DEBUG);

            if (!pcntl_signal(SIGTERM, [$this, 'signalHandler'], false)) {
                $this->quit(-170);
            }
        }

        // stop
        pcntl_signal(SIGINT, [$this, 'signalHandler'], false);
        // reload
        pcntl_signal(SIGUSR1, [$this, 'signalHandler'], false);
        // status
        pcntl_signal(SIGUSR2, [$this, 'signalHandler'], false);

        // ignore
        pcntl_signal(SIGPIPE, SIG_IGN, false);

        pcntl_signal(SIGCHLD, [$this, 'signalHandler'], false);

        return $this;
    }

    /**
     * Handles signals
     * @param int $sigNo
     */
    public function signalHandler($sigNo)
    {
        if ($this->isMaster) {
            static $stopCount = 0;

            switch ($sigNo) {
                case SIGINT: // Ctrl + C
                case SIGTERM:
                    $sigText = $sigNo === SIGINT ? 'SIGINT' : 'SIGTERM';
                    $this->log("Shutting down(signal:$sigText)...", self::LOG_PROC_INFO);
                    $this->stopWork = true;
                    $this->stat['stop_time'] = time();
                    $stopCount++;

                    if ($stopCount < 5) {
                        $this->stopWorkers();
                    } else {
                        $this->log('Stop workers failed by(signal:SIGTERM), force kill workers by(signal:SIGKILL)', self::LOG_PROC_INFO);
                        $this->stopWorkers(SIGKILL);
                    }
                    break;
                case SIGHUP:
                    $this->log('Restarting workers(signal:SIGHUP)', self::LOG_PROC_INFO);
                    $this->openLogFile();
                    $this->stopWorkers();
                    break;
                case SIGUSR1: // reload workers and reload handlers
                    $this->log('Reloading workers and handlers(signal:SIGUSR1)', self::LOG_PROC_INFO);
                    $this->stopWork = true;
                    $this->start();
                    break;
                case SIGUSR2:
                    break;
                default:
                    // handle all other signals
            }

        } else {
            $this->stopWork = true;
            $this->stat['stop_time'] = time();
            $this->log("Received 'stopWork' signal(signal:SIGTERM), will be exiting.", self::LOG_PROC_INFO);
        }
    }
    /**
     * Signal handler
     * @param $signal
     */
    public function _signalHandler($signal)
    {
        switch ($signal) {
            // Stop.
            case SIGTERM:
            case SIGINT: // ctrl+c
                $this->stopServer();
                break;
            // Reload.
            case SIGUSR1:
                self::$_pidsToRestart = self::getAllWorkerPids();
                self::reload();
                break;
            // Show status.
            case SIGUSR2:
                self::writeStatisticsToStatusFile();
                break;

            case SIGCHLD:
                pcntl_waitpid(-1, $status);
                break;
        }
    }

    /**
     * @param int $pid
     * @param int $signal
     */
    protected function sendSignal($pid, $signal)
    {
        if ($this->supportPC) {
            ProcessHelper::sendSignal($pid, $signal);
        }
    }

    /**
     * dispatchSignals
     */
    protected function dispatchSignals()
    {
        if ($this->supportPC) {
            pcntl_signal_dispatch();
        }
    }

    /**
     * checkEnvironment
     */
    protected function checkEnvironment()
    {
        $e1 = function_exists('posix_kill');
        $e2 = function_exists('pcntl_fork');

        if (!$e1 || !$e2) {
            $this->supportPC = false;

            $e1t = $e1 ? 'yes' : 'no';
            $e2t = $e2 ? 'yes' : 'no';

            $this->stdout("Is not support multi process of the current system. the posix($e1t),pcntl($e2t) extensions is required.\n");
        }
    }

    /**
     * @return bool
     */
    public function isSupportPC(): bool
    {
        return $this->supportPC;
    }

}
