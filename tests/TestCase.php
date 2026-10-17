<?php

declare(strict_types=1);

namespace Prontuno\Laravel\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Prontuno\Laravel\ProntunoServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            ProntunoServiceProvider::class,
        ];
    }
}
