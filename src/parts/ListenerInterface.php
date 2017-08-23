<?php

namespace inhere\webSocket\parts;

use inhere\webSocket\Application;

interface ListenerInterface
{
    public function listen(Application $app);
}
