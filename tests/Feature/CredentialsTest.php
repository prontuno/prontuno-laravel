<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Prontuno\Laravel\Exceptions\ApiException;
use Prontuno\Laravel\Facades\Prontuno as ProntunoFacade;
use Prontuno\Laravel\Prontuno;

beforeEach(function () {
    config()->set('prontuno.api_key', 'pno_sk_test');
    config()->set('prontuno.base_url', 'https://api.prontuno.test/v1');
    Sleep::fake();
});

const PRONTUNO_KEY_ID = 'key_01kfzeb03ae59a98z11nhxnrkj';
const PRONTUNO_SMTP_ID = 'smtp_01kfzeb03ae59a98z11nhxnrkm';
const PRONTUNO_DOMAIN_ID = 'dom_01kfzeb03ae59a98z11nhxnrkk';

it('exposes memoized credential resources', function () {
    $client = app(Prontuno::class);

    expect($client->apiKeys())->toBe($client->apiKeys())
        ->and($client->smtpCredentials())->toBe($client->smtpCredentials())
        ->and(ProntunoFacade::apiKeys())->toBe($client->apiKeys());
});

it('creates, finds, and revokes api keys', function () {
    Http::fake([
        'api.prontuno.test/v1/api-keys' => Http::response(['id' => PRONTUNO_KEY_ID, 'key' => 'pno_sk_secret'], 201),
        'api.prontuno.test/v1/api-keys/*' => Http::sequence()
            ->push(['id' => PRONTUNO_KEY_ID, 'scopes' => ['email:send']])
            ->pushStatus(204),
    ]);

    $apiKeys = app(Prontuno::class)->apiKeys();

    expect($apiKeys->create('Sender', 'sending', domainId: PRONTUNO_DOMAIN_ID)['key'])->toBe('pno_sk_secret')
        ->and($apiKeys->retrieve(PRONTUNO_KEY_ID)['scopes'])->toBe(['email:send']);

    $apiKeys->revoke(PRONTUNO_KEY_ID);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.prontuno.test/v1/api-keys'
        && $request->data() === ['name' => 'Sender', 'type' => 'sending', 'domain_id' => PRONTUNO_DOMAIN_ID]);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'https://api.prontuno.test/v1/api-keys/'.PRONTUNO_KEY_ID);
    Http::assertSentCount(3);
});

it('only sends optional api key fields when given', function () {
    Http::fake(['api.prontuno.test/v1/api-keys' => Http::response(['key' => 'k'], 201)]);

    app(Prontuno::class)->apiKeys()->create('Admin', 'management');
    app(Prontuno::class)->apiKeys()->create('Agent', 'mcp', PRONTUNO_DOMAIN_ID, allowEmailSending: true);

    Http::assertSent(fn (Request $request): bool => $request->data() === ['name' => 'Admin', 'type' => 'management']);
    Http::assertSent(fn (Request $request): bool => $request->data() === [
        'name' => 'Agent', 'type' => 'mcp', 'domain_id' => PRONTUNO_DOMAIN_ID, 'allow_email_sending' => true,
    ]);
});

it('lists api keys with status and type filters', function () {
    Http::fake([
        'api.prontuno.test/v1/api-keys*' => Http::sequence()
            ->push(['data' => [['id' => 'key_1']], 'pagination' => ['limit' => 5, 'has_more' => false, 'next_cursor' => null]])
            ->push(['data' => [['id' => 'key_1']], 'pagination' => ['limit' => 1, 'has_more' => true, 'next_cursor' => 'c2']])
            ->push(['data' => [['id' => 'key_2']], 'pagination' => ['limit' => 1, 'has_more' => false, 'next_cursor' => null]]),
    ]);

    $apiKeys = app(Prontuno::class)->apiKeys();
    $apiKeys->list(limit: 5, cursor: 'c1', status: 'all', type: 'mcp');

    expect($apiKeys->lazy(status: 'revoked', type: 'sending', limit: 1)->pluck('id')->all())->toBe(['key_1', 'key_2']);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.prontuno.test/v1/api-keys?limit=5&cursor=c1&status=all&type=mcp');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.prontuno.test/v1/api-keys?cursor=c2&limit=1&status=revoked&type=sending');
});

it('surfaces already revoked conflicts', function () {
    Http::fake(['api.prontuno.test/v1/api-keys/*' => Http::response([
        'error' => ['code' => 'already_revoked', 'message' => 'API key is already revoked.', 'retryable' => false],
    ], 409)]);

    try {
        app(Prontuno::class)->apiKeys()->revoke(PRONTUNO_KEY_ID);
        $this->fail('Expected an API exception.');
    } catch (ApiException $exception) {
        expect($exception->status())->toBe(409)
            ->and($exception->errorCode())->toBe('already_revoked');
    }

    Http::assertSentCount(1);
});

it('manages smtp credentials', function () {
    Http::fake([
        'api.prontuno.test/v1/smtp-credentials' => Http::response(['id' => PRONTUNO_SMTP_ID, 'password' => 'first'], 201),
        'api.prontuno.test/v1/smtp-credentials/*' => Http::sequence()
            ->push(['id' => PRONTUNO_SMTP_ID, 'username' => 'pno_smtp_01kfzeb03ae59a98z11nhxnrkn'])
            ->push(['id' => PRONTUNO_SMTP_ID, 'password' => 'second'])
            ->pushStatus(204),
    ]);

    $credentials = app(Prontuno::class)->smtpCredentials();

    expect($credentials->create('CRM', PRONTUNO_DOMAIN_ID)['password'])->toBe('first')
        ->and($credentials->retrieve(PRONTUNO_SMTP_ID)['username'])->toBe('pno_smtp_01kfzeb03ae59a98z11nhxnrkn')
        ->and($credentials->resetPassword(PRONTUNO_SMTP_ID)['password'])->toBe('second');

    $credentials->revoke(PRONTUNO_SMTP_ID);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.prontuno.test/v1/smtp-credentials'
        && $request->data() === ['name' => 'CRM', 'domain_id' => PRONTUNO_DOMAIN_ID]);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.prontuno.test/v1/smtp-credentials/'.PRONTUNO_SMTP_ID.'/reset-password'
        && $request->body() === '');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'https://api.prontuno.test/v1/smtp-credentials/'.PRONTUNO_SMTP_ID);
    Http::assertSentCount(4);
});

it('lists smtp credentials with a status filter', function () {
    Http::fake([
        'api.prontuno.test/v1/smtp-credentials*' => Http::response(['data' => [['id' => PRONTUNO_SMTP_ID]], 'pagination' => ['limit' => 10, 'has_more' => false, 'next_cursor' => null]]),
    ]);

    $credentials = app(Prontuno::class)->smtpCredentials();
    $credentials->list(limit: 10, cursor: 'c1', status: 'revoked');

    expect($credentials->lazy(status: 'all')->count())->toBe(1);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.prontuno.test/v1/smtp-credentials?limit=10&cursor=c1&status=revoked');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.prontuno.test/v1/smtp-credentials?limit=100&status=all');
});

it('never retries requests that create secrets', function (Closure $call) {
    Http::fake(['*' => Http::response(['error' => ['code' => 'internal_error', 'message' => 'Boom', 'retryable' => true]], 500)]);

    expect(fn () => $call(app(Prontuno::class)))->toThrow(ApiException::class);

    Http::assertSentCount(1);
})->with([
    'api key' => [fn (Prontuno $client) => $client->apiKeys()->create('Admin', 'management')],
    'smtp credential' => [fn (Prontuno $client) => $client->smtpCredentials()->create('CRM', PRONTUNO_DOMAIN_ID)],
    'password reset' => [fn (Prontuno $client) => $client->smtpCredentials()->resetPassword(PRONTUNO_SMTP_ID)],
]);

it('retrieves usage', function () {
    $usage = [
        'subscription' => null,
        'credits' => ['balance' => 25000, 'expires_at' => null],
        'limits' => [
            'domains' => ['used' => 2, 'limit' => 2],
            'shared_teams' => ['used' => 0, 'limit' => 5],
            'api_keys' => ['used' => 3, 'limit' => 10],
            'smtp_credentials' => ['used' => 0, 'limit' => null],
        ],
    ];
    Http::fake(['api.prontuno.test/v1/usage' => Http::response($usage)]);

    expect(ProntunoFacade::usage()->retrieve())->toBe($usage)
        ->and(app(Prontuno::class)->usage())->toBe(app(Prontuno::class)->usage());

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://api.prontuno.test/v1/usage');
});
