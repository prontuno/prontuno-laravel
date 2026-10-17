<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Prontuno\Laravel\Http\Middleware\VerifyProntunoWebhook;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    Carbon::setTestNow(Carbon::createFromTimestampUTC(1719230400));
    config()->set('prontuno.webhooks.signing_secret', 'webhook-secret');
    config()->set('prontuno.webhooks.secrets.billing', 'billing-secret');

    Route::post('/hooks', fn (Request $request) => response()->json([
        'type' => VerifyProntunoWebhook::event($request)?->type,
    ]))->middleware('prontuno.webhook');

    Route::post('/hooks/billing', fn () => response()->noContent())->middleware('prontuno.webhook:billing');
    Route::post('/hooks/missing', fn () => response()->noContent())->middleware('prontuno.webhook:missing');
});

afterEach(function () {
    Carbon::setTestNow();
});

function signedProntunoRequest(string $uri, string $secret, ?string $payload = null): TestResponse
{
    $payload ??= '{"event_id":"evt_1","webhook_id":"whk_1","type":"email.complained","created_at":"2026-06-28T11:20:58.412Z","data":{"email_id":"email_1"}}';
    $signature = 't=1719230400,v1='.hash_hmac('sha256', "1719230400.{$payload}", $secret);

    return test()->call('POST', $uri, server: [
        'HTTP_PRONTUNO_SIGNATURE' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ], content: $payload);
}

it('passes verified events to the route', function () {
    signedProntunoRequest('/hooks', 'webhook-secret')
        ->assertOk()
        ->assertJson(['type' => 'email.complained']);
});

it('rejects invalid signatures with 403', function () {
    signedProntunoRequest('/hooks', 'wrong-secret')->assertForbidden();
    test()->postJson('/hooks', ['type' => 'email.sent'])->assertForbidden();
});

it('rejects signed but malformed payloads with 400', function () {
    signedProntunoRequest('/hooks', 'webhook-secret', '{"event_id":"evt_1"}')->assertBadRequest();
});

it('verifies with a named secret', function () {
    signedProntunoRequest('/hooks/billing', 'billing-secret')->assertNoContent();
    signedProntunoRequest('/hooks/billing', 'webhook-secret')->assertForbidden();
});

it('fails loudly when a named secret is not configured', function () {
    test()->withoutExceptionHandling();

    signedProntunoRequest('/hooks/missing', 'webhook-secret');
})->throws(HttpException::class, 'Prontuno webhook secret [missing] is not configured.');
