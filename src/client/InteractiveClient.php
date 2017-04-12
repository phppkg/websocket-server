<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-03-30
 * Time: 13:26
 */

namespace inhere\webSocket\client;

/**
 * Class InteractiveClient
 *  Interactive Terminal environment
 * @package inhere\webSocket\client
 */
class InteractiveClient
{
    const CMD_PREFIX = ':';
    const DEFAULT_CMD = 'send';

    /**
     * command callbacks
     * @var array
     */
    protected $callbacks = [];

    /**
     *
     */
    public function start()
    {

    }

    /**
     */
    public function onOpen()
    {

    }

    /**
     */
    public function onMessage()
    {

    }

    /**
     */
    public function onClose()
    {

    }

    /**
     * @param $command
     * @param callable $cb
     * @return $this
     */
    public function add($command, callable $cb)
    {


        return $this;
    }

    /**
     * `@send`
     *
     */
    public function sendCommand()
    {

    }

    /**
     * `@help` `?`
     *
     */
    public function helpCommand()
    {

    }

    /**
     * `@close`
     */
    public function closeCommand()
    {

    }

}