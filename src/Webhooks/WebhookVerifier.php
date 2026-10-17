<?php

declare(strict_types=1);

namespace Prontuno\Laravel\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use JsonException;
use Prontuno\Laravel\Webhooks\Exceptions\InvalidPayloadException;
use Prontuno\Laravel\Webhooks\Exceptions\InvalidSignatureException;
use Prontuno\Laravel\Webhooks\Exceptions\StaleWebhookException;
use Prontuno\Laravel\Webhooks\Exceptions\WebhookVerificationException;

final class WebhookVerifier
{
    public const string SIGNATURE_HEADER = 'Prontuno-Signature';

    public function __construct(
        private readonly ?string $signingSecret = null,
        private readonly int $tolerance = 300,
    ) {
        if ($this->tolerance < 0) {
            throw new InvalidArgumentException('Prontuno webhook timestamp tolerance cannot be negative.');
        }
    }

    public function fromRequest(
        Request $request,
        ?string $secret = null,
        ?int $tolerance = null,
    ): WebhookEvent {
        $signature = $request->header(self::SIGNATURE_HEADER);

        if (! is_string($signature) || trim($signature) === '') {
            throw new InvalidSignatureException('The Prontuno-Signature header is missing.');
        }

        return $this->constructEvent(
            payload: $request->getContent(),
            signature: $signature,
            secret: $secret,
            tolerance: $tolerance,
        );
    }

    public function constructEvent(
        string $payload,
        string $signature,
        ?string $secret = null,
        ?int $tolerance = null,
    ): WebhookEvent {
        $signatureTimestamp = $this->verifiedTimestamp($payload, $signature, $secret, $tolerance);
        $decoded = $this->decodePayload($payload);

        return new WebhookEvent(
            eventId: $this->requiredString($decoded, 'event_id'),
            webhookId: $this->requiredString($decoded, 'webhook_id'),
            type: $this->requiredString($decoded, 'type'),
            createdAt: $this->requiredString($decoded, 'created_at'),
            data: $this->requiredData($decoded),
            signatureTimestamp: $signatureTimestamp,
            payload: $decoded,
        );
    }

    public function verify(
        string $payload,
        string $signature,
        ?string $secret = null,
        ?int $tolerance = null,
    ): void {
        $this->verifiedTimestamp($payload, $signature, $secret, $tolerance);
    }

    private function verifiedTimestamp(
        string $payload,
        string $signature,
        ?string $secret,
        ?int $tolerance,
    ): int {
        $resolvedSecret = $this->resolveSecret($secret);
        $resolvedTolerance = $this->resolveTolerance($tolerance);
        [$timestamp, $signatures] = $this->parseSignature($signature);
        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", $resolvedSecret);

        $matches = false;

        foreach ($signatures as $candidate) {
            if (hash_equals($expected, strtolower($candidate))) {
                $matches = true;

                break;
            }
        }

        if (! $matches) {
            throw new InvalidSignatureException('The Prontuno webhook signature is invalid.');
        }

        if ($resolvedTolerance > 0 && abs(Carbon::now()->getTimestamp() - $timestamp) > $resolvedTolerance) {
            throw new StaleWebhookException('The Prontuno webhook signature timestamp is outside the allowed tolerance.');
        }

        return $timestamp;
    }

    private function resolveSecret(?string $secret): string
    {
        $resolved = $secret ?? $this->signingSecret;

        if (! is_string($resolved) || trim($resolved) === '') {
            throw new WebhookVerificationException(
                'Prontuno webhook signing secret is not configured. Set PRONTUNO_WEBHOOK_SIGNING_SECRET or pass the endpoint secret explicitly.',
            );
        }

        return $resolved;
    }

    private function resolveTolerance(?int $tolerance): int
    {
        $resolved = $tolerance ?? $this->tolerance;

        if ($resolved < 0) {
            throw new InvalidArgumentException('Prontuno webhook timestamp tolerance cannot be negative.');
        }

        return $resolved;
    }

    /**
     * @return array{0: int, 1: list<string>}
     */
    private function parseSignature(string $signature): array
    {
        if (trim($signature) === '') {
            throw new InvalidSignatureException('The Prontuno webhook signature is missing.');
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $signature) as $part) {
            $segments = explode('=', trim($part), 2);

            if (count($segments) !== 2) {
                throw new InvalidSignatureException('The Prontuno webhook signature header is malformed.');
            }

            [$scheme, $value] = $segments;
            $scheme = trim($scheme);
            $value = trim($value);

            if ($scheme === 't') {
                if ($timestamp !== null || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) {
                    throw new InvalidSignatureException('The Prontuno webhook signature timestamp is invalid.');
                }

                $timestamp = (int) $value;
            }

            // Malformed v1 values are skipped, so another valid v1 value can still match.
            if ($scheme === 'v1' && preg_match('/\A[a-f0-9]{64}\z/i', $value) === 1) {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === []) {
            throw new InvalidSignatureException('The Prontuno webhook signature header must contain t and v1 values.');
        }

        return [$timestamp, $signatures];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(string $payload): array
    {
        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidPayloadException('The Prontuno webhook payload is not valid JSON.', previous: $exception);
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidPayloadException('The Prontuno webhook payload must be a JSON object.');
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requiredString(array $payload, string $field): string
    {
        $value = $payload[$field] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidPayloadException("The Prontuno webhook payload requires a non-empty {$field} field.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function requiredData(array $payload): array
    {
        $data = $payload['data'] ?? null;

        // An empty JSON object decodes to [], so only non-empty lists are rejected.
        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new InvalidPayloadException('The Prontuno webhook payload requires a data object.');
        }

        return $data;
    }
}
