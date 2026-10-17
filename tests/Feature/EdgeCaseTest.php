<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Prontuno\Laravel\Exceptions\ApiException;
use Prontuno\Laravel\Exceptions\ConfigurationException;
use Prontuno\Laravel\Exceptions\PaginationException;
use Prontuno\Laravel\Facades\Prontuno as ProntunoFacade;
use Prontuno\Laravel\Prontuno;
use Prontuno\Laravel\Webhooks\Exceptions\InvalidPayloadException;
use Prontuno\Laravel\Webhooks\Exceptions\InvalidSignatureException;
use Prontuno\Laravel\Webhooks\WebhookVerifier;

beforeEach(function () {
    config()->set('prontuno.api_key', 'pno_sk_test');
    config()->set('prontuno.base_url', 'https://api.prontuno.test/v1');
    Sleep::fake();
});

function prontunoConnectionFailure(): Closure
{
    return fn (Request $request) => Create::rejectionFor(
        new ConnectException('Connection refused', $request->toPsrRequest()),
    );
}

it('resolves the client through the facade', function () {
    expect(ProntunoFacade::getFacadeRoot())->toBe(app(Prontuno::class))
        ->and(ProntunoFacade::webhooks())->toBe(app(Prontuno::class)->webhooks());
});

it('rejects an empty base url and negative retries', function (array $arguments, string $message) {
    expect(fn () => new Prontuno(app(Factory::class), 'pno_sk_test', ...$arguments))
        ->toThrow(ConfigurationException::class, $message);
})->with([
    'empty base url' => [['baseUrl' => ' '], 'base URL cannot be empty'],
    'negative retries' => [['retries' => -1], 'retries cannot be negative'],
]);

it('retries connection failures with exponential backoff', function () {
    Http::fake([
        'api.prontuno.test/v1/emails/email_1' => Http::sequence()
            ->pushResponse(prontunoConnectionFailure()(new Request(new GuzzleHttp\Psr7\Request('GET', 'https://api.prontuno.test/v1/emails/email_1'))))
            ->push(['id' => 'email_1']),
    ]);

    expect(app(Prontuno::class)->emails()->retrieve('email_1')['id'])->toBe('email_1');

    Sleep::assertSlept(fn ($duration): bool => $duration->totalMilliseconds === 500.0, 1);
});

it('raises connection failures once retries are exhausted', function () {
    Http::fake(['api.prontuno.test/v1/*' => prontunoConnectionFailure()]);

    app(Prontuno::class)->domains()->retrieve('dom_1');
})->throws(ConnectionException::class);

it('lists one page of webhooks', function () {
    Http::fake([
        'api.prontuno.test/v1/webhooks*' => Http::response([
            'data' => [['id' => 'whk_1']],
            'pagination' => ['limit' => 10, 'has_more' => false, 'next_cursor' => null],
        ]),
    ]);

    expect(app(Prontuno::class)->webhooks()->list(limit: 10, cursor: 'c1')['data'][0]['id'])->toBe('whk_1');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.prontuno.test/v1/webhooks?limit=10&cursor=c1');
});

it('validates pagination limits and cursors', function () {
    expect(fn () => app(Prontuno::class)->domains()->lazy(maxPages: 0))
        ->toThrow(PaginationException::class, 'maxPages to be at least 1');

    Http::fake([
        'api.prontuno.test/v1/domains*' => Http::response(['data' => [['id' => 'a'], 'junk'], 'pagination' => ['limit' => 1, 'has_more' => true, 'next_cursor' => 'next']]),
        'api.prontuno.test/v1/suppressions*' => Http::response(['data' => [], 'pagination' => ['limit' => 1, 'has_more' => true, 'next_cursor' => null]]),
    ]);

    $seen = [];

    expect(function () use (&$seen) {
        foreach (app(Prontuno::class)->domains()->lazy(maxPages: 1) as $domain) {
            $seen[] = $domain['id'];
        }
    })->toThrow(PaginationException::class, 'maximum of 1 pages')
        ->and($seen)->toBe(['a'])
        ->and(fn () => app(Prontuno::class)->suppressions()->lazy()->all())
        ->toThrow(PaginationException::class, 'without a next cursor');
});

it('rejects a negative per-call tolerance', function () {
    (new WebhookVerifier('secret'))->verify('{}', 't=1,v1='.str_repeat('a', 64), tolerance: -1);
})->throws(InvalidArgumentException::class, 'tolerance cannot be negative');

it('rejects signature segments without a value', function () {
    (new WebhookVerifier('secret'))->verify('{}', 't=1,v1');
})->throws(InvalidSignatureException::class, 'malformed');

it('rejects signed payloads that are not event objects', function (string $payload, string $message) {
    $signature = 't=100,v1='.hash_hmac('sha256', "100.{$payload}", 'secret');

    expect(fn () => (new WebhookVerifier('secret', 0))->constructEvent($payload, $signature))
        ->toThrow(InvalidPayloadException::class, $message);
})->with([
    'scalar json' => ['"text"', 'must be a JSON object'],
    'non-object data' => ['{"event_id":"e","webhook_id":"w","type":"email.sent","created_at":"x","data":"no"}', 'requires a data object'],
]);

it('uses the default base url when the configured one is missing or blank', function (?string $configured, string $expected) {
    config()->set('prontuno.base_url', $configured);
    app()->forgetInstance(Prontuno::class);
    Http::fake(['*' => Http::response(['id' => 'email_1'])]);

    app(Prontuno::class)->emails()->retrieve('email_1');

    Http::assertSent(fn (Request $request): bool => $request->url() === "{$expected}/emails/email_1");
})->with([
    'unset' => [null, 'https://api.prontuno.net/v1'],
    'blank' => ['  ', 'https://api.prontuno.net/v1'],
    'custom' => [' https://eu.prontuno.test/v1 ', 'https://eu.prontuno.test/v1'],
]);

it('requires https for the base url', function (string $baseUrl) {
    expect(fn () => new Prontuno(app(Factory::class), 'pno_sk_test', baseUrl: $baseUrl))
        ->toThrow(ConfigurationException::class);
})->with([
    'plain http' => ['http://api.prontuno.net/v1'],
    'other scheme' => ['ftp://api.prontuno.net/v1'],
    'no scheme' => ['api.prontuno.net/v1'],
    'no host' => ['https:///v1'],
    'http lookalike host' => ['http://localhost.example.com/v1'],
]);

it('accepts https and loopback http base urls', function (string $baseUrl) {
    expect(new Prontuno(app(Factory::class), 'pno_sk_test', baseUrl: $baseUrl))->toBeInstanceOf(Prontuno::class);
})->with([
    'https' => ['https://api.prontuno.net/v1'],
    'uppercase scheme' => ['HTTPS://api.prontuno.net/v1'],
    'localhost' => ['http://localhost:8000/v1'],
    'ipv4 loopback' => ['http://127.0.0.1:8000/v1'],
    'ipv6 loopback' => ['http://[::1]:8000/v1'],
]);

it('rejects an insecure base url from config', function () {
    config()->set('prontuno.base_url', 'http://api.prontuno.net/v1');
    app()->forgetInstance(Prontuno::class);

    app(Prontuno::class);
})->throws(ConfigurationException::class, 'must use https');

it('wraps connection failures in the prontuno connection exception', function () {
    Http::fake(['api.prontuno.test/v1/*' => prontunoConnectionFailure()]);

    try {
        app(Prontuno::class)->domains()->retrieve('dom_1');
        $this->fail('Expected a connection exception.');
    } catch (\Prontuno\Laravel\Exceptions\ConnectionException $exception) {
        expect($exception->getMessage())->toStartWith('Could not connect to the Prontuno API')
            ->and($exception->getPrevious())->toBeInstanceOf(ConnectionException::class);
    }
});

it('rejects blank identifiers before sending a request', function (Closure $call) {
    Http::fake();

    expect(fn () => $call(app(Prontuno::class)))->toThrow(ConfigurationException::class, 'identifier is required');

    Http::assertNothingSent();
})->with([
    'email' => fn (Prontuno $client) => $client->emails()->retrieve(''),
    'attachment' => fn (Prontuno $client) => $client->emails()->attachments()->retrieve('email_1', ' '),
    'webhook' => fn (Prontuno $client) => $client->webhooks()->delete(''),
    'api key' => fn (Prontuno $client) => $client->apiKeys()->revoke(''),
]);

it('treats a retried delete that finds the resource gone as success', function (array $responses) {
    $sequence = Http::sequence();

    foreach ($responses as $response) {
        $sequence = $response instanceof Closure
            ? $sequence->pushResponse($response(new Request(new GuzzleHttp\Psr7\Request('DELETE', 'https://api.prontuno.test/v1/webhooks/whk_1'))))
            : $sequence->push(...$response);
    }

    Http::fake(['api.prontuno.test/v1/webhooks/whk_1' => $sequence]);

    app(Prontuno::class)->webhooks()->delete('whk_1');

    Http::assertSentCount(count($responses));
})->with([
    'server error then not found' => [[
        [['error' => ['code' => 'unavailable', 'message' => 'Down', 'retryable' => true]], 503],
        [['error' => ['code' => 'not_found', 'message' => 'Missing', 'retryable' => false]], 404],
    ]],
    'connection failure then already revoked' => [[
        prontunoConnectionFailure(),
        [['error' => ['code' => 'already_revoked', 'message' => 'Revoked', 'retryable' => false]], 409],
    ]],
]);

it('still rejects a delete when no earlier attempt could have applied', function (array $responses) {
    $sequence = Http::sequence();

    foreach ($responses as $response) {
        $sequence = $sequence->push(...$response);
    }

    Http::fake(['api.prontuno.test/v1/suppressions/sup_1' => $sequence]);

    expect(fn () => app(Prontuno::class)->suppressions()->delete('sup_1'))->toThrow(ApiException::class);

    Http::assertSentCount(count($responses));
})->with([
    'not found on the first attempt' => [[
        [['error' => ['code' => 'not_found', 'message' => 'Missing', 'retryable' => false]], 404],
    ]],
    'not found after a rate limit' => [[
        [['error' => ['code' => 'rate_limited', 'message' => 'Slow down', 'retryable' => true]], 429, ['Retry-After' => '0']],
        [['error' => ['code' => 'not_found', 'message' => 'Missing', 'retryable' => false]], 404],
    ]],
    'another conflict after a server error' => [[
        [['error' => ['code' => 'internal_error', 'message' => 'Boom', 'retryable' => true]], 500],
        [['error' => ['code' => 'conflict', 'message' => 'Conflict', 'retryable' => false]], 409],
    ]],
]);
