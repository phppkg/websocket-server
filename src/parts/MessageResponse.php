<?php
/**
 * Created by PhpStorm.
 * User: Inhere
 * Date: 2017/3/29 0029
 * Time: 21:32
 */

namespace inhere\webSocket\parts;

use inhere\librarys\traits\TraitArrayAccess;
use inhere\librarys\traits\TraitGetterSetterAccess;
use inhere\webSocket\WebSocketServer;

/**
 * Class MessageResponse
 * webSocket message response
 * @package inhere\webSocket\parts
 */
class MessageResponse implements \ArrayAccess
{
    use TraitArrayAccess;
    use TraitGetterSetterAccess;

    /**
     * @var WebSocketServer
     */
    private $ws;

    /**
     * the sender id
     * @var int
     */
    private $sender;

    /**
     * the receivers id list
     * @var array
     */
    private $receivers;

    /**
     * the excepted id list
     * @var array
     */
    private $excepted;

    /**
     * @var string
     */
    private $data;

    public static function make(string $data = '', int $sender = 0, array $receivers = [], array $excepted = [])
    {
        return new self($data, $sender, $receivers, $excepted);
    }

    /**
     * MessageResponse constructor.
     * @param string $data
     * @param int $sender
     * @param array $receivers
     * @param array $excepted
     */
    public function __construct(string $data = '', int $sender = 0, array $receivers = [], array $excepted = [])
    {
        $this->data = $data;
        $this->sender = $sender;
        $this->receivers = $receivers;
        $this->excepted = $excepted;
    }

    /**
     * @param bool $reset
     * @return int
     */
    public function send(bool $reset = true)
    {
        if ( !$this->ws ) {
            throw new \InvalidArgumentException('Please set the property [ws], is instance of the WebSocketServer');
        }

        $status = $this->ws->send($this->getData(), $this->sender, $this->receivers, $this->excepted);

        if ( $reset ) {
            $this->reset();
        }

        return $status;
    }

    /**
     * reset
     */
    public function reset()
    {
        $this->sender = 0;
        $this->receivers = $this->excepted = $this->data = [];
    }

    public function __destruct()
    {
        $this->ws = null;
        $this->reset();
    }

    /**
     * @return int
     */
    public function getSender(): int
    {
        return $this->sender;
    }

    /**
     * @param int $sender
     * @return $this
     */
    public function bySender(int $sender)
    {
        return $this->setSender($sender);
    }
    public function from(int $sender)
    {
        return $this->setSender($sender);
    }
    public function setSender(int $sender)
    {
        $this->sender = $sender;

        return $this;
    }

    /**
     * @return array
     */
    public function getReceivers(): array
    {
        return $this->receivers;
    }

    /**
     * @param array $receivers
     * @return $this
     */
    public function to(array $receivers)
    {
        return $this->setReceivers($receivers);
    }
    public function setReceivers(array $receivers)
    {
        $this->receivers = $receivers;

        return $this;
    }

    /**
     * @return array
     */
    public function getExcepted(): array
    {
        return $this->excepted;
    }

    /**
     * @param $receiver
     * @return $this
     */
    public function except(int $receiver)
    {
        if ( !in_array($receiver, $this->receivers, true) ) {
            $this->excepted[] = $receiver;
        }

        return $this;
    }

    /**
     * @param array|int $excepted
     * @return $this
     */
    public function setExcepted($excepted)
    {
        $this->excepted = (array)$excepted;

        return $this;
    }

    /**
     * @param string $data
     * @param bool $toLast
     * @return $this
     */
    public function addData(string $data, bool $toLast = true)
    {
        if ( $toLast ) {
            $this->data .= $data;
        } else {
            $this->data = $data . $this->data;
        }

        return $this;
    }

    /**
     * @return string
     */
    public function getData(): string
    {
        return $this->data;
    }

    /**
     * @param string $data
     * @return self
     */
    public function setData(string $data): self
    {
        $this->data = $data;

        return $this;
    }

    /**
     * @return WebSocketServer
     */
    public function getWs(): WebSocketServer
    {
        return $this->ws;
    }

    /**
     * @param WebSocketServer $ws
     * @return self
     */
    public function setWs(WebSocketServer $ws)
    {
        if ( !$this->ws ) {
            $this->ws = $ws;
        }

        return $this;
    }
}
