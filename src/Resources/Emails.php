<?php

declare(strict_types=1);

namespace Prontuno\Laravel\Resources;

use Illuminate\Support\LazyCollection;

final class Emails extends Resource
{
    private ?SentEmailAttachments $attachments = null;

    public function attachments(): SentEmailAttachments
    {
        return $this->attachments ??= new SentEmailAttachments($this->client);
    }

    /**
     * @param  array<string, mixed>  $email
     * @return array<string, mixed>
     */
    public function send(array $email, ?string $idempotencyKey = null): array
    {
        return $this->client->post('emails', $email, $this->idempotencyHeaders($idempotencyKey), sending: true);
    }

    /**
     * @param  array<int, array<string, mixed>>  $emails
     * @return array<string, mixed>
     */
    public function sendBatch(array $emails, ?string $idempotencyKey = null): array
    {
        return $this->client->post('emails/batch', ['emails' => $emails], $this->idempotencyHeaders($idempotencyKey), sending: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function list(
        int $limit = 50,
        ?string $cursor = null,
        ?string $domainId = null,
        ?string $status = null,
    ): array {
        return $this->client->get('emails', [
            'limit' => $limit,
            'cursor' => $cursor,
            'domain_id' => $domainId,
            'status' => $status,
        ]);
    }

    /**
     * One email with its status, timestamps, stored `text` and `html` bodies (null when
     * absent or unavailable), and `attachments` with fresh one-hour download links.
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $emailId): array
    {
        return $this->client->get('emails/'.self::id('email', $emailId));
    }

    /**
     * @return array<string, string>
     */
    private function idempotencyHeaders(?string $idempotencyKey): array
    {
        return $idempotencyKey === null ? [] : ['Idempotency-Key' => $idempotencyKey];
    }

    /**
     * Lazily iterate every email across all pages.
     *
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function lazy(?string $domainId = null, ?string $status = null, int $limit = 100, int $maxPages = 1000): LazyCollection
    {
        return $this->paginate('emails', ['limit' => $limit, 'domain_id' => $domainId, 'status' => $status], $maxPages);
    }
}
