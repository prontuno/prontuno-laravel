<?php

declare(strict_types=1);

namespace Prontuno\Laravel\Webhooks;

final readonly class WebhookEvent
{
    public const string EMAIL_SENT = 'email.sent';

    public const string EMAIL_REJECTED = 'email.rejected';

    public const string EMAIL_DEFERRED = 'email.deferred';

    public const string EMAIL_DELIVERED = 'email.delivered';

    public const string EMAIL_BOUNCED = 'email.bounced';

    public const string EMAIL_EXPIRED = 'email.expired';

    public const string EMAIL_COMPLAINED = 'email.complained';

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $eventId,
        public string $webhookId,
        public string $type,
        public string $createdAt,
        public array $data,
        public int $signatureTimestamp,
        private array $payload,
    ) {}

    public function is(string $type): bool
    {
        return $this->type === $type;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->payload;
    }
}
