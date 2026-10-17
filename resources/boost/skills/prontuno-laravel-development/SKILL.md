---
name: prontuno-laravel-development
description: >
  Configure and use Prontuno Laravel to send email, manage API resources,
  and securely receive signed Prontuno webhooks in Laravel applications.
license: MIT
metadata:
  author: Prontuno Team
---

# Prontuno Laravel

Use this skill when a Laravel application integrates `prontuno/prontuno-laravel`.

## Primary Goal

Configure the Prontuno API client and securely verify incoming webhook requests using the package's public contracts.

## Workflow

1. Install `prontuno/prontuno-laravel` and set `PRONTUNO_SENDING_KEY` (domain-bound, used by `emails()->send()`, `emails()->sendBatch()` and the mail driver) and `PRONTUNO_MANAGEMENT_KEY` (every other request). `PRONTUNO_API_KEY` is a single-key fallback for whichever role isn't set.
2. Inject `Prontuno\Laravel\Prontuno` through Laravel's container.
3. Use `emails()`, `domains()`, `suppressions()`, `webhooks()`, `apiKeys()`, or `smtpCredentials()` for the required operation.
4. Supply a unique idempotency key to `emails()->send()` and `emails()->sendBatch()` when the operation may be retried.
5. Catch `Prontuno\Laravel\Exceptions\ApiException` and use `status()`, `errorCode()`, `isRetryable()`, `retryAfter()`, and `errors()` to decide how the application should respond. Use `rawResponseBody()` when diagnosing a malformed upstream response with the `unexpected_response` code. Connection failures throw `Prontuno\Laravel\Exceptions\ConnectionException`.
6. In multi-tenant applications, call `usingApiKey($key)` to obtain an isolated client for a tenant.
7. In tests, use Laravel's `Http::fake()` with Prontuno API URLs; no SDK-specific fake is required.
8. To receive webhooks, set `PRONTUNO_WEBHOOK_SIGNING_SECRET`, inject `WebhookVerifier`, and call `fromRequest($request)` before dispatching application work.
9. Deduplicate webhook processing with `WebhookEvent::$eventId`; Prontuno delivery is at least once.

Publish `prontuno-config` only when the application must override the base URL, request timeout, connect timeout, or app info (`PRONTUNO_APP_INFO`, sent after the package's own User-Agent).

## Public API

- `emails()->send(array $email, ?string $idempotencyKey = null)`
- `emails()->sendBatch(array $emails, ?string $idempotencyKey = null)`
- `emails()->list(int $limit = 50, ?string $cursor = null, ?string $domainId = null, ?string $status = null)`
- `emails()->retrieve(string $emailId)` (includes `text`, `html`, and `attachments` with one-hour `download_url`s)
- `emails()->lazy(?string $domainId = null, ?string $status = null, int $limit = 100, int $maxPages = 1000)`
- `emails()->attachments()->list(string $emailId, int $limit = 50, ?string $cursor = null)`
- `emails()->attachments()->lazy(string $emailId, int $limit = 100, int $maxPages = 1000)`
- `emails()->attachments()->retrieve(string $emailId, string $attachmentId)` (fresh `download_url`; throws a 403 `attachment_unavailable` while the file hasn't finished uploading)
- `domains()->create(string $domain)`
- `domains()->list(int $limit = 50, ?string $cursor = null)`
- `domains()->retrieve(string $domainId)`
- `suppressions()->create(string $email)`
- `suppressions()->list(int $limit = 50, ?string $cursor = null, ?string $email = null)`
- `suppressions()->retrieve(string $suppressionIdOrEmail)`
- `suppressions()->delete(string $suppressionIdOrEmail)`
- `webhooks()->create(string $name, string $url, array $events, ?string $domainId = null, ?bool $isGlobal = null, ?bool $isEnabled = null)` (the response's `secret` is returned only once)
- `webhooks()->list(int $limit = 50, ?string $cursor = null)` and `webhooks()->lazy(int $limit = 100, int $maxPages = 1000)`
- `webhooks()->retrieve(string $webhookId)`
- `webhooks()->update(string $webhookId, array $changes)` (send `is_global` with `domain_id` to switch between all domains and one domain)
- `webhooks()->rotateSecret(string $webhookId)` and `webhooks()->delete(string $webhookId)`
- `apiKeys()->create(string $name, string $type, ?string $domainId = null, bool $allowEmailSending = false)` (the plaintext `key` is returned only once)
- `apiKeys()->list(int $limit = 50, ?string $cursor = null, ?string $status = null, ?string $type = null)`, `apiKeys()->lazy(...)`, `apiKeys()->retrieve(string $apiKeyId)`, `apiKeys()->revoke(string $apiKeyId)`
- `smtpCredentials()->create(string $name, string $domainId)` (the `password` is returned only once)
- `smtpCredentials()->list(int $limit = 50, ?string $cursor = null, ?string $status = null)`, `smtpCredentials()->lazy(...)`, `smtpCredentials()->retrieve(string $smtpCredentialId)`, `smtpCredentials()->resetPassword(string $smtpCredentialId)`, `smtpCredentials()->revoke(string $smtpCredentialId)`
- `WebhookVerifier::fromRequest(Request $request, ?string $secret = null, ?int $tolerance = null)`
- `WebhookVerifier::constructEvent(string $payload, string $signature, ?string $secret = null, ?int $tolerance = null)`
- `WebhookVerifier::verify(string $payload, string $signature, ?string $secret = null, ?int $tolerance = null)`

## References

- `README.md` for payload fields, attachments, pagination, scopes, and error handling
- `config/prontuno.php` for supported environment overrides

## Examples

```php
use Prontuno\Laravel\Prontuno;

final class SendReceipt
{
    public function __construct(private Prontuno $prontuno) {}

    public function handle(string $recipient, string $idempotencyKey): string
    {
        $response = $this->prontuno->emails()->send([
            'from' => 'billing@mg.example.com',
            'to' => $recipient,
            'subject' => 'Your receipt',
            'html' => '<p>Thanks for your order.</p>',
        ], $idempotencyKey);

        return $response['emails'][0]['id'];
    }
}
```

## Anti-patterns

- Do not call undocumented endpoints through the client's low-level request methods when a resource method exists.
- Do not retry sends with a new idempotency key after an uncertain response; reuse the original key and body.
- Do not store API keys in source control or logs.
- Do not manually concatenate suppression email paths; the resource methods handle required percent encoding.
- Do not store, parse, or build attachment `download_url`s; they are short-lived opaque links (CDN or storage) that expire at `expires_at`, so call `attachments()->retrieve()` for a fresh one.
- Do not assume a list call returns every record; follow `pagination.next_cursor` while `pagination.has_more` is true.
- Do not decode and re-encode a webhook body before verification; verify the exact raw request body.
- Do not perform slow webhook work before acknowledging delivery; verify, enqueue durable processing, and return a 2xx response.
- Do not treat webhook delivery as exactly once; persist or otherwise deduplicate `eventId` before side effects.
