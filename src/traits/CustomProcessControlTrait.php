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
trait CustomProcessControlTrait
{
    /**
     * current support process control
     * @var bool
     */
    protected $supportPC = true;

    /**
     * @var int
     */
    protected $id = 0;

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
     * When true, workers will stop and the parent process will kill off all running workers
     * @var boolean
     */
    protected $stopWork = false;

    /**
     * Workers will only live for 1 hour
     * @var integer
     */
    protected $maxLifetime = 3600;


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

    /*
    $ws = new WebSocketServer;
    $ws->asDaemon();
    $ws->changeIdentity(65534, 65534); // nobody/nogroup
    $ws->registerSignals();
    $pid = $ws->getMasterPID();
    ...
    $ws->start();
     */

    protected function doStart()
    {
        // Register signal listeners `pcntl_signal_dispatch()`
        $this->installSignals();

        // before Start Workers
        $this->beforeStartWorkers();

        // start workers and set up a running environment
        $this->startWorkers();

        // start worker monitor
        $this->startWorkerMonitor();
    }

    /**
     * fork multi process
     */
    public function startWorkers()
    {
        if ($this->supportPC) {
            $num = $this->config['worker_num'];

            for ($i = 0; $i < $num; $i++) {
                $this->startWorker($i+1);

                // Don't start workers too fast.
                usleep(500000);
            }

            $this->log('Workers stopped', self::LOG_PROC_INFO);

        // if not support multi process
        } else {
            $this->startDriverWorker();
        }
    }

    /**
     * Start a worker do there are assign jobs. If is in the parent, record worker info.
     *
     * @param int $id
     * @param bool $isFirst True: Is first start by manager. False: is restart by monitor `startWorkerMonitor()`
     */
    protected function startWorker(int $id, $isFirst = true)
    {
        if (!$isFirst) {
            // clear file info
            clearstatcache();
        }

        // fork process
        $pid = pcntl_fork();

        switch ($pid) {
            case 0: // at workers
                $this->isWorker = true;
                $this->isMaster = false;
                $this->masterPid = $this->pid;
                $this->id = $id;
                $this->pid = getmypid();
                $this->stat['start_time'] = time();

                $this->installSignals(false);
                $this->setProcessTitle(sprintf("php-gwm: worker process %s", $this->getShowName()));

                if (($splay = $this->get('restart_splay')) > 0) {
                    $this->maxLifetime += mt_rand(0, $splay);
                    $this->log("The worker adjusted max run time to {$this->maxLifetime} seconds", self::LOG_DEBUG);
                }

                $code = $this->startDriverWorker();

                $this->log("Worker exiting(PID:{$this->pid} Code:$code)", self::LOG_WORKER_INFO);
                $this->quit($code);
                break;

            case -1: // fork failed.
                $this->stderr('Could not fork workers process! exiting', true, false);
                $this->stopWork = true;
                $this->stopWorkers();
                break;

            default: // at parent
                $text = $isFirst ? 'First' : 'Restart';
                $this->log("Started worker(PID:$pid) ($text)", self::LOG_PROC_INFO);
                $this->workers[$pid] = [
                    'id' => $id,
                    'start_time' => time(),
                ];
        }
    }

    /**
     * Begin monitor workers
     *  - will monitoring workers process running status
     *
     * @notice run in the parent main process, workers process will exited in the `startWorkers()`
     */
    protected function startWorkerMonitor()
    {
        $this->log('Now, Begin monitor runtime status for all workers', self::LOG_DEBUG);

        $maxLifetime = $this->config['max_lifetime'];

        // Main processing loop for the parent process
        while (!$this->stopWork || count($this->workers)) {
            // receive and dispatch sig
            pcntl_signal_dispatch();

            $status = null;

            // Check for exited workers
            $exitedPid = pcntl_wait($status, WNOHANG);

            // We run other workers, make sure this is a worker
            if (isset($this->workers[$exitedPid])) {
                /*
                 * If they have exited, remove them from the workers array
                 * If we are not stopping work, start another in its place
                 */
                if ($exitedPid) {
                    $exitCode = pcntl_wexitstatus($status);
                    $info = $this->workers[$exitedPid];
                    unset($this->workers[$exitedPid]);

                    $this->logWorkerStatus($exitedPid, $exitCode);

                    if (!$this->stopWork) {
                        $this->startWorker($info['id'], false);
                    }
                }
            }

            if ($this->stopWork && time() - $this->stat['stop_time'] > 30) {
                $this->log('Workers have not exited, force killing.', self::LOG_PROC_INFO);
                $this->stopWorkers(SIGKILL);
                // $this->killProcess($pid, SIGKILL);
            } else {
                // If any workers have been running 150% of max run time, forcibly terminate them
                foreach ($this->workers as $pid => $worker) {
                    $startTime = $worker['start_time'];

                    if ($startTime > 0 && $maxLifetime > 0 && time() - $startTime > $maxLifetime * 1.5) {
                        $this->logWorkerStatus($pid, self::CODE_MANUAL_KILLED);
                        $this->sendSignal($pid, SIGKILL);
                    }
                }
            }

            // php will eat up your cpu if you don't have this
            usleep(10000);
        }
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

        $signals = [
            SIGINT => 'SIGINT',
            SIGTERM => 'SIGTERM',
            SIGKILL => 'SIGKILL',
        ];

        $this->log("Stopping workers(send:{$signals[$signal]}) ...", self::LOG_PROC_INFO);

        foreach ($this->workers as $pid => $worker) {
            $this->stopWorker($pid);
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
    protected function sendSignal(int $pid, int $signal)
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
     * set Process Title
     * @param string $title
     */
    protected function setProcessTitle(string $title)
    {
        if ($this->supportPC) {
            ProcessHelper::setProcessTitle($title);
        }
    }

    /**
     * @param int $pid
     * @param int $statusCode
     */
    protected function logWorkerStatus($pid, $statusCode)
    {
        switch ((int)$statusCode) {
            case self::CODE_MANUAL_KILLED:
                $message = "Worker (PID:$pid) has been running too long. Forcibly killing process.";
                break;
            case self::CODE_NORMAL_EXITED:
                unset($this->workers[$pid]);
                $message = "Worker (PID:$pid) normally exited.";
                break;
            case self::CODE_CONNECT_ERROR:
                $message = "Worker (PID:$pid) connect to job server failed. exiting";
                $this->stopWork = true;
                break;
            default:
                $message = "Worker (PID:$pid) died unexpectedly with exit code $statusCode.";
                break;
        }

        $this->log($message, self::LOG_PROC_INFO);
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