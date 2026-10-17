<?php

declare(strict_types=1);

namespace Prontuno\Laravel\Resources;

use Illuminate\Support\LazyCollection;

final class SmtpCredentials extends Resource
{
    /**
     * @param  'active'|'revoked'|'all'|null  $status  Defaults to `active` on the API.
     * @return array<string, mixed>
     */
    public function list(int $limit = 50, ?string $cursor = null, ?string $status = null): array
    {
        return $this->client->get('smtp-credentials', [
            'limit' => $limit,
            'cursor' => $cursor,
            'status' => $status,
        ]);
    }

    /**
     * @param  'active'|'revoked'|'all'|null  $status
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function lazy(?string $status = null, int $limit = 100, int $maxPages = 1000): LazyCollection
    {
        return $this->paginate('smtp-credentials', ['limit' => $limit, 'status' => $status], $maxPages);
    }

    /**
     * Create an SMTP username and password for sending from one verified domain.
     * The response includes the `password`, which is returned only once.
     *
     * @return array<string, mixed>
     */
    public function create(string $name, string $domainId): array
    {
        return $this->client->post('smtp-credentials', [
            'name' => $name,
            'domain_id' => $domainId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function retrieve(string $smtpCredentialId): array
    {
        return $this->client->get('smtp-credentials/'.self::id('SMTP credential', $smtpCredentialId));
    }

    /**
     * Permanently revoke an SMTP credential. SMTP logins using it fail immediately.
     */
    public function revoke(string $smtpCredentialId): void
    {
        $this->client->delete('smtp-credentials/'.self::id('SMTP credential', $smtpCredentialId));
    }

    /**
     * Replace the password. The old password stops working immediately, and the
     * response includes the new `password`, which is returned only once.
     *
     * @return array<string, mixed>
     */
    public function resetPassword(string $smtpCredentialId): array
    {
        return $this->client->post('smtp-credentials/'.self::id('SMTP credential', $smtpCredentialId).'/reset-password', []);
    }
}
