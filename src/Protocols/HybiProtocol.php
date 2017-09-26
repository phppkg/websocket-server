<?php

namespace Inhere\WebSocket\Protocols;

use Wrench\Payload\HybiPayload;

/**
 * @see http://tools.ietf.org/html/rfc6455#section-5.2
 */
abstract class HybiProtocol extends Protocol
{
    public function getPayload()
    {
        return new HybiPayload();
    }
}
