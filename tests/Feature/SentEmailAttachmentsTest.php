<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Prontuno\Laravel\Exceptions\ApiException;
use Prontuno\Laravel\Exceptions\ConfigurationException;
use Prontuno\Laravel\Exceptions\PaginationException;
use Prontuno\Laravel\Prontuno;

const PRONTUNO_ATTACHMENT = [
    'id' => 'att_01kfzeb03ae59a98z11nhxnrkp',
    'filename' => 'invoice.pdf',
    'content_type' => 'application/pdf',
    'size' => 48213,
    'sha256' => null,
    'content_id' => null,
    'content_disposition' => 'attachment',
    'download_url' => 'https://files.example.com/sent/2026/10/18/att_01kfzeb03ae59a98z11nhxnrkp?token=HS256-abc&expires=1792328400',
    'expires_at' => '2026-10-18T13:00:00.000Z',
];

beforeEach(function () {
    config()->set('prontuno.api_key', 'pno_sk_test');
    config()->set('prontuno.base_url', 'https://api.prontuno.test/v1');
    Sleep::fake();
});

it('returns email bodies and attachments when retrieving an email', function () {
    Http::fake(['api.prontuno.test/v1/emails/email_1' => Http::response([
        'id' => 'email_1',
        'text' => 'Your invoice is attached.',
        'html' => null,
        'attachments' => [PRONTUNO_ATTACHMENT],
    ])]);

    $email = app(Prontuno::class)->emails()->retrieve('email_1');

    expect($email['text'])->toBe('Your invoice is attached.')
        ->and($email['html'])->toBeNull()
        ->and($email['attachments'])->toBe([PRONTUNO_ATTACHMENT]);
});

it('exposes a memoized attachments resource', function () {
    $emails = app(Prontuno::class)->emails();

    expect($emails->attachments())->toBe($emails->attachments());
});

it('lists one page of attachments', function () {
    Http::fake(['api.prontuno.test/v1/emails/email_1/attachments*' => Http::response([
        'data' => [PRONTUNO_ATTACHMENT, ['download_url' => null, 'expires_at' => null] + PRONTUNO_ATTACHMENT],
        'pagination' => ['limit' => 2, 'has_more' => false, 'next_cursor' => null],
    ])]);

    $page = app(Prontuno::class)->emails()->attachments()->list('email_1', 2, 'c1');

    expect($page['data'][1]['download_url'])->toBeNull();
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.prontuno.test/v1/emails/email_1/attachments?limit=2&cursor=c1');
});

it('lazily iterates attachments across pages', function () {
    Http::fake(['api.prontuno.test/v1/emails/email_1/attachments*' => Http::sequence()
        ->push(['data' => [['id' => 'att_1']], 'pagination' => ['limit' => 1, 'has_more' => true, 'next_cursor' => 'c2']])
        ->push(['data' => [['id' => 'att_2']], 'pagination' => ['limit' => 1, 'has_more' => false, 'next_cursor' => null]]),
    ]);

    $ids = app(Prontuno::class)->emails()->attachments()->lazy('email_1', limit: 1)->pluck('id')->all();

    expect($ids)->toBe(['att_1', 'att_2']);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.prontuno.test/v1/emails/email_1/attachments?cursor=c2&limit=1');
});

it('stops on a repeated attachment cursor', function () {
    Http::fake(['*' => Http::response(['data' => [], 'pagination' => ['limit' => 1, 'has_more' => true, 'next_cursor' => 'same']])]);

    app(Prontuno::class)->emails()->attachments()->lazy('email_1')->all();
})->throws(PaginationException::class, 'repeated pagination cursor');

it('retrieves one attachment and encodes identifiers', function () {
    Http::fake(['*' => Http::response(PRONTUNO_ATTACHMENT)]);

    expect(app(Prontuno::class)->emails()->attachments()->retrieve('email_1', 'att_01/x')['filename'])->toBe('invoice.pdf');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.prontuno.test/v1/emails/email_1/attachments/att_01%2Fx');
});

it('retries temporary storage outages', function () {
    Http::fake(['*' => Http::sequence()
        ->push(['error' => ['code' => 'storage_unavailable', 'message' => 'Unavailable', 'retryable' => true]], 503, ['Retry-After' => '0'])
        ->push(PRONTUNO_ATTACHMENT),
    ]);

    expect(app(Prontuno::class)->emails()->attachments()->retrieve('email_1', 'att_1')['id'])->toBe(PRONTUNO_ATTACHMENT['id']);
    Http::assertSentCount(2);
});

it('surfaces attachments that have not finished uploading', function () {
    Http::fake(['*' => Http::response(['error' => [
        'code' => 'attachment_unavailable',
        'message' => 'This attachment is unavailable because its storage is incomplete',
        'retryable' => false,
        'suggested_action' => 'contact_support',
    ]], 403)]);

    try {
        app(Prontuno::class)->emails()->attachments()->retrieve('email_1', 'att_1');
        $this->fail('Expected an API exception.');
    } catch (ApiException $exception) {
        expect($exception->status())->toBe(403)
            ->and($exception->errorCode())->toBe('attachment_unavailable')
            ->and($exception->isRetryable())->toBeFalse();
    }

    Http::assertSentCount(1);
});

it('requires the management key for attachments', function () {
    Http::fake();

    (new Prontuno(app(Factory::class), sendingKey: 'pno_sk_send'))->emails()->attachments()->list('email_1');
})->throws(ConfigurationException::class, 'GET /emails/email_1/attachments requires a management API key');
