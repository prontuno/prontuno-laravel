<?php

declare(strict_types=1);

namespace Prontuno\Laravel\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;

class ApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $body
     * @param  array<int, array<string, mixed>>  $errors
     */
    private function __construct(
        string $message,
        private readonly int $status,
        private readonly string $apiErrorCode,
        private readonly ?string $apiRequestId,
        private readonly bool $retryable,
        private readonly ?string $action,
        private readonly ?int $retryAfterSeconds,
        private readonly array $body,
        private readonly array $errors,
        private readonly string $rawBody,
    ) {
        parent::__construct($message, $status);
    }

    public static function fromResponse(Response $response): self
    {
        $json = $response->json();
        $body = is_array($json) ? $json : [];
        $error = isset($body['error']) && is_array($body['error']) ? $body['error'] : [];
        $errors = isset($body['errors']) && is_array($body['errors']) ? $body['errors'] : [];

        $message = is_string($error['message'] ?? null)
            ? $error['message']
            : "Prontuno API request failed with status {$response->status()}.";

        $retryAfter = $error['retry_after_seconds'] ?? $response->header('Retry-After');

        return new self(
            message: $message,
            status: $response->status(),
            apiErrorCode: is_string($error['code'] ?? null) ? $error['code'] : 'api_error',
            apiRequestId: $response->header('x-request-id'),
            retryable: is_bool($error['retryable'] ?? null) ? $error['retryable'] : $response->status() >= 500,
            action: is_string($error['suggested_action'] ?? null) ? $error['suggested_action'] : null,
            retryAfterSeconds: is_numeric($retryAfter) ? (int) $retryAfter : null,
            body: $body,
            errors: array_values(array_filter($errors, 'is_array')),
            rawBody: $response->body(),
        );
    }

    public static function unexpectedResponse(Response $response): self
    {
        return new self(
            message: "Prontuno API returned an invalid JSON response with status {$response->status()}.",
            status: $response->status(),
            apiErrorCode: 'unexpected_response',
            apiRequestId: $response->header('x-request-id'),
            retryable: false,
            action: null,
            retryAfterSeconds: null,
            body: [],
            errors: [],
            rawBody: $response->body(),
        );
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errorCode(): string
    {
        return $this->apiErrorCode;
    }

    public function requestId(): ?string
    {
        return $this->apiRequestId;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    public function suggestedAction(): ?string
    {
        return $this->action;
    }

    public function retryAfter(): ?int
    {
        return $this->retryAfterSeconds;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return array<string, mixed>
     */
    public function responseBody(): array
    {
        return $this->body;
    }

    public function rawResponseBody(): string
    {
        return $this->rawBody;
    }
}
