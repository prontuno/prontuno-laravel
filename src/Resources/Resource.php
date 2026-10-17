<?php

declare(strict_types=1);

namespace Prontuno\Laravel\Resources;

use Illuminate\Support\LazyCollection;
use Prontuno\Laravel\Exceptions\ConfigurationException;
use Prontuno\Laravel\Exceptions\PaginationException;
use Prontuno\Laravel\Prontuno;

abstract class Resource
{
    public function __construct(protected readonly Prontuno $client) {}

    /**
     * Encode one identifier as a path segment, rejecting blank values before any request.
     */
    protected static function id(string $label, string $id): string
    {
        if (trim($id) === '') {
            throw new ConfigurationException("A {$label} identifier is required.");
        }

        return rawurlencode($id);
    }

    /**
     * Lazily follow `pagination.next_cursor`, yielding every item across pages.
     *
     * @param  array<string, mixed>  $query
     * @return LazyCollection<int, array<string, mixed>>
     */
    protected function paginate(string $uri, array $query, int $maxPages): LazyCollection
    {
        if ($maxPages < 1) {
            throw new PaginationException('Prontuno pagination requires maxPages to be at least 1.');
        }

        return LazyCollection::make(function () use ($uri, $query, $maxPages) {
            $cursor = null;
            $seen = [];

            for ($pages = 1; ; $pages++) {
                $page = $this->client->get($uri, ['cursor' => $cursor] + $query);

                foreach (is_array($page['data'] ?? null) ? $page['data'] : [] as $item) {
                    if (is_array($item)) {
                        /** @var array<string, mixed> $item */
                        yield $item;
                    }
                }

                $pagination = is_array($page['pagination'] ?? null) ? $page['pagination'] : [];

                if (($pagination['has_more'] ?? false) !== true) {
                    return;
                }

                if ($pages >= $maxPages) {
                    throw new PaginationException("Prontuno pagination exceeded the maximum of {$maxPages} pages.");
                }

                $cursor = $pagination['next_cursor'] ?? null;

                if (! is_string($cursor) || $cursor === '') {
                    throw new PaginationException('Prontuno pagination indicated more results without a next cursor.');
                }

                if (isset($seen[$cursor])) {
                    throw new PaginationException('Prontuno API returned a repeated pagination cursor.');
                }

                $seen[$cursor] = true;
            }
        });
    }
}
