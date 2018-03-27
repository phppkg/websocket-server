<?php

namespace Inhere\WebSocket\Part;

use Inhere\WebSocket\Application;

interface ListenerInterface
{
    public function listen(Application $app);
}
