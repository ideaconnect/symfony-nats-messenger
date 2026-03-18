<?php

namespace App\Console;

use Symfony\Bundle\FrameworkBundle\Console\Application as BaseApplication;
use Symfony\Component\Console\Command\Command;

final class Application extends BaseApplication
{
    public function add(Command $command): ?Command
    {
        return $this->addCommand($command);
    }
}