<?php
/**
 * Created by PhpStorm.
 * User: Inhere
 * Date: 2017/4/9 0009
 * Time: 23:39
 */

namespace Inhere\WebSocket\Traits;

use inhere\console\utils\Show;
use Inhere\Library\Helpers\Cli;
use Inhere\Library\Helpers\ProcessHelper;

/**
 * Class ProcessControlTrait
 * @package Inhere\WebSocket\Traits
 *
 */
trait ProcessControlTrait
{
    /**
     * current support process control
     * @var bool
     */
    protected $canProcessControl = true;

    /**
     * @var int
     */
    protected $id = 0;

    /**
     * The PID of the current running process. Set for parent and child processes
     * @var int
     */
    protected $pid = 0;

    /**
     * @var string
     */
    protected $pidFile;

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
     * doStart
     */
    protected function doStart()
    {
        $this->log("Started server with pid {$this->pid}, Current script owner: " . get_current_user(), self::LOG_PROC_INFO);

        $this->isMaster = true;
        $this->stat['start_time'] = time();

        $fullScript = implode(' ', $GLOBALS['argv']);
        $this->setProcessTitle(sprintf('php-ws: master process%s (%s)', $this->getShowName(), $fullScript));

        // Register signal listeners `pcntl_signal_dispatch()`
        $this->installSignals();

        // before Start Workers
        $this->beforeStartWorkers();

        // start workers and set up a running environment
        $this->startWorkers();

        // start worker monitor
        if ($this->canProcessControl) {
            $this->startWorkerMonitor();
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

        $maxLifetime = $this->maxLifetime;

        // Main processing loop for the parent process
        while (!$this->stopWork || \count($this->workers)) {
            // receive and dispatch sig
            $this->dispatchSignals();

            // Check for exited workers
            $status = null;
            $exitedPid = pcntl_wait($status, WNOHANG);

            // We run other workers, make sure this is a worker
            if ($exitedPid && isset($this->workers[$exitedPid])) {
                /*
                 * If they have exited, remove them from the workers array
                 * If we are not stopping work, start another in its place
                 */
                $exitCode = pcntl_wexitstatus($status);
                $info = $this->workers[$exitedPid];
                unset($this->workers[$exitedPid]);

                $this->logWorkerStatus($exitedPid, $exitCode);

                if (!$this->stopWork) {
                    $this->startWorker($info['id'], false);
                }
            }

            if ($this->stopWork) {
                if (time() - $this->stat['stop_time'] > 30) {
                    $this->log('Workers have not exited, force killing.', self::LOG_PROC_INFO);
                    $this->stopWorkers(SIGKILL);
                    // $this->killProcess($pid, SIGKILL);
                }
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
    protected function stopWorkers($signal = SIGTERM): bool
    {
        if (!$this->workers) {
            $this->log('No child process(worker) need to stop', self::LOG_PROC_INFO);
            return false;
        }

        return ProcessHelper::stopChildren($this->workers, $signal, [
            'beforeStops' => function ($sigText) {
                $this->log("Stopping workers({$sigText}) ...", self::LOG_PROC_INFO);
            },
            'beforeStop' => function ($pid, $info) {
                $this->log("Stopping worker #{$info['id']}(PID:$pid)", self::LOG_PROC_INFO);
            },
        ]);
    }

    public function installSignals($isMaster = true)
    {
        if (!$this->canProcessControl) {
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
            $this->log('Registering signal handlers for current worker process', self::LOG_DEBUG);

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
                    $this->stopWork();
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
                    $this->stopWork();
                    $this->start();
                    break;
                case SIGUSR2:
                    break;
                default:
                    // handle all other signals
            }

        } else {
            $this->stopWork();
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
        if ($this->canProcessControl) {
            ProcessHelper::sendSignal($pid, $signal);
        }
    }

    /**
     * dispatchSignals
     */
    protected function dispatchSignals()
    {
        if ($this->canProcessControl) {
            pcntl_signal_dispatch();
        }
    }

    /**
     * set Process Title
     * @param string $title
     */
    protected function setProcessTitle(string $title)
    {
        if ($this->canProcessControl) {
            ProcessHelper::setTitle($title);
        }
    }

    /**
     * @return string
     */
    public function getPidRole(): string
    {
        return $this->isMaster ? 'Master' : 'Worker';
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
                $this->stopWork();
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
        $e1 = \function_exists('posix_kill');
        $e2 = \function_exists('pcntl_fork');

        if (!$e1 || !$e2) {
            $this->canProcessControl = false;

            $e1t = $e1 ? 'yes' : 'no';
            $e2t = $e2 ? 'yes' : 'no';

            $this->stdout("Is not support multi process of the current system. the posix($e1t),pcntl($e2t) extensions is required.\n");
        }
    }

    /**
     * @return bool
     */
    public function isCanProcessControl(): bool
    {
        return $this->canProcessControl;
    }

    /**
     * stopWork
     */
    protected function stopWork()
    {
        $this->stopWork = true;
        $this->stat['stop_time'] = time();
    }

////////////////////////////////////////////////////////////////////////////
/// process manage
////////////////////////////////////////////////////////////////////////////

    /**
     * run as daemon process
     */
    public function runAsDaemon()
    {
        if (!$this->canProcessControl) {
            $this->log("Want to run process as daemon, require 'pcntl','posix' extension!", self::LOG_DEBUG);
        } else {
            // set pid
            $this->pid = ProcessHelper::runAsDaemon();
        }
    }

    /**
     * fork multi process
     */
    public function startWorkers()
    {
        if ($this->canProcessControl) {
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
                $this->setProcessTitle(sprintf('php-gwm: worker process %s', $this->getShowName()));

                if (($splay = $this->get('restart_splay')) > 0) {
                    $this->maxLifetime += random_int(0, $splay);
                    $this->log("The worker adjusted max run time to {$this->maxLifetime} seconds", self::LOG_DEBUG);
                }

                $code = $this->startDriverWorker();

                $this->log("Worker exiting(PID:{$this->pid} Code:$code)", self::LOG_WORKER_INFO);
                $this->quit($code);
                break;

            case -1: // fork failed.
                $this->stderr('Could not fork workers process! exiting', true, false);
                $this->stopWork();
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
     * do Reload Workers
     * @param  int $masterPid
     * @param  boolean $onlyTaskWorker
     */
    public function reloadWorkers($masterPid, $onlyTaskWorker = false)
    {
        // SIGUSR1: 向管理进程发送信号，将平稳地重启所有worker进程; 也可在PHP代码中调用`$server->reload()`完成此操作
        $sig = SIGUSR1;

        // SIGUSR2: only reload task worker
        if ($onlyTaskWorker) {
            $sig = SIGUSR2;
            Show::notice('Will only reload task worker(send: SIGUSR2)');
        }

        if (!ProcessHelper::sendSignal($masterPid, $sig)) {
            Show::error("The server({$this->name}) worker process reload fail!", -1);
        }

        Show::success("The server({$this->name}) worker process reload success.", 0);
    }

    /**
     * Do shutdown server
     * @param  int $pid Master Pid
     * @param  boolean $quit Quit, When stop success?
     */
    protected function stopServer(int $pid, $quit = false)
    {
        $this->stdout("Stop the manager(PID:$pid)");

        ProcessHelper::killAndWait($pid, SIGTERM, 'server');

        $this->stdout(sprintf("\n%s\n"), Cli::color('The manager process stopped', Cli::FG_GREEN));

        if ($quit) {
            $this->quit();
        }

        // clear file info
        clearstatcache();

        $this->stdout('Begin restart server ...');
    }

    /**
     * 使当前worker进程停止运行，并立即触发onWorkerStop回调函数
     * @param int $pid
     */
    public function stopWorker(int $pid)
    {
        ProcessHelper::killAndWait($pid, SIGTERM, 'worker');
    }

    /**
     * savePidFile
     */
    protected function savePidFile()
    {
        if ($this->pidFile && !file_put_contents($this->pidFile, $this->pid)) {
            $this->showHelp("Unable to write PID to the file {$this->pidFile}");
        }
    }

    /**
     * delete pidFile
     */
    protected function delPidFile()
    {
        if ($this->pidFile && file_exists($this->pidFile) && !unlink($this->pidFile)) {
            $this->log("Could not delete PID file: {$this->pidFile}", self::LOG_WARN);
        }
    }
}
