<?php

$dsn = 'nats-jetstream://marian:marianos123@localhost:4222/dupas/marianos?batching=10';

$c = parse_url($dsn);

var_dump(explode('/', $c['path']));