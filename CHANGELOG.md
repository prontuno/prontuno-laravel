# Release Notes

## [Unreleased](https://github.com/prontuno/prontuno-laravel/compare/v0.2.0...HEAD)

## [v0.2.0](https://github.com/prontuno/prontuno-laravel/compare/v0.1.0...v0.2.0) - 2026-10-24

Adds support for the [Prontuno API](https://prontuno.com/api-reference/general/introduction/) usage endpoint.

### Added

- `Prontuno::usage()->retrieve()` (or `$prontuno->usage()->retrieve()`) returns the team's sending capacity (`GET /v1/usage`):
  - the subscription plan, with recipients used and remaining in the current billing period;
  - the prepaid credit balance and when it expires;
  - how many domains, shared teams, API keys, and SMTP credentials are in use against their limits.
  
- A PHPStan array shape for the response. `subscription` is `null` when the team has no plan, and unlimited values are `null`.
- Uses the management key. A client with only a sending key throws `ConfigurationException` before any request is made.

```php
$usage = Prontuno::usage()->retrieve();
echo $usage['credits']['balance'];

```
### Changed

- The User-Agent is now `prontuno-laravel/0.2.0`.
- The README links to the API reference, lists `usage:read` in the `mcp` key scopes, and adds `usage()->retrieve()` to the API reference table.

### Upgrading

There are no breaking changes. Because `^0.1` doesn't include 0.2 releases, update the version constraint:

```bash
composer require prontuno/prontuno-laravel:^0.2

```
**Full changelog:** https://github.com/prontuno/prontuno-laravel/compare/v0.1.0...v0.2.0

## [v0.1.0](https://github.com/prontuno/prontuno-laravel/releases/tag/v0.1.0) - 2026-10-23

Initial release of the official Laravel integration for the Prontuno API. Requires PHP 8.3+ and Laravel 12 or 13.

### Added

- `Prontuno` client and facade, auto-discovered, with a publishable config file (`php artisan vendor:publish --tag=prontuno-config`).
- `prontuno` mail transport for Mailables, notifications, and `Mail::raw()`. It maps addresses, subject, bodies, custom headers, and attachments (including inline ones), uses each message's `Message-ID` as its idempotency key, and adds accepted email IDs to the `X-Prontuno-Email-ID` header. Extra sending domains get their own mailer with a `key`.
- Emails: `send()`, `sendBatch()`, `list()`, `retrieve()`, and sent-attachment listing and downloads with fresh signed links.
- Domains, suppressions, webhook endpoints (including secret rotation), API keys, and SMTP credentials (including password resets).
- Sending and management keys, each used for the right calls. A method whose key isn't configured throws a `ConfigurationException` before any request. `withSendingKey()` and `usingApiKey()` return a new client with a different key.
- Webhook verification with the `prontuno.webhook` route middleware (named secrets with `prontuno.webhook:<name>`) or `WebhookVerifier::fromRequest()`. Checks the `Prontuno-Signature` HMAC on the raw body with a timestamp tolerance (300 seconds by default) and returns a typed `WebhookEvent`.
- Cursor pagination with `list()`, and `lazy()` `LazyCollection`s that go through every page.
- Retries for GET, DELETE, and idempotent sends on `408`, `429`, `5xx`, and connection failures, respecting `Retry-After` (up to 60 seconds). A retried DELETE succeeds if the first attempt already removed the resource.
- `ApiException` with the status, error code, request ID, retryability, suggested action, retry-after, field and batch errors, and the raw response body. Also `ConnectionException` (extends Laravel's), `ConfigurationException`, and `PaginationException`.
- `.env` settings for keys, base URL, timeouts, retries, webhook secrets and tolerance, and `PRONTUNO_APP_INFO`, which is added to the `prontuno-laravel/0.1.0` User-Agent.
