<?php

namespace Inhere\WebSocket\Parts;

use Inhere\WebSocket\Application;

interface ListenerInterface
{
    public function listen(Application $app);
}
