<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Prontuno\Laravel\Exceptions\ApiException;
use Prontuno\Laravel\Exceptions\PaginationException;
use Prontuno\Laravel\Prontuno;

beforeEach(function () {
    config()->set('prontuno.api_key', 'pno_sk_test');
    config()->set('prontuno.base_url', 'https://api.prontuno.test/v1');
    Sleep::fake();
});

function prontunoWebhookResponse(array $overrides = []): array
{
    return [
        'id' => 'whk_1',
        'name' => 'Production',
        'url' => 'https://example.com/hooks',
        'events' => ['email.delivered'],
        'domain_id' => null,
        'is_global' => true,
        'is_enabled' => true,
        'created_at' => '2026-06-28T11:20:58.412Z',
        ...$overrides,
    ];
}

it('sends is_global with domain_id when switching a webhook scope', function () {
    Http::fake(['api.prontuno.test/v1/webhooks/whk_1' => Http::response(prontunoWebhookResponse())]);

    $webhooks = app(Prontuno::class)->webhooks();
    $webhooks->update('whk_1', ['is_global' => false, 'domain_id' => 'dom_1']);
    $webhooks->update('whk_1', ['is_global' => true, 'domain_id' => null]);

    Http::assertSent(fn (Request $request): bool => $request->data() === ['is_global' => false, 'domain_id' => 'dom_1']);
    Http::assertSent(fn (Request $request): bool => $request->data() === ['is_global' => true, 'domain_id' => null]);
    Http::assertSentCount(2);
});

it('creates, finds, updates, rotates, and deletes webhooks', function () {
    Http::fake([
        'api.prontuno.test/v1/webhooks' => Http::response(prontunoWebhookResponse(['secret' => 'whsec_1']), 201),
        'api.prontuno.test/v1/webhooks/whk_1/rotate-secret' => Http::response(['id' => 'whk_1', 'secret' => 'whsec_2']),
        'api.prontuno.test/v1/webhooks/whk_1' => Http::sequence()
            ->push(prontunoWebhookResponse())
            ->push(prontunoWebhookResponse(['is_enabled' => false]))
            ->pushStatus(204),
    ]);

    $webhooks = app(Prontuno::class)->webhooks();

    $created = $webhooks->create('Production', 'https://example.com/hooks', ['email.delivered'], domainId: 'dom_1');

    expect($created['secret'])->toBe('whsec_1')
        ->and($webhooks->retrieve('whk_1')['id'])->toBe('whk_1')
        ->and($webhooks->update('whk_1', ['is_enabled' => false])['is_enabled'])->toBeFalse()
        ->and($webhooks->rotateSecret('whk_1')['secret'])->toBe('whsec_2');

    $webhooks->delete('whk_1');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.prontuno.test/v1/webhooks'
        && $request->data() === ['name' => 'Production', 'url' => 'https://example.com/hooks', 'events' => ['email.delivered'], 'domain_id' => 'dom_1']);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
        && $request->data() === ['is_enabled' => false]);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'https://api.prontuno.test/v1/webhooks/whk_1');
    Http::assertSentCount(5);
});

it('lazily paginates every resource', function (string $resource, string $path) {
    Http::fake([
        "api.prontuno.test/v1/{$path}*" => Http::sequence()
            ->push(['data' => [['id' => 'a']], 'pagination' => ['limit' => 1, 'has_more' => true, 'next_cursor' => 'c2']])
            ->push(['data' => [['id' => 'b']], 'pagination' => ['limit' => 1, 'has_more' => false, 'next_cursor' => null]]),
    ]);

    $ids = app(Prontuno::class)->{$resource}()->lazy(limit: 1)->pluck('id')->all();

    expect($ids)->toBe(['a', 'b']);
    Http::assertSent(fn (Request $request): bool => $request->url() === "https://api.prontuno.test/v1/{$path}?cursor=c2&limit=1");
})->with([
    ['emails', 'emails'],
    ['domains', 'domains'],
    ['suppressions', 'suppressions'],
    ['webhooks', 'webhooks'],
]);

it('stops pagination on repeated cursors', function () {
    Http::fake([
        'api.prontuno.test/v1/emails*' => Http::response(['data' => [], 'pagination' => ['limit' => 1, 'has_more' => true, 'next_cursor' => 'same']]),
    ]);

    app(Prontuno::class)->emails()->lazy()->all();
})->throws(PaginationException::class, 'repeated pagination cursor');

it('retries safe requests on transient failures', function () {
    Http::fake([
        'api.prontuno.test/v1/emails/email_1' => Http::sequence()
            ->push(['error' => ['code' => 'rate_limited', 'message' => 'Slow down', 'retryable' => true]], 429, ['Retry-After' => '2'])
            ->push(['error' => ['code' => 'internal_error', 'message' => 'Boom', 'retryable' => true]], 503)
            ->push(['id' => 'email_1']),
    ]);

    expect(app(Prontuno::class)->emails()->retrieve('email_1')['id'])->toBe('email_1');

    Http::assertSentCount(3);
    Sleep::assertSlept(fn ($duration): bool => $duration->totalMilliseconds === 2000.0, 1);
});

it('throws after exhausting retries', function () {
    Http::fake([
        'api.prontuno.test/v1/domains/dom_1' => Http::response(['error' => ['code' => 'internal_error', 'message' => 'Boom', 'retryable' => true]], 500),
    ]);

    try {
        app(Prontuno::class)->domains()->retrieve('dom_1');
        $this->fail('Expected an API exception.');
    } catch (ApiException $exception) {
        expect($exception->status())->toBe(500);
    }

    Http::assertSentCount(3);
});

it('only retries sends that carry an idempotency key', function () {
    Http::fake([
        'api.prontuno.test/v1/emails' => Http::sequence()
            ->push(['error' => ['code' => 'internal_error', 'message' => 'Boom', 'retryable' => true]], 500)
            ->push(['error' => ['code' => 'internal_error', 'message' => 'Boom', 'retryable' => true]], 500)
            ->push(['emails' => [['id' => 'email_1']]], 202),
    ]);

    $email = ['from' => 'a@example.com', 'to' => 'b@example.com', 'subject' => 'Hi', 'text' => 'Hi'];

    expect(fn () => app(Prontuno::class)->emails()->send($email))->toThrow(ApiException::class);
    Http::assertSentCount(1);

    expect(app(Prontuno::class)->emails()->send($email, 'key-1')['emails'][0]['id'])->toBe('email_1');
    Http::assertSentCount(3);
});

it('does not retry webhook updates or client errors', function () {
    Http::fake([
        'api.prontuno.test/v1/webhooks/whk_1' => Http::response(['error' => ['code' => 'internal_error', 'message' => 'Boom', 'retryable' => true]], 500),
        'api.prontuno.test/v1/suppressions/x' => Http::response(['error' => ['code' => 'not_found', 'message' => 'Missing', 'retryable' => false]], 404),
    ]);

    expect(fn () => app(Prontuno::class)->webhooks()->update('whk_1', ['name' => 'x']))->toThrow(ApiException::class)
        ->and(fn () => app(Prontuno::class)->suppressions()->retrieve('x'))->toThrow(ApiException::class);

    Http::assertSentCount(2);
});
