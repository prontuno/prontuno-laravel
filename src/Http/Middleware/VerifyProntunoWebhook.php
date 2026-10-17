<?php

declare(strict_types=1);

namespace Prontuno\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Prontuno\Laravel\Webhooks\Exceptions\InvalidPayloadException;
use Prontuno\Laravel\Webhooks\Exceptions\InvalidSignatureException;
use Prontuno\Laravel\Webhooks\Exceptions\StaleWebhookException;
use Prontuno\Laravel\Webhooks\WebhookEvent;
use Prontuno\Laravel\Webhooks\WebhookVerifier;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects requests without a valid Prontuno signature and exposes the verified event.
 *
 * Route::post('/webhooks/prontuno', ...)->middleware('prontuno.webhook');
 * Route::post('/webhooks/billing', ...)->middleware('prontuno.webhook:billing'); // prontuno.webhooks.secrets.billing
 */
final class VerifyProntunoWebhook
{
    public const string EVENT_ATTRIBUTE = 'prontuno_webhook_event';

    public function __construct(private readonly WebhookVerifier $verifier) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, ?string $secretName = null): Response
    {
        $secret = null;

        if ($secretName !== null) {
            $configured = config("prontuno.webhooks.secrets.{$secretName}");
            $secret = is_string($configured) ? $configured : null;

            abort_if($secret === null || trim($secret) === '', 500, "Prontuno webhook secret [{$secretName}] is not configured.");
        }

        try {
            $event = $this->verifier->fromRequest($request, $secret);
        } catch (InvalidPayloadException) {
            abort(400, 'Invalid Prontuno webhook payload.');
        } catch (InvalidSignatureException|StaleWebhookException) {
            abort(403, 'Invalid Prontuno webhook signature.');
        }

        $request->attributes->set(self::EVENT_ATTRIBUTE, $event);

        return $next($request);
    }

    public static function event(Request $request): ?WebhookEvent
    {
        $event = $request->attributes->get(self::EVENT_ATTRIBUTE);

        return $event instanceof WebhookEvent ? $event : null;
    }
}
