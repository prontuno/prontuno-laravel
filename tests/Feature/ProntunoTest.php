<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Prontuno\Laravel\Exceptions\ApiException;
use Prontuno\Laravel\Exceptions\ConfigurationException;
use Prontuno\Laravel\Prontuno;
use Prontuno\Laravel\ProntunoServiceProvider;

beforeEach(function () {
    config()->set('prontuno.api_key', 'pno_sk_test');
    config()->set('prontuno.base_url', 'https://api.prontuno.test/v1');
});

it('resolves one configured client from the container', function () {
    $client = app(Prontuno::class);

    expect($client)
        ->toBeInstanceOf(Prontuno::class)
        ->toBe(app(Prontuno::class))
        ->and($client->emails())->toBe($client->emails())
        ->and($client->domains())->toBe($client->domains())
        ->and($client->suppressions())->toBe($client->suppressions());
});

it('merges and publishes the package configuration', function () {
    $published = ServiceProvider::pathsToPublish(
        ProntunoServiceProvider::class,
        'prontuno-config',
    );

    expect(config('prontuno.timeout'))->toBe(30)
        ->and(config('prontuno.connect_timeout'))->toBe(10)
        ->and(config('prontuno.webhooks.tolerance'))->toBe(300)
        ->and($published)->toHaveCount(1)
        ->and(array_values($published)[0])->toEndWith('config/prontuno.php');
});

it('sends app info after the package user agent', function (?string $appInfo, string $expected) {
    config()->set('prontuno.app_info', $appInfo);
    app()->forgetInstance(Prontuno::class);
    Http::fake(['*' => Http::response(['data' => [], 'pagination' => ['limit' => 50, 'has_more' => false, 'next_cursor' => null]])]);

    app(Prontuno::class)->domains()->list();

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('User-Agent', $expected));
})->with([
    'unset' => [null, Prontuno::USER_AGENT],
    'blank' => ['  ', Prontuno::USER_AGENT],
    'app info' => [' my-app/1.2.0 ', Prontuno::USER_AGENT.' my-app/1.2.0'],
]);

it('keeps app info when switching keys and rejects multi-line app info', function () {
    Http::fake(['*' => Http::response(['data' => [], 'pagination' => ['limit' => 50, 'has_more' => false, 'next_cursor' => null]])]);
    $client = new Prontuno(app(Factory::class), 'pno_sk_test', 'https://api.prontuno.test/v1', appInfo: 'my-app/1.2.0');

    $client->usingApiKey('pno_sk_other')->domains()->list();

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('User-Agent', Prontuno::USER_AGENT.' my-app/1.2.0'));
    expect(fn () => new Prontuno(app(Factory::class), 'pno_sk_test', appInfo: "my-app\r\nX-Injected: 1"))
        ->toThrow(ConfigurationException::class, 'app info must be a single line');
});

it('sends an email with authentication and an idempotency key', function () {
    Http::fake([
        'api.prontuno.test/v1/emails' => Http::response([
            'emails' => [['id' => 'email_123', 'to' => 'ada@example.com']],
        ], 202),
    ]);

    $response = app(Prontuno::class)->emails()->send([
        'from' => 'hello@example.com',
        'to' => 'ada@example.com',
        'subject' => 'Welcome',
        'html' => '<p>Hello!</p>',
    ], 'send-123');

    expect($response['emails'][0]['id'])->toBe('email_123');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.prontuno.test/v1/emails'
        && $request->hasHeader('Authorization', 'Bearer pno_sk_test')
        && $request->hasHeader('Idempotency-Key', 'send-123')
        && $request['subject'] === 'Welcome');
});

it('batch sends emails', function () {
    Http::fake([
        'api.prontuno.test/v1/emails/batch' => Http::response([
            'emails' => [[['id' => 'email_123', 'to' => 'ada@example.com']]],
        ], 202),
    ]);

    $emails = [[
        'from' => 'hello@example.com',
        'to' => 'ada@example.com',
        'subject' => 'Welcome',
        'text' => 'Hello!',
    ]];

    $response = app(Prontuno::class)->emails()->sendBatch($emails, 'batch-123');

    expect($response['emails'][0][0]['id'])->toBe('email_123');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.prontuno.test/v1/emails/batch'
        && $request->hasHeader('Idempotency-Key', 'batch-123')
        && $request['emails'] === $emails);
});

it('lists and finds emails', function () {
    Http::fake([
        'api.prontuno.test/v1/emails?*' => Http::response([
            'data' => [],
            'pagination' => ['limit' => 25, 'has_more' => false, 'next_cursor' => null],
        ]),
        'api.prontuno.test/v1/emails/email_123' => Http::response([
            'id' => 'email_123',
            'status' => 'delivered',
        ]),
    ]);

    $emails = app(Prontuno::class)->emails();

    expect($emails->list(25, 'next-page', 'dom_123', 'delivered')['pagination']['limit'])->toBe(25)
        ->and($emails->retrieve('email_123')['status'])->toBe('delivered');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://api.prontuno.test/v1/emails?limit=25&cursor=next-page&domain_id=dom_123&status=delivered');
});

it('manages domains', function () {
    Http::fake([
        'api.prontuno.test/v1/domains*' => Http::sequence()
            ->push(['data' => [], 'pagination' => ['limit' => 50, 'has_more' => false, 'next_cursor' => null]])
            ->push(['id' => 'dom_123', 'domain' => 'mg.example.com'], 201)
            ->push([
                'id' => 'dom_123',
                'domain' => 'mg.example.com',
                'dns_records' => [],
            ]),
    ]);

    $domains = app(Prontuno::class)->domains();

    expect($domains->list()['data'])->toBe([])
        ->and($domains->create('mg.example.com')['id'])->toBe('dom_123')
        ->and($domains->retrieve('dom_123')['dns_records'])->toBe([]);
});

it('manages suppressions by id or email', function () {
    Http::fake([
        'api.prontuno.test/v1/suppressions?*' => Http::response([
            'data' => [],
            'pagination' => ['limit' => 50, 'has_more' => false, 'next_cursor' => null],
        ]),
        'api.prontuno.test/v1/suppressions' => Http::response([
            'id' => 'sup_123',
            'email' => 'ada+test@example.com',
        ], 201),
        'api.prontuno.test/v1/suppressions/ada%2Btest%40example.com' => Http::sequence()
            ->push(['id' => 'sup_123', 'email' => 'ada+test@example.com'])
            ->push([], 204),
    ]);

    $suppressions = app(Prontuno::class)->suppressions();

    expect($suppressions->list(50, null, 'ada+test@example.com')['data'])->toBe([])
        ->and($suppressions->create('ada+test@example.com')['id'])->toBe('sup_123')
        ->and($suppressions->retrieve('ada+test@example.com')['email'])->toBe('ada+test@example.com')
        ->and($suppressions->delete('ada+test@example.com'))->toBeNull();
});

it('rejects absolute URLs in low-level request methods', function (string $method) {
    Http::fake();

    $client = app(Prontuno::class);

    $request = match ($method) {
        'get' => fn () => $client->get('https://attacker.example/collect'),
        'post' => fn () => $client->post('https://attacker.example/collect', []),
        'delete' => fn () => $client->delete('https://attacker.example/collect'),
    };

    expect($request)->toThrow(InvalidArgumentException::class, 'relative API path');

    Http::assertNothingSent();
})->with(['get', 'post', 'delete']);

it('rejects unsafe low-level request URI forms', function (string $uri) {
    Http::fake();

    expect(fn () => app(Prontuno::class)->get($uri))
        ->toThrow(InvalidArgumentException::class, 'relative API path');

    Http::assertNothingSent();
})->with([
    'plaintext absolute URL' => 'http://127.0.0.1/admin',
    'leading slash before absolute URL' => '/https://attacker.example/collect',
    'mixed-case scheme' => 'HTTPS://attacker.example/collect',
    'protocol-relative URL' => '//attacker.example/collect',
    'non-HTTP scheme' => 'ftp://attacker.example/collect',
    'user-info authority' => 'https://api.prontuno.test@attacker.example/collect',
    'dot segment' => '../admin',
    'encoded dot segment' => '%2e%2e/admin',
    'double-encoded dot segment' => '%252e%252e/admin',
    'encoded separator traversal' => '%2e%2e%2fadmin',
    'fragment' => 'emails#attacker-controlled',
    'backslash separator' => 'emails\\..\\admin',
    'control character' => "emails\nX-Test: injected",
]);

it('keeps relative low-level request paths on the configured API origin', function () {
    Http::fake([
        'api.prontuno.test/v1/health?*' => Http::response(['ok' => true]),
    ]);

    $response = app(Prontuno::class)->get('/health', ['verbose' => true]);

    expect($response)->toBe(['ok' => true]);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.prontuno.test/v1/health?verbose=1'
        && $request->hasHeader('Authorization', 'Bearer pno_sk_test'));
});

it('throws a structured exception for api errors', function () {
    Http::fake([
        'api.prontuno.test/v1/emails' => Http::response([
            'error' => [
                'code' => 'validation_failed',
                'message' => 'Request validation failed',
                'retryable' => false,
                'suggested_action' => 'fix_input',
            ],
            'errors' => [[
                'code' => 'missing_subject',
                'message' => 'subject is required',
                'field' => 'subject',
            ]],
        ], 400, [
            'x-request-id' => 'req_123',
            'Retry-After' => '3',
        ]),
    ]);

    try {
        app(Prontuno::class)->emails()->send([
            'from' => 'hello@example.com',
            'to' => 'ada@example.com',
            'html' => '<p>Hello!</p>',
        ]);
    } catch (ApiException $exception) {
        expect($exception->getMessage())->toBe('Request validation failed')
            ->and($exception->status())->toBe(400)
            ->and($exception->errorCode())->toBe('validation_failed')
            ->and($exception->requestId())->toBe('req_123')
            ->and($exception->isRetryable())->toBeFalse()
            ->and($exception->suggestedAction())->toBe('fix_input')
            ->and($exception->retryAfter())->toBe(3)
            ->and($exception->errors()[0]['field'])->toBe('subject')
            ->and($exception->responseBody()['error']['code'])->toBe('validation_failed');

        return;
    }

    $this->fail('Expected an ApiException to be thrown.');
});

it('rejects redirects instead of treating them as successful api responses', function () {
    Http::fake([
        'api.prontuno.test/v1/domains?*' => Http::response('redirect', 302),
    ]);

    try {
        app(Prontuno::class)->domains()->list();
    } catch (ApiException $exception) {
        expect($exception->status())->toBe(302)
            ->and($exception->rawResponseBody())->toBe('redirect');

        return;
    }

    $this->fail('Expected an ApiException to be thrown.');
});

it('rejects malformed json returned with a successful status', function () {
    Http::fake([
        'api.prontuno.test/v1/domains?*' => Http::response(
            '<html>maintenance</html>',
            200,
            ['Content-Type' => 'text/html'],
        ),
    ]);

    try {
        app(Prontuno::class)->domains()->list();
    } catch (ApiException $exception) {
        expect($exception->getMessage())->toBe('Prontuno API returned an invalid JSON response with status 200.')
            ->and($exception->status())->toBe(200)
            ->and($exception->errorCode())->toBe('unexpected_response')
            ->and($exception->rawResponseBody())->toBe('<html>maintenance</html>');

        return;
    }

    $this->fail('Expected an ApiException to be thrown.');
});

it('can use a different api key without mutating the configured client', function () {
    Http::fake(['*' => Http::response(['data' => [], 'pagination' => []])]);

    $client = app(Prontuno::class);
    $client->usingApiKey('pno_sk_tenant')->domains()->list();
    $client->domains()->list();

    Http::assertSentInOrder([
        fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer pno_sk_tenant'),
        fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer pno_sk_test'),
    ]);
});

it('rejects an empty api key', function () {
    config()->set('prontuno.api_key', '');

    app(Prontuno::class);
})->throws(ConfigurationException::class, 'Prontuno API key is not configured');
