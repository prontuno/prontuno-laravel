<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Prontuno\Laravel\Webhooks\Exceptions\InvalidPayloadException;
use Prontuno\Laravel\Webhooks\Exceptions\InvalidSignatureException;
use Prontuno\Laravel\Webhooks\Exceptions\StaleWebhookException;
use Prontuno\Laravel\Webhooks\Exceptions\WebhookVerificationException;
use Prontuno\Laravel\Webhooks\WebhookEvent;
use Prontuno\Laravel\Webhooks\WebhookVerifier;

function prontunoWebhookPayload(): string
{
    return '{"event_id":"evt_123","webhook_id":"whk_123","type":"email.delivered","created_at":"2026-06-28T11:20:58.412Z","data":{"email_id":"email_123"}}';
}

function prontunoWebhookSignature(): string
{
    return 't=1719230400,v1=6a3b8a936b55f30946ea05fb70aa1698d3eb7c8a6c6a41b78ca00a87bd3e9fc8';
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::createFromTimestampUTC(1719230400));
});

afterEach(function () {
    Carbon::setTestNow();
});

it('verifies and parses the outgoing webhook contract', function () {
    $event = (new WebhookVerifier('webhook-secret'))->constructEvent(
        prontunoWebhookPayload(),
        prontunoWebhookSignature(),
    );

    expect($event)
        ->toBeInstanceOf(WebhookEvent::class)
        ->and($event->eventId)->toBe('evt_123')
        ->and($event->webhookId)->toBe('whk_123')
        ->and($event->type)->toBe(WebhookEvent::EMAIL_DELIVERED)
        ->and($event->createdAt)->toBe('2026-06-28T11:20:58.412Z')
        ->and($event->data)->toBe(['email_id' => 'email_123'])
        ->and($event->signatureTimestamp)->toBe(1719230400)
        ->and($event->is('email.delivered'))->toBeTrue()
        ->and($event->is('email.bounced'))->toBeFalse()
        ->and($event->toArray()['event_id'])->toBe('evt_123');
});

it('constructs an event from a Laravel request without resolving the api client', function () {
    config()->set('prontuno.webhooks.signing_secret', 'webhook-secret');

    $request = Request::create(
        '/webhooks/prontuno',
        'POST',
        server: ['HTTP_PRONTUNO_SIGNATURE' => prontunoWebhookSignature()],
        content: prontunoWebhookPayload(),
    );

    $event = app(WebhookVerifier::class)->fromRequest($request);

    expect($event->eventId)->toBe('evt_123');
});

it('accepts a per-endpoint signing secret override', function () {
    $event = (new WebhookVerifier('wrong-secret'))->constructEvent(
        prontunoWebhookPayload(),
        prontunoWebhookSignature(),
        secret: 'webhook-secret',
    );

    expect($event->webhookId)->toBe('whk_123');
});

it('can verify a payload without parsing its event envelope', function () {
    $result = (new WebhookVerifier('webhook-secret'))->verify(
        prontunoWebhookPayload(),
        prontunoWebhookSignature(),
    );

    expect($result)->toBeNull();
});

it('accepts any matching v1 signature in the signature header', function () {
    $signature = prontunoWebhookSignature().',v1=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    $event = (new WebhookVerifier('webhook-secret'))->constructEvent(
        prontunoWebhookPayload(),
        $signature,
    );

    expect($event->eventId)->toBe('evt_123');
});

it('rejects a Laravel request without a signature header', function () {
    $request = Request::create(
        '/webhooks/prontuno',
        'POST',
        content: prontunoWebhookPayload(),
    );

    (new WebhookVerifier('webhook-secret'))->fromRequest($request);
})->throws(InvalidSignatureException::class, 'Prontuno-Signature header is missing');

it('rejects missing, malformed, and incorrect signatures', function (?string $signature) {
    (new WebhookVerifier('webhook-secret'))->constructEvent(
        prontunoWebhookPayload(),
        $signature ?? '',
    );
})->with([
    'missing signature' => null,
    'missing timestamp' => 'v1=6a3b8a936b55f30946ea05fb70aa1698d3eb7c8a6c6a41b78ca00a87bd3e9fc8',
    'malformed timestamp' => 't=now,v1=6a3b8a936b55f30946ea05fb70aa1698d3eb7c8a6c6a41b78ca00a87bd3e9fc8',
    'malformed signature' => 't=1719230400,v1=not-a-signature',
    'incorrect signature' => 't=1719230400,v1=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
])->throws(InvalidSignatureException::class);

it('rejects webhook signatures outside the timestamp tolerance', function () {
    Carbon::setTestNow(Carbon::createFromTimestampUTC(1719230701));

    (new WebhookVerifier('webhook-secret', 300))->constructEvent(
        prontunoWebhookPayload(),
        prontunoWebhookSignature(),
    );
})->throws(StaleWebhookException::class);

it('rejects invalid json after verifying its signature', function () {
    $payload = '{invalid-json';
    $signature = 't=1719230400,v1='.hash_hmac('sha256', "1719230400.{$payload}", 'webhook-secret');

    (new WebhookVerifier('webhook-secret'))->constructEvent($payload, $signature);
})->throws(InvalidPayloadException::class);

it('rejects a signed payload with an invalid event envelope', function () {
    $payload = '{"event_id":"evt_123","data":{}}';
    $signature = 't=1719230400,v1='.hash_hmac('sha256', "1719230400.{$payload}", 'webhook-secret');

    (new WebhookVerifier('webhook-secret'))->constructEvent($payload, $signature);
})->throws(InvalidPayloadException::class);

it('requires a configured or explicit signing secret', function () {
    (new WebhookVerifier)->constructEvent(
        prontunoWebhookPayload(),
        prontunoWebhookSignature(),
    );
})->throws(WebhookVerificationException::class, 'Prontuno webhook signing secret is not configured');

it('rejects a negative timestamp tolerance', function () {
    new WebhookVerifier('webhook-secret', -1);
})->throws(InvalidArgumentException::class, 'timestamp tolerance cannot be negative');

it('skips malformed v1 values when another v1 value matches', function () {
    $event = (new WebhookVerifier('webhook-secret'))->constructEvent(
        prontunoWebhookPayload(),
        't=1719230400,v1=not-a-signature,'.substr(prontunoWebhookSignature(), strlen('t=1719230400,')),
    );

    expect($event->eventId)->toBe('evt_123');
});

it('skips the timestamp check when the tolerance is zero', function () {
    Carbon::setTestNow(Carbon::createFromTimestampUTC(1819230400));

    $event = (new WebhookVerifier('webhook-secret', 0))->constructEvent(
        prontunoWebhookPayload(),
        prontunoWebhookSignature(),
    );

    expect($event->eventId)->toBe('evt_123');
});

it('rejects signed json lists and list data', function (string $payload) {
    $signature = 't=1719230400,v1='.hash_hmac('sha256', "1719230400.{$payload}", 'webhook-secret');

    (new WebhookVerifier('webhook-secret'))->constructEvent($payload, $signature);
})->with([
    'list payload' => '[{"event_id":"evt_123"}]',
    'list data' => '{"event_id":"evt_123","webhook_id":"whk_123","type":"email.sent","created_at":"x","data":[1,2]}',
])->throws(InvalidPayloadException::class);

it('accepts an empty data object', function () {
    $payload = '{"event_id":"evt_123","webhook_id":"whk_123","type":"email.sent","created_at":"x","data":{}}';
    $signature = 't=1719230400,v1='.hash_hmac('sha256', "1719230400.{$payload}", 'webhook-secret');

    expect((new WebhookVerifier('webhook-secret'))->constructEvent($payload, $signature)->data)->toBe([]);
});
