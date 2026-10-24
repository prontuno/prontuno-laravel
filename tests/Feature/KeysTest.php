<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Prontuno\Laravel\Exceptions\ConfigurationException;
use Prontuno\Laravel\Prontuno;

const PRONTUNO_TEST_EMAIL = ['from' => 'a@example.com', 'to' => 'b@example.com', 'subject' => 'Hi', 'text' => 'Hi'];

beforeEach(function () {
    config()->set('prontuno.base_url', 'https://api.prontuno.test/v1');
    config()->set('prontuno.api_key', null);
    config()->set('prontuno.sending_key', 'pno_sk_send');
    config()->set('prontuno.management_key', 'pno_sk_manage');
    app()->forgetInstance(Prontuno::class);

    Http::fake([
        'api.prontuno.test/v1/emails' => Http::response(['emails' => [['id' => 'email_1']]], 202),
        'api.prontuno.test/v1/emails/batch' => Http::response(['emails' => [[['id' => 'email_1']]]], 202),
        '*' => Http::response(['data' => [], 'pagination' => ['limit' => 50, 'has_more' => false, 'next_cursor' => null]]),
    ]);
});

/**
 * @return list<string>
 */
function prontunoKeysUsed(): array
{
    return Http::recorded()
        ->map(fn (array $pair): string => $pair[0]->method().' '.parse_url($pair[0]->url(), PHP_URL_PATH).' '.$pair[0]->header('Authorization')[0])
        ->values()
        ->all();
}

it('sends with the sending key and uses the management key for everything else', function () {
    $client = app(Prontuno::class);

    $client->emails()->send(PRONTUNO_TEST_EMAIL);
    $client->emails()->sendBatch([PRONTUNO_TEST_EMAIL]);
    $client->emails()->list();
    $client->domains()->list();
    $client->usage()->retrieve();

    expect(prontunoKeysUsed())->toBe([
        'POST /v1/emails Bearer pno_sk_send',
        'POST /v1/emails/batch Bearer pno_sk_send',
        'GET /v1/emails Bearer pno_sk_manage',
        'GET /v1/domains Bearer pno_sk_manage',
        'GET /v1/usage Bearer pno_sk_manage',
    ]);
});

it('sends laravel mail with the sending key', function () {
    config()->set('mail.mailers.prontuno', ['transport' => 'prontuno']);

    Mail::mailer('prontuno')->raw('Hi', fn (Message $message) => $message->from('a@example.com')->to('b@example.com')->subject('Hi'));

    expect(prontunoKeysUsed())->toBe(['POST /v1/emails Bearer pno_sk_send']);
});

it('uses a mailer specific sending key without touching the management key', function () {
    config()->set('mail.mailers.billing', ['transport' => 'prontuno', 'key' => 'pno_sk_billing']);

    Mail::mailer('billing')->raw('Hi', fn (Message $message) => $message->from('a@example.com')->to('b@example.com')->subject('Hi'));
    app(Prontuno::class)->domains()->list();

    expect(prontunoKeysUsed())->toBe([
        'POST /v1/emails Bearer pno_sk_billing',
        'GET /v1/domains Bearer pno_sk_manage',
    ]);
});

it('falls back to the single api key for a missing role', function () {
    config()->set('prontuno.api_key', 'pno_sk_shared');
    config()->set('prontuno.management_key', '');
    app()->forgetInstance(Prontuno::class);

    app(Prontuno::class)->emails()->send(PRONTUNO_TEST_EMAIL);
    app(Prontuno::class)->webhooks()->list();

    expect(prontunoKeysUsed())->toBe([
        'POST /v1/emails Bearer pno_sk_send',
        'GET /v1/webhooks Bearer pno_sk_shared',
    ]);
});

it('fails before any request when the needed key is missing', function () {
    $sendOnly = new Prontuno(app(Factory::class), sendingKey: 'pno_sk_send');
    $manageOnly = new Prontuno(app(Factory::class), managementKey: ' pno_sk_manage ');

    expect(fn () => $sendOnly->domains()->list())
        ->toThrow(ConfigurationException::class, 'GET /domains requires a management API key. Set PRONTUNO_MANAGEMENT_KEY (or PRONTUNO_API_KEY).')
        ->and(fn () => $sendOnly->emails()->retrieve('email_1'))
        ->toThrow(ConfigurationException::class, 'requires a management API key')
        ->and(fn () => $manageOnly->emails()->send(PRONTUNO_TEST_EMAIL))
        ->toThrow(ConfigurationException::class, 'POST /emails requires a sending API key. Set PRONTUNO_SENDING_KEY');

    Http::assertNothingSent();
});

it('requires at least one non-blank key', function () {
    new Prontuno(app(Factory::class), apiKey: ' ', sendingKey: '', managementKey: null);
})->throws(ConfigurationException::class, 'Set PRONTUNO_SENDING_KEY and PRONTUNO_MANAGEMENT_KEY, or PRONTUNO_API_KEY');

it('uses one key for everything after usingApiKey', function () {
    $tenant = app(Prontuno::class)->usingApiKey('pno_sk_tenant');

    $tenant->emails()->send(PRONTUNO_TEST_EMAIL);
    $tenant->domains()->list();

    expect(prontunoKeysUsed())->toBe([
        'POST /v1/emails Bearer pno_sk_tenant',
        'GET /v1/domains Bearer pno_sk_tenant',
    ]);
});

it('switches only the sending key with withSendingKey', function () {
    $client = app(Prontuno::class);
    $billing = $client->withSendingKey('pno_sk_billing');

    $billing->emails()->send(PRONTUNO_TEST_EMAIL);
    $billing->domains()->list();
    $client->emails()->send(PRONTUNO_TEST_EMAIL);

    expect($billing)->not->toBe($client)
        ->and(prontunoKeysUsed())->toBe([
            'POST /v1/emails Bearer pno_sk_billing',
            'GET /v1/domains Bearer pno_sk_manage',
            'POST /v1/emails Bearer pno_sk_send',
        ])
        ->and(fn () => $client->withSendingKey(' '))->toThrow(ConfigurationException::class);
});
