<div align="center">
    <h1>Prontuno Laravel</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/prontuno/prontuno-laravel"><img src="https://img.shields.io/packagist/v/prontuno/prontuno-laravel.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/prontuno/prontuno-laravel"><img src="https://img.shields.io/packagist/php-v/prontuno/prontuno-laravel.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://github.com/prontuno/prontuno-laravel/actions"><img alt="GitHub Workflow Status" src="https://img.shields.io/github/actions/workflow/status/prontuno/prontuno-laravel/tests.yml?branch=main&label=Tests&style=flat-square"></a>
</p>

The official Laravel integration for the [Prontuno API](https://prontuno.com/api-reference/general/introduction/): sending email (including a `prontuno` mail transport), managing API resources, and receiving Prontuno webhooks.

## Requirements

- PHP 8.3 or newer
- Laravel 12 or 13
- A Prontuno sending key (to send email) and/or a management key (for everything else)

## Installation

Install the package with Composer:

```bash
composer require prontuno/prontuno-laravel
```

Prontuno has two kinds of API key: a **sending key**, which can only send email from one verified domain, and a **management key**, which can do everything else. Add the ones you need to `.env`:

```dotenv
PRONTUNO_SENDING_KEY=pno_sk_your_sending_key       # emails()->send(), emails()->sendBatch(), and the mail driver
PRONTUNO_MANAGEMENT_KEY=pno_sk_your_management_key # every other request
```

The package picks the right key for each call:

| Call | Key used |
| --- | --- |
| `emails()->send()`, `emails()->sendBatch()`, the `prontuno` mail driver | `PRONTUNO_SENDING_KEY` |
| Everything else, including `emails()->list()` and `emails()->retrieve()` | `PRONTUNO_MANAGEMENT_KEY` |

- **One key for everything:** set `PRONTUNO_API_KEY` instead. It's also used for whichever role-specific key isn't set, so an app that only sends mail can set just a sending key.
- **Missing keys fail early:** calling something whose key isn't configured throws a `ConfigurationException` before any request is made, for example "GET /domains requires a management API key. Set PRONTUNO_MANAGEMENT_KEY (or PRONTUNO_API_KEY)." instead of a `403` from the API.
- **Blank values count as unset**, so `PRONTUNO_MANAGEMENT_KEY=` in `.env` is the same as leaving it out.

Laravel discovers the package automatically. If you need to customize timeouts or the API URL, publish its configuration:

```bash
php artisan vendor:publish --tag=prontuno-config
```

Available environment variables (`PRONTUNO_BASE_URL` falls back to `https://api.prontuno.net/v1` when unset or blank):

```dotenv
PRONTUNO_BASE_URL=https://api.prontuno.net/v1
PRONTUNO_TIMEOUT=30
PRONTUNO_CONNECT_TIMEOUT=10
PRONTUNO_APP_INFO=my-app/1.2.0
PRONTUNO_RETRIES=2
PRONTUNO_WEBHOOK_SIGNING_SECRET=whsec_your_endpoint_secret
PRONTUNO_WEBHOOK_TOLERANCE=300
```

`PRONTUNO_APP_INFO` identifies your application in Prontuno's logs. It's sent after the package's own User-Agent, so requests go out as `prontuno-laravel/0.1.0 my-app/1.2.0`.

## Laravel mail transport

Send any Laravel `Mailable`, notification, or `Mail::raw()` through Prontuno by adding a mailer to `config/mail.php`:

```php
'mailers' => [
    'prontuno' => [
        'transport' => 'prontuno',
    ],

    // Optional: a second sending domain (for example billing.example.com) uses its own sending key.
    'prontuno-billing' => [
        'transport' => 'prontuno',
        'key' => env('PRONTUNO_BILLING_SENDING_KEY'),
    ],
],
```

Send through the second mailer with `Mail::mailer('prontuno-billing')->to($user)->send(new InvoicePaid($invoice));`.

Then set it as the default mailer:

```dotenv
MAIL_MAILER=prontuno
```

The mail driver sends with `PRONTUNO_SENDING_KEY`. Because each sending key belongs to one domain, give each additional sending domain its own mailer with a `key`; management calls keep using `PRONTUNO_MANAGEMENT_KEY`.

From, to, cc, bcc, reply-to (first address only), subject, text and HTML bodies, custom headers, and attachments (including inline attachments) are mapped to the API. After sending, the Prontuno email IDs are available on the `X-Prontuno-Email-ID` header of the sent message, comma separated when there are several recipients. API failures surface as Symfony `TransportException`s. Each send uses the message's `Message-ID` as its idempotency key, so rate limits, server errors, and connection failures are retried without sending the message twice.

## Sending email

Resolve the client through dependency injection:

```php
use Prontuno\Laravel\Prontuno;

final class SendWelcomeEmail
{
    public function __construct(private Prontuno $prontuno) {}

    public function handle(): string
    {
        $response = $this->prontuno->emails()->send([
            'from' => 'Prontuno <hello@mg.example.com>',
            'to' => 'Ada <ada@example.com>',
            'subject' => 'Welcome',
            'html' => '<h1>Welcome to Prontuno</h1>',
            'text' => 'Welcome to Prontuno',
            'reply_to' => 'support@example.com',
            'headers' => [
                'X-Campaign' => 'welcome',
            ],
        ], idempotencyKey: (string) str()->uuid());

        return $response['emails'][0]['id'];
    }
}
```

`to`, `cc`, and `bcc` each accept one mailbox string or an array of mailbox strings, up to 100 recipients in total. Prontuno creates one email record per accepted recipient.

Idempotency keys are optional but strongly recommended for sends. Reuse the same key and request body when retrying an uncertain request; Prontuno retains keys for 24 hours.

### Attachments

Attachment content is base64 encoded. Each email must be 25 MB or less once encoded with its attachments (larger emails are rejected with `413 message_too_large`), and the JSON request body is limited to 32 MB.

```php
$response = $prontuno->emails()->send([
    'from' => 'billing@mg.example.com',
    'to' => 'ada@example.com',
    'subject' => 'Your invoice',
    'text' => 'Your invoice is attached.',
    'attachments' => [[
        'filename' => 'invoice.pdf',
        'content' => base64_encode(file_get_contents(storage_path('invoices/invoice.pdf'))),
        'encoding' => 'base64',
        'content_type' => 'application/pdf',
    ]],
]);
```

Use `content_id` to reference an inline attachment from HTML.

### Batch sending

A batch accepts between 1 and 1,000 email payloads. If any item fails validation, the complete batch is rejected before any email is accepted.

```php
$response = $prontuno->emails()->sendBatch([
    [
        'from' => 'hello@mg.example.com',
        'to' => 'ada@example.com',
        'subject' => 'Hello Ada',
        'text' => 'Hello Ada!',
    ],
    [
        'from' => 'hello@mg.example.com',
        'to' => 'grace@example.com',
        'subject' => 'Hello Grace',
        'text' => 'Hello Grace!',
    ],
], idempotencyKey: (string) str()->uuid());
```

## Emails

```php
// One email record.
$email = $prontuno->emails()->retrieve('email_01kxh2fjhze009s9d337jd18jt');
$email['text']; $email['html']; // stored bodies, null when absent or unavailable

// Cursor-paginated records, optionally filtered by domain and status.
$page = $prontuno->emails()->list(
    limit: 50,
    cursor: $cursor,
    domainId: 'dom_01kxjf1tyje00bgxb0s9g4gfxn',
    status: 'delivered',
);

$nextCursor = $page['pagination']['next_cursor'];

// Or lazily walk every page.
$prontuno->emails()->lazy(status: 'bounced')->each(function (array $email) {
    // ...
});
```

Email statuses are `queued`, `sent`, `deferred`, `delivered`, `bounced`, `expired`, `rejected`, and `complained`.

`retrieve()` returns the full email, including its stored `text` and `html` bodies and its `attachments`. List results contain only the status and metadata, so call `retrieve()` when you need the content.

### Attachments

Attachments on a sent email come with a short-lived signed `download_url` that is valid for one hour, until `expires_at`. Both are `null` while a file hasn't finished uploading, and `emails()->attachments()->retrieve()` throws an `ApiException` with `403 attachment_unavailable` until it has.

```php
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

$page = $prontuno->emails()->attachments()->list('email_01kx...', limit: 20);

$prontuno->emails()->attachments()->lazy('email_01kx...')
    ->each(fn (array $attachment) => logger()->info($attachment['filename']));

// Fetch a fresh link for one attachment, then download it.
$attachment = $prontuno->emails()->attachments()->retrieve('email_01kx...', 'att_01kx...');

if ($attachment['download_url'] !== null) {
    $name = basename($attachment['filename'] ?? $attachment['id']);

    Storage::put('invoices/'.$name, Http::get($attachment['download_url'])->throw()->body());
}
```

Download links expire, so request a fresh one with `emails()->attachments()->retrieve()` (or `emails()->retrieve()`) instead of storing them. Treat `download_url` as opaque: it may be served from Prontuno's CDN or directly from storage, so don't parse it or depend on its host or path. Fetch it directly, without your API key. The file downloads under its original filename. `content_disposition` (`inline` or `attachment`) describes how the file was attached to the email, not how the link is served. A temporary storage outage returns a retryable `503 storage_unavailable`, which the package retries automatically.

### Pagination

`list()` returns one page. Every resource also has `lazy()`, which returns a `LazyCollection` that follows `pagination.next_cursor` for you. It reads at most 1,000 pages by default (`maxPages` changes this) and throws `PaginationException` if the API repeats a cursor.

### Retries

GET and DELETE requests, and sends that carry an idempotency key, are retried up to `PRONTUNO_RETRIES` times (default 2) on `408`, `429`, `5xx`, and connection failures. `Retry-After` is honoured, capped at 60 seconds. Other POST and PATCH requests are never retried automatically. The timeout applies to each attempt. If a DELETE is retried after an attempt whose outcome is unknown (a connection failure, timeout, or `5xx`) and the retry finds the resource already gone (`404`, or `409 already_revoked`), the call succeeds. Blank identifiers throw a `ConfigurationException` before any request is sent.

## Domains

```php
// Create a sending domain and receive its required DNS records.
$domain = $prontuno->domains()->create('mg.example.com');

foreach ($domain['dns_records'] as $record) {
    // Add $record['record_type'], $record['hostname'], and $record['value'] at your DNS provider.
}

$domain = $prontuno->domains()->retrieve($domain['id']);
$page = $prontuno->domains()->list(limit: 50, cursor: $cursor);
```

## Suppressions

```php
$suppression = $prontuno->suppressions()->create('user@example.com');

// Find and delete by either suppression ID or email address.
$suppression = $prontuno->suppressions()->retrieve('user@example.com');
$prontuno->suppressions()->delete($suppression['id']);

// Exact email filtering is optional.
$page = $prontuno->suppressions()->list(
    limit: 50,
    cursor: $cursor,
    email: 'user@example.com',
);
```

The SDK percent-encodes email addresses used as suppression path identifiers, including `@` and `+` characters.

## Webhook endpoints

```php
$webhook = $prontuno->webhooks()->create(
    name: 'Production app',
    url: 'https://example.com/webhooks/prontuno',
    events: ['email.delivered', 'email.bounced', 'email.complained'],
    // domainId: 'dom_01kx...', // scope to one verified domain; omit for a team-wide webhook
);

$secret = $webhook['secret']; // returned only once, so store it securely

$prontuno->webhooks()->retrieve($webhook['id']);
$prontuno->webhooks()->update($webhook['id'], ['is_enabled' => false]);
$prontuno->webhooks()->update($webhook['id'], ['is_global' => false, 'domain_id' => 'dom_01kx...']); // switching scope needs is_global too
$prontuno->webhooks()->rotateSecret($webhook['id']); // the old secret stops working immediately
$prontuno->webhooks()->list();
$prontuno->webhooks()->delete($webhook['id']);
```

### Event types

| Event | Constant | Sent when |
| --- | --- | --- |
| `email.sent` | `WebhookEvent::EMAIL_SENT` | Prontuno's outbound mail server accepted the email for delivery. |
| `email.delivered` | `WebhookEvent::EMAIL_DELIVERED` | The recipient's mail server accepted the email. |
| `email.deferred` | `WebhookEvent::EMAIL_DEFERRED` | The recipient's mail server temporarily refused the email. Delivery is retried. |
| `email.bounced` | `WebhookEvent::EMAIL_BOUNCED` | The recipient's mail server permanently refused the email. |
| `email.expired` | `WebhookEvent::EMAIL_EXPIRED` | Delivery was retried until the retry period ran out without success. |
| `email.rejected` | `WebhookEvent::EMAIL_REJECTED` | Prontuno refused the email before sending it, for example because the recipient is on the suppression list. |
| `email.complained` | `WebhookEvent::EMAIL_COMPLAINED` | The recipient reported the email as spam to their mailbox provider. |

Subscribe to any combination with `events`. Each event's `data` describes the email it relates to.

## API keys

Managing API keys requires a `management` API key. A key's permissions (scopes) come from its type:

| Type | Scopes |
| --- | --- |
| `sending` | `email:send` for one verified domain (`domainId` required) |
| `mcp` | `domains:*`, `emails:read`, `inbound:read`, `suppressions:*`, `webhooks:*`, plus `email:send` with `allowEmailSending: true` and a `domainId` |
| `management` | The `mcp` account scopes without `email:send`, plus `api_keys:*` and `smtp_credentials:*` |

```php
$key = $prontuno->apiKeys()->create('Production sender', 'sending', domainId: 'dom_01kx...');
$plaintext = $key['key']; // returned only once, so store it securely

$prontuno->apiKeys()->create('Support agent', 'mcp');
$prontuno->apiKeys()->create('Agent that sends', 'mcp', domainId: 'dom_01kx...', allowEmailSending: true);
$prontuno->apiKeys()->create('Ops automation', 'management');

$prontuno->apiKeys()->retrieve($key['id']);                    // includes revoked keys
$prontuno->apiKeys()->list(status: 'all', type: 'sending'); // active keys only by default
$prontuno->apiKeys()->lazy(status: 'revoked')->each(fn (array $key) => /* ... */ null);

$prontuno->apiKeys()->revoke($key['id']); // fails immediately; revoking twice returns 409 already_revoked
```

## SMTP credentials

```php
$credential = $prontuno->smtpCredentials()->create('Legacy CRM', 'dom_01kx...');

// Use these as the SMTP login. The password is returned only once.
[$username, $password] = [$credential['username'], $credential['password']];

$reset = $prontuno->smtpCredentials()->resetPassword($credential['id']); // old password stops working
$prontuno->smtpCredentials()->retrieve($credential['id']);
$prontuno->smtpCredentials()->list(status: 'all');
$prontuno->smtpCredentials()->revoke($credential['id']); // SMTP logins fail immediately
```

Creating API keys and SMTP credentials, and resetting passwords, are never retried automatically, so a timeout can't create a duplicate secret.

## Receiving webhooks

Prontuno signs the exact request body with the signing secret shown for the webhook endpoint. Configure that secret when the application receives webhooks through one endpoint:

```dotenv
PRONTUNO_WEBHOOK_SIGNING_SECRET=whsec_your_endpoint_secret
PRONTUNO_WEBHOOK_TOLERANCE=300
```

The simplest option is the `prontuno.webhook` route middleware. It rejects bad signatures with `403` and malformed payloads with `400`, then exposes the verified event:

```php
use Illuminate\Http\Request;
use Prontuno\Laravel\Http\Middleware\VerifyProntunoWebhook;

Route::post('/webhooks/prontuno', function (Request $request) {
    $event = VerifyProntunoWebhook::event($request);

    ProcessProntunoWebhook::dispatch($event->toArray());

    return response()->noContent();
})->middleware('prontuno.webhook');
```

Webhook routes receive requests from Prontuno, not a browser, so exclude them from CSRF verification in `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->validateCsrfTokens(except: ['webhooks/prontuno']);
})
```

For a second endpoint (for example a domain-scoped webhook with its own secret), add the secret to `prontuno.webhooks.secrets` in the published config and reference it by name: `->middleware('prontuno.webhook:billing')`.

Alternatively, inject `WebhookVerifier` into a controller and pass the original Laravel request. Signature verification happens against the raw body before JSON is decoded.

```php
namespace App\Http\Controllers;

use App\Jobs\ProcessProntunoWebhook;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Prontuno\Laravel\Webhooks\Exceptions\WebhookVerificationException;
use Prontuno\Laravel\Webhooks\WebhookVerifier;

final class ProntunoWebhookController
{
    public function __invoke(Request $request, WebhookVerifier $webhooks): Response
    {
        try {
            $event = $webhooks->fromRequest($request);
        } catch (WebhookVerificationException) {
            abort(400, 'Invalid Prontuno webhook.');
        }

        ProcessProntunoWebhook::dispatch($event->toArray());

        return response()->noContent();
    }
}
```

Register the controller, and exclude its path from CSRF verification as shown above:

```php
use App\Http\Controllers\ProntunoWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/prontuno', ProntunoWebhookController::class);
```

The returned `WebhookEvent` exposes `eventId`, `webhookId`, `type`, `createdAt`, `data`, and `signatureTimestamp`. Compare `type` with the `WebhookEvent` constants listed under [Event types](#event-types), for example `$event->is(WebhookEvent::EMAIL_BOUNCED)`.

For applications receiving multiple Prontuno endpoints, pass the appropriate endpoint secret explicitly:

```php
$event = $webhooks->fromRequest($request, secret: $endpointSigningSecret);
```

Prontuno sends `Prontuno-Signature: t=<timestamp>,v1=<signature>`, where the signature is the hexadecimal HMAC-SHA256 of `<timestamp>.<raw-body>`. The default timestamp tolerance is 300 seconds. A tolerance of `0` disables the freshness check and is not recommended for public endpoints.

Webhook requests include these headers:

- `Prontuno-Event`
- `Prontuno-Event-Id`
- `Prontuno-Webhook-Id`
- `Prontuno-Signature` (`t=<unix-seconds>,v1=<hex-hmac>`)
- `Prontuno-Delivery-Attempt`
- `Idempotency-Key` (the event ID)

Webhook delivery is at least once. Use `eventId` as a durable idempotency key in the processing job, and return a successful response for events already processed. Do not parse and re-encode the JSON before verification because even semantically equivalent JSON produces a different signature.

## Switching keys at runtime

Both methods return a new client and leave the configured singleton untouched:

```php
// Send from another domain: swaps only the sending key, keeps the management key.
$billing = $prontuno->withSendingKey($billingSendingKey);
$billing->emails()->send($payload);

// Multi-tenant: one key for every request.
$tenantProntuno = $prontuno->usingApiKey($tenant->prontuno_api_key);
$tenantProntuno->emails()->send($payload);
```

## Error handling

Non-2xx responses and malformed non-JSON success responses throw `ApiException`. It exposes Prontuno's structured error information without discarding the raw response body. Malformed success responses use the `unexpected_response` error code. When the API doesn't supply an error code, `errorCode()` returns `api_error`, and `isRetryable()` is true for `5xx` responses.

```php
use Prontuno\Laravel\Exceptions\ApiException;

try {
    $prontuno->emails()->send($payload, idempotencyKey: $key);
} catch (ApiException $exception) {
    report($exception);

    $exception->status();          // e.g. 429
    $exception->errorCode();       // e.g. rate_limit_exceeded
    $exception->requestId();       // value of x-request-id
    $exception->isRetryable();
    $exception->suggestedAction();
    $exception->retryAfter();      // seconds, when supplied
    $exception->errors();          // field or batch validation details
    $exception->responseBody();    // complete decoded response
    $exception->rawResponseBody(); // original response text
}
```

Transport-level failures throw `Prontuno\Laravel\Exceptions\ConnectionException`, which extends Laravel's `Illuminate\Http\Client\ConnectionException`.

## API reference

| SDK call | API operation | Required scope |
| --- | --- | --- |
| `emails()->send()` | `POST /v1/emails` | `email:send` |
| `emails()->sendBatch()` | `POST /v1/emails/batch` | `email:send` |
| `emails()->list()` | `GET /v1/emails` | `emails:read` or `emails:*` |
| `emails()->retrieve()` | `GET /v1/emails/{id}` | `emails:read` or `emails:*` |
| `emails()->attachments()->list()` | `GET /v1/emails/{id}/attachments` | `emails:read` or `emails:*` |
| `emails()->attachments()->retrieve()` | `GET /v1/emails/{id}/attachments/{attachment_id}` | `emails:read` or `emails:*` |
| `domains()->create()` | `POST /v1/domains` | `domains:*` |
| `domains()->list()` | `GET /v1/domains` | `domains:read` or `domains:*` |
| `domains()->retrieve()` | `GET /v1/domains/{id}` | `domains:read` or `domains:*` |
| `suppressions()->create()` | `POST /v1/suppressions` | `suppressions:*` |
| `suppressions()->list()` | `GET /v1/suppressions` | `suppressions:read` or `suppressions:*` |
| `suppressions()->retrieve()` | `GET /v1/suppressions/{id-or-email}` | `suppressions:read` or `suppressions:*` |
| `suppressions()->delete()` | `DELETE /v1/suppressions/{id-or-email}` | `suppressions:*` |
| `webhooks()->create()` | `POST /v1/webhooks` | `webhooks:*` |
| `webhooks()->list()` | `GET /v1/webhooks` | `webhooks:read` or `webhooks:*` |
| `webhooks()->retrieve()` | `GET /v1/webhooks/{id}` | `webhooks:read` or `webhooks:*` |
| `webhooks()->update()` | `PATCH /v1/webhooks/{id}` | `webhooks:*` |
| `webhooks()->delete()` | `DELETE /v1/webhooks/{id}` | `webhooks:*` |
| `webhooks()->rotateSecret()` | `POST /v1/webhooks/{id}/rotate-secret` | `webhooks:*` |
| `apiKeys()->create()` | `POST /v1/api-keys` | `api_keys:*` |
| `apiKeys()->list()` | `GET /v1/api-keys` | `api_keys:read` or `api_keys:*` |
| `apiKeys()->retrieve()` | `GET /v1/api-keys/{id}` | `api_keys:read` or `api_keys:*` |
| `apiKeys()->revoke()` | `DELETE /v1/api-keys/{id}` | `api_keys:*` |
| `smtpCredentials()->create()` | `POST /v1/smtp-credentials` | `smtp_credentials:*` |
| `smtpCredentials()->list()` | `GET /v1/smtp-credentials` | `smtp_credentials:read` or `smtp_credentials:*` |
| `smtpCredentials()->retrieve()` | `GET /v1/smtp-credentials/{id}` | `smtp_credentials:read` or `smtp_credentials:*` |
| `smtpCredentials()->revoke()` | `DELETE /v1/smtp-credentials/{id}` | `smtp_credentials:*` |
| `smtpCredentials()->resetPassword()` | `POST /v1/smtp-credentials/{id}/reset-password` | `smtp_credentials:*` |

## Testing application code

The SDK uses Laravel's HTTP client, so `Http::fake()` prevents network requests in your tests:

```php
use Illuminate\Support\Facades\Http;

Http::fake([
    'api.prontuno.net/v1/emails' => Http::response([
        'emails' => [[
            'id' => 'email_test',
            'to' => 'ada@example.com',
        ]],
    ], 202),
]);
```

## Contributing

```bash
composer test           # static analysis, formatting, type coverage, and tests
composer test:coverage  # requires pcov or Xdebug; fails below 100% line coverage
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for release history.

## Security

Please review [the security policy](.github/SECURITY.md) to report vulnerabilities.

## License

Prontuno Laravel is open-sourced software licensed under the [MIT license](LICENSE.md).
