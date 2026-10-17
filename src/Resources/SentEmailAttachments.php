<?php

declare(strict_types=1);

namespace Prontuno\Laravel\Resources;

use Illuminate\Support\LazyCollection;

final class SentEmailAttachments extends Resource
{
    /**
     * One page of a sent email's attachments, each with a fresh one-hour `download_url`
     * (null while the file hasn't finished uploading).
     *
     * @return array<string, mixed>
     */
    public function list(string $emailId, int $limit = 50, ?string $cursor = null): array
    {
        return $this->client->get(self::uri($emailId), [
            'limit' => $limit,
            'cursor' => $cursor,
        ]);
    }

    /**
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function lazy(string $emailId, int $limit = 100, int $maxPages = 1000): LazyCollection
    {
        return $this->paginate(self::uri($emailId), ['limit' => $limit], $maxPages);
    }

    /**
     * Attachment metadata with a fresh signed `download_url` that expires after one hour.
     * Treat the URL as opaque: it may be served from a CDN or directly from storage.
     * Throws a 403 `attachment_unavailable` while the file hasn't finished uploading.
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $emailId, string $attachmentId): array
    {
        return $this->client->get(self::uri($emailId).'/'.self::id('attachment', $attachmentId));
    }

    private static function uri(string $emailId): string
    {
        return 'emails/'.self::id('email', $emailId).'/attachments';
    }
}
