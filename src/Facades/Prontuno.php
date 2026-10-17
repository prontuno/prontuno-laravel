<?php

declare(strict_types=1);

namespace Prontuno\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Prontuno\Laravel\Prontuno
 */
class Prontuno extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Prontuno\Laravel\Prontuno::class;
    }
}
