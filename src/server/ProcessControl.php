<?php
/**
 * Created by PhpStorm.
 * User: Inhere
 * Date: 2017/4/9 0009
 * Time: 23:39
 */

namespace inhere\webSocket\server;

/**
 * Class ProcessControl
 * @package inhere\webSocket\server
 *
 * @method print($message, $nl = true, $exit = false)
 */
trait ProcessControl
{
    /**
     * @var bool
     */
    protected $loadedPcntl;

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

    public function spawn($number)
    {
        if (!$this->hasPcntl()) {
            $this->print("Start multi workers process, require 'pcntl' extension!");

            return -3;
        }

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
    public function asDaemon()
    {
        if (!$this->hasPcntl()) {
            $this->print("[NOTICE] Want to run process as daemon, require 'pcntl' extension!");

            return false;
        }

        umask(0);
        // Forks the currently running process
        $pid = pcntl_fork();

        // 父进程和子进程都会执行下面代码
        if ( $pid === -1) {
            /* fork failed, exit */
            $this->print('fork sub-process failure!', true, - __LINE__);
        }

        if ($pid) {
            // 父进程会得到子进程号，所以这里是父进程执行的逻辑
            // 即 fork 进程成功，这是在父进程（自己通过命令行调用启动的进程）内，得到了fork的进程(子进程)的pid

            // pcntl_wait($status); //等待子进程中断，防止子进程成为僵尸进程。

            // 关闭当前进程，所有逻辑交给在后台的子进程处理 -- 在后台运行
            $this->print("Server run on the background.[PID: $pid]", true, 0);

        } else {
            // fork 进程成功，子进程得到的$pid为0, 所以这里是子进程执行的逻辑。
            /* child becomes our daemon */
            posix_setsid();

            chdir('/');
            umask(0);

            // return posix_getpid();
        }

        return true;
    }

    /**
     * Change the identity to a non-priv user
     * @param int $uid
     * @param int $gid
     * @return $this
     */
    public function changeIdentity(int $uid, int $gid )
    {
        if (!$this->hasPcntl()) {
            $this->print("[NOTICE] Want to change the process user info, require 'pcntl' extension!");

            return $this;
        }

        if( !posix_setgid( $gid ) ) {
            $this->print("Unable to set group id to [$gid]", true, - __LINE__);
        }

        if( !posix_setuid( $uid ) ) {
            $this->print("Unable to set user id to [$uid]", true, - __LINE__);
        }

        return $this;
    }

    public function installSignals()
    {
        if (!$this->hasPcntl()) {
            $this->print("[NOTICE] Want to register the signal handler, require 'pcntl' extension!");

            return $this;
        }

        /* handle signals */
        // eg: 向当前进程发送SIGUSR1信号
        // posix_kill(posix_getpid(), SIGUSR1);

        // stop
        pcntl_signal(SIGINT, [ $this, 'signalHandler'], false);
        // reload
        pcntl_signal(SIGUSR1, [ $this, 'signalHandler'], false);
        // status
        pcntl_signal(SIGUSR2, [ $this, 'signalHandler'], false);
        // ignore
        pcntl_signal(SIGPIPE, SIG_IGN, false);

        pcntl_signal(SIGCHLD, [ $this, 'signalHandler'], false);

        return $this;
    }

    /**
     * Signal handler
     * @param $signal
     */
    public function signalHandler($signal)
    {
        switch ($signal) {
            // Stop.
            case SIGTERM:
            case SIGINT:
                self::stopServer();
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
     * @return bool
     */
    public function isLoadedPcntl(): bool
    {
        return $this->hasPcntl();
    }

    /**
     * @return bool
     */
    public function hasPcntl()
    {
        if (!is_bool($this->loadedPcntl)) {
            $this->loadedPcntl = function_exists('pcntl_fork');
        }

        return $this->loadedPcntl;
    }

}
