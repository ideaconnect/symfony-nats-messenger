<?php

use App\Tests\Behat\NatsSetupContext;
use Behat\Config\Config;
use Behat\Config\Formatter\PrettyFormatter;
use Behat\Config\Profile;
use Behat\Config\Suite;
use Behat\Config\TesterOptions;

return (new Config())
    ->withProfile((new Profile('default'))
        ->withFormatter(new PrettyFormatter())
        // Fail on undefined and pending steps. Without this, a step whose definition Behat did not
        // register counts as undefined and the run still exits 0.
        ->withTesterOptions((new TesterOptions())->withStrictResultInterpretation())
        ->withSuite((new Suite('default'))
            ->withContexts(NatsSetupContext::class)
            ->withPaths('%paths.base%/features')));
