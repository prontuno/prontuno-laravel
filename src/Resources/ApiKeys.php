<?php

declare(strict_types=1);

namespace Prontuno\Laravel\Resources;

use Illuminate\Support\LazyCollection;

final class ApiKeys extends Resource
{
    /**
     * @param  'active'|'revoked'|'all'|null  $status  Defaults to `active` on the API.
     * @return array<string, mixed>
     */
    public function list(int $limit = 50, ?string $cursor = null, ?string $status = null, ?string $type = null): array
    {
        return $this->client->get('api-keys', [
            'limit' => $limit,
            'cursor' => $cursor,
            'status' => $status,
            'type' => $type,
        ]);
    }

    /**
     * @param  'active'|'revoked'|'all'|null  $status
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function lazy(?string $status = null, ?string $type = null, int $limit = 100, int $maxPages = 1000): LazyCollection
    {
        return $this->paginate('api-keys', ['limit' => $limit, 'status' => $status, 'type' => $type], $maxPages);
    }

    /**
     * Create an API key. Its scopes are derived from `$type`: `sending` keys send from
     * `$domainId`; `mcp` keys manage the account and also send when `$allowEmailSending`
     * is true; `management` keys can also manage API keys and SMTP credentials.
     *
     * The response includes the plaintext `key`, which is returned only once.
     *
     * @return array<string, mixed>
     */
    public function create(string $name, string $type, ?string $domainId = null, bool $allowEmailSending = false): array
    {
        return $this->client->post('api-keys', array_filter([
            'name' => $name,
            'type' => $type,
            'domain_id' => $domainId,
            'allow_email_sending' => $allowEmailSending ?: null,
        ], static fn (mixed $value): bool => $value !== null));
    }

    /**
     * @return array<string, mixed>
     */
    public function retrieve(string $apiKeyId): array
    {
        return $this->client->get('api-keys/'.self::id('API key', $apiKeyId));
    }

    /**
     * Permanently revoke an API key. Requests using it fail immediately.
     */
    public function revoke(string $apiKeyId): void
    {
        $this->client->delete('api-keys/'.self::id('API key', $apiKeyId));
    }
}
