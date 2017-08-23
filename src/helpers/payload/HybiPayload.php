<?php

namespace inhere\webSocket\helpers\frame\payload;

use inhere\webSocket\helpers\frame\Frame;
use inhere\webSocket\helpers\frame\HybiFrame;

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
