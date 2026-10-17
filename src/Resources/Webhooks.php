<?php

declare(strict_types=1);

namespace Prontuno\Laravel\Resources;

use Illuminate\Support\LazyCollection;

final class Webhooks extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function list(int $limit = 50, ?string $cursor = null): array
    {
        return $this->client->get('webhooks', [
            'limit' => $limit,
            'cursor' => $cursor,
        ]);
    }

    /**
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function lazy(int $limit = 100, int $maxPages = 1000): LazyCollection
    {
        return $this->paginate('webhooks', ['limit' => $limit], $maxPages);
    }

    /**
     * Create a webhook. The response includes the signing `secret`, which is returned only once.
     *
     * @param  list<string>  $events
     * @return array<string, mixed>
     */
    public function create(
        string $name,
        string $url,
        array $events,
        ?string $domainId = null,
        ?bool $isGlobal = null,
        ?bool $isEnabled = null,
    ): array {
        return $this->client->post('webhooks', array_filter([
            'name' => $name,
            'url' => $url,
            'events' => $events,
            'domain_id' => $domainId,
            'is_global' => $isGlobal,
            'is_enabled' => $isEnabled,
        ], static fn (mixed $value): bool => $value !== null));
    }

    /**
     * @return array<string, mixed>
     */
    public function retrieve(string $webhookId): array
    {
        return $this->client->get('webhooks/'.self::id('webhook', $webhookId));
    }

    /**
     * Change only the given fields. To switch between all domains and one domain, set
     * `is_global` too: `['is_global' => true, 'domain_id' => null]` or
     * `['is_global' => false, 'domain_id' => 'dom_...']`.
     *
     * @param  array{name?: string, url?: string, events?: list<string>, domain_id?: string|null, is_global?: bool, is_enabled?: bool}  $changes
     * @return array<string, mixed>
     */
    public function update(string $webhookId, array $changes): array
    {
        return $this->client->patch('webhooks/'.self::id('webhook', $webhookId), $changes);
    }

    public function delete(string $webhookId): void
    {
        $this->client->delete('webhooks/'.self::id('webhook', $webhookId));
    }

    /**
     * Replace the signing secret. The old secret stops working immediately.
     *
     * @return array<string, mixed>
     */
    public function rotateSecret(string $webhookId): array
    {
        return $this->client->post('webhooks/'.self::id('webhook', $webhookId).'/rotate-secret', []);
    }
}
