<?php

namespace Inhere\WebSocket\Helpers\Payload;

use Inhere\WebSocket\Helpers\Frame\Frame;
use Inhere\WebSocket\Helpers\Frame\HybiFrame;

/**
 * Gets a HyBi payload
 */
class HybiPayload extends Payload
{
    protected function getFrame(): Frame
    {
        return new HybiFrame();
    }
}
