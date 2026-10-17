<?php

declare(strict_types=1);

namespace Prontuno\Laravel\Exceptions;

use Illuminate\Http\Client\ConnectionException as HttpConnectionException;

/**
 * The Prontuno API could not be reached. Extends Laravel's HTTP client exception, so
 * handlers for Laravel HTTP connection failures also catch it.
 */
class ConnectionException extends HttpConnectionException
{
    //
}
