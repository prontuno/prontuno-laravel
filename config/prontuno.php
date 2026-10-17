<?php

declare(strict_types=1);

use Illuminate\Support\Env;

return [
    // Sending keys can only send email from one domain (emails()->send(), emails()->sendBatch(),
    // and the prontuno mail driver). Management keys handle every other request.
    'sending_key' => Env::get('PRONTUNO_SENDING_KEY'),

    'management_key' => Env::get('PRONTUNO_MANAGEMENT_KEY'),

    // A single key for every request, used when the role-specific key above is not set.
    'api_key' => Env::get('PRONTUNO_API_KEY'),

    // Leave PRONTUNO_BASE_URL unset (or blank) to use https://api.prontuno.net/v1.
    'base_url' => Env::get('PRONTUNO_BASE_URL'),

    'timeout' => (int) Env::get('PRONTUNO_TIMEOUT', 30),

    'connect_timeout' => (int) Env::get('PRONTUNO_CONNECT_TIMEOUT', 10),

    // Identifies your application in Prontuno's logs, for example "my-app/1.2.0". It is sent
    // after the package's own prontuno-laravel/<version> User-Agent, never instead of it.
    'app_info' => Env::get('PRONTUNO_APP_INFO'),

    // Retries for GET/DELETE requests and sends with an idempotency key.
    'retries' => (int) Env::get('PRONTUNO_RETRIES', 2),

    'webhooks' => [
        'signing_secret' => Env::get('PRONTUNO_WEBHOOK_SIGNING_SECRET'),
        'tolerance' => (int) Env::get('PRONTUNO_WEBHOOK_TOLERANCE', 300),

        // Named secrets for additional endpoints, used as `prontuno.webhook:<name>` middleware.
        'secrets' => [],
    ],
];
