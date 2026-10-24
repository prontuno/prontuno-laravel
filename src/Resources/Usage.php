<?php

declare(strict_types=1);

namespace Prontuno\Laravel\Resources;

/**
 * @phpstan-type UsageLimit array{used: int, limit: int|null}
 * @phpstan-type UsageResponse array{
 *     subscription: array{
 *         plan: string,
 *         status: string|null,
 *         is_active: bool,
 *         period_start: string|null,
 *         period_end: string|null,
 *         cancel_at_period_end: bool,
 *         recipients_included: int|null,
 *         recipients_used: int,
 *         recipients_remaining: int|null,
 *     }|null,
 *     credits: array{balance: int, expires_at: string|null},
 *     limits: array{
 *         domains: UsageLimit,
 *         shared_teams: UsageLimit|null,
 *         api_keys: UsageLimit,
 *         smtp_credentials: UsageLimit,
 *     },
 * }
 */
final class Usage extends Resource
{
    /**
     * The team's subscription allowance, prepaid credit balance, and resource limits.
     * `subscription` is null when the team has no plan, and a null `limit` means unlimited.
     *
     * @return UsageResponse
     */
    public function retrieve(): array
    {
        /** @var UsageResponse */
        return $this->client->get('usage');
    }
}
