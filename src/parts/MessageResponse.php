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
     * @var array
     */
    private $data;

    public static function make(array $data = [], int $sender = 0, array $receivers = [], array $excepted = [])
    {
        return new self($data, $sender, $receivers, $excepted);
    }

    /**
     * MessageResponse constructor.
     * @param array $data
     * @param int $sender
     * @param array $receivers
     * @param array $excepted
     */
    public function __construct(array $data = [], int $sender = 0, array $receivers = [], array $excepted = [])
    {
        $this->data = $data;
        $this->sender = $sender;
        $this->receivers = $receivers;
        $this->excepted = $excepted;
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
     */
    public function bySender(int $sender)
    {
        $this->sender = $sender;
    }
    public function setSender(int $sender)
    {
        $this->sender = $sender;
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
     * @param array $excepted
     * @return $this
     */
    public function setExcepted($excepted)
    {
        $this->excepted = (array)$excepted;

        return $this;
    }

    /**
     * @param string $data
     * @param string $key define a name for the data
     * @param bool $replace
     * @return $this
     */
    public function addData(string $data, string $key = '', $replace = false)
    {
        if ( $this->data === null ) {
            $this->data = [];
        }

        if ($key && (!isset($this->data[$key]) || $replace)) {
            $this->data[$key] = $data;
        } else {
            $this->data[] = $data;
        }

        return $this;
    }

    /**
     * @param bool $toString
     * @return array|string
     */
    public function getData(bool $toString = false)
    {
        return $toString ? implode('', $this->data) : $this->data;
    }

    /**
     * @param string|array $data
     * @return $this
     */
    public function setData($data)
    {
        $this->data = (array)$data;

        return $this;
    }


}
