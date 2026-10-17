<?php

declare(strict_types=1);

use Illuminate\Http\Client\Response;
use Prontuno\Laravel\Exceptions\ApiException;

it('provides fallbacks for a non-json api error', function () {
    $psrResponse = new GuzzleHttp\Psr7\Response(503, ['x-request-id' => 'req_456'], 'unavailable');
    $exception = ApiException::fromResponse(new Response($psrResponse));

    expect($exception->getMessage())->toBe('Prontuno API request failed with status 503.')
        ->and($exception->status())->toBe(503)
        ->and($exception->errorCode())->toBe('api_error')
        ->and($exception->requestId())->toBe('req_456')
        ->and($exception->isRetryable())->toBeTrue()
        ->and($exception->suggestedAction())->toBeNull()
        ->and($exception->retryAfter())->toBeNull()
        ->and($exception->errors())->toBe([])
        ->and($exception->responseBody())->toBe([])
        ->and($exception->rawResponseBody())->toBe('unavailable');
});
