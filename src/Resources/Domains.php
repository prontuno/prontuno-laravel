<?php

declare(strict_types=1);

namespace Prontuno\Laravel\Resources;

use Illuminate\Support\LazyCollection;

final class Domains extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function list(int $limit = 50, ?string $cursor = null): array
    {
        return $this->client->get('domains', [
            'limit' => $limit,
            'cursor' => $cursor,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function create(string $domain): array
    {
        return $this->client->post('domains', ['domain' => $domain]);
    }

    /**
     * @return array<string, mixed>
     */
    public function retrieve(string $domainId): array
    {
        return $this->client->get('domains/'.self::id('domain', $domainId));
    }

    /**
     * Lazily iterate every domain across all pages.
     *
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function lazy(int $limit = 100, int $maxPages = 1000): LazyCollection
    {
        return $this->paginate('domains', ['limit' => $limit], $maxPages);
    }
}
