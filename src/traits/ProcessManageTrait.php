<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017/5/21
 * Time: 下午9:51
 */

namespace inhere\webSocket\traits;

use inhere\library\helpers\CliHelper;
use inhere\library\process\ProcessUtil;

/**
 * Class ProcessManageTrait
 * @package inhere\webSocket\traits
 */
trait ProcessManageTrait
{
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
     * run as daemon process
     */
    public function runAsDaemon()
    {
        if (!$this->supportPC) {
            $this->log("Want to run process as daemon, require 'pcntl','posix' extension!", self::LOG_DEBUG);
        } else {
            // set pid
            $this->pid = ProcessUtil::runAsDaemon();
        }
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
            $this->cliOut->notice("Will only reload task worker(send: SIGUSR2)");
        }

        if (!ProcessUtil::sendSignal($masterPid, $sig)) {
            $this->cliOut->error("The server({$this->name}) worker process reload fail!", -1);
        }

        $this->cliOut->success("The server({$this->name}) worker process reload success.", 0);
    }

    /**
     * Do shutdown server
     * @param  int $pid Master Pid
     * @param  boolean $quit Quit, When stop success?
     */
    protected function stopServer(int $pid, $quit = false)
    {
        $this->stdout("Stop the manager(PID:$pid)");

        ProcessUtil::killAndWait($pid, SIGTERM, 'server');

        $this->stdout(sprintf("\n%s\n"), CliHelper::color("The manager process stopped", CliHelper::FG_GREEN));

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
        ProcessUtil::killAndWait($pid, SIGTERM, 'worker');
    }

    /**
     * @return string
     */
    public function getPidRole()
    {
        return '';
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