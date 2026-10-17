<?php

declare(strict_types=1);

namespace Prontuno\Laravel\Resources;

use Illuminate\Support\LazyCollection;

final class Suppressions extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function list(int $limit = 50, ?string $cursor = null, ?string $email = null): array
    {
        return $this->client->get('suppressions', [
            'limit' => $limit,
            'cursor' => $cursor,
            'email' => $email,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function create(string $email): array
    {
        return $this->client->post('suppressions', ['email' => $email]);
    }

    /**
     * @return array<string, mixed>
     */
    public function retrieve(string $suppressionIdOrEmail): array
    {
        return $this->client->get('suppressions/'.self::id('suppression', $suppressionIdOrEmail));
    }

    public function delete(string $suppressionIdOrEmail): void
    {
        $this->client->delete('suppressions/'.self::id('suppression', $suppressionIdOrEmail));
    }

    /**
     * Lazily iterate every suppression across all pages.
     *
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function lazy(?string $email = null, int $limit = 100, int $maxPages = 1000): LazyCollection
    {
        return $this->paginate('suppressions', ['limit' => $limit, 'email' => $email], $maxPages);
    }
}
