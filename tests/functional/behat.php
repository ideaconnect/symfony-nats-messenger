<?php

use App\Tests\Behat\NatsSetupContext;
use Behat\Config\Config;
use Behat\Config\Formatter\PrettyFormatter;
use Behat\Config\Profile;
use Behat\Config\Suite;

return (new Config())
    ->withProfile((new Profile('default'))
        ->withFormatter(new PrettyFormatter())
        ->withSuite((new Suite('default'))
            ->withContexts(NatsSetupContext::class)
            ->withPaths('%paths.base%/features')));
