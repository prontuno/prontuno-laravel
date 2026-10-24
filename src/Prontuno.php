<?php

declare(strict_types=1);

namespace Prontuno\Laravel;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use Prontuno\Laravel\Exceptions\ApiException;
use Prontuno\Laravel\Exceptions\ConfigurationException;
use Prontuno\Laravel\Exceptions\ConnectionException as ProntunoConnectionException;
use Prontuno\Laravel\Resources\ApiKeys;
use Prontuno\Laravel\Resources\Domains;
use Prontuno\Laravel\Resources\Emails;
use Prontuno\Laravel\Resources\SmtpCredentials;
use Prontuno\Laravel\Resources\Suppressions;
use Prontuno\Laravel\Resources\Usage;
use Prontuno\Laravel\Resources\Webhooks;
use Throwable;

class Prontuno
{
    private ?Emails $emails = null;

    private ?Domains $domains = null;

    private ?Suppressions $suppressions = null;

    private ?Webhooks $webhooks = null;

    private ?ApiKeys $apiKeys = null;

    private ?SmtpCredentials $smtpCredentials = null;

    private ?Usage $usage = null;

    public const string VERSION = '0.1.0';

    public const string DEFAULT_BASE_URL = 'https://api.prontuno.net/v1';

    public const string USER_AGENT = 'prontuno-laravel/'.self::VERSION;

    private const int MAX_RETRY_DELAY_MILLISECONDS = 60_000;

    private readonly string $userAgent;

    /**
     * Pass one API key for every request, or separate keys. Sends use the sending key
     * and every other request uses the management key. Role-specific keys take
     * precedence over `$apiKey`, and blank keys count as unset. `$appInfo` (for example
     * "my-app/1.2.0") identifies your application and is sent after the SDK's own
     * `prontuno-laravel/<version>` User-Agent, never instead of it.
     */
    public function __construct(
        private readonly Factory $http,
        private readonly ?string $apiKey = null,
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly int $timeout = 30,
        private readonly int $connectTimeout = 10,
        private readonly ?string $appInfo = null,
        private readonly int $retries = 2,
        private readonly ?string $sendingKey = null,
        private readonly ?string $managementKey = null,
    ) {
        if ($this->keyFor(sending: true) === null && $this->keyFor(sending: false) === null) {
            throw new ConfigurationException(
                'Prontuno API key is not configured. Set PRONTUNO_SENDING_KEY and PRONTUNO_MANAGEMENT_KEY, or PRONTUNO_API_KEY for a single key.',
            );
        }

        if (trim($this->baseUrl) === '') {
            throw new ConfigurationException('Prontuno base URL cannot be empty.');
        }

        self::assertSecureBaseUrl($this->baseUrl);

        if ($this->retries < 0) {
            throw new ConfigurationException('Prontuno retries cannot be negative.');
        }

        if ($this->appInfo !== null && preg_match('/[\x00-\x1F\x7F]/', $this->appInfo) === 1) {
            throw new ConfigurationException('Prontuno app info must be a single line.');
        }

        $this->userAgent = $this->appInfo === null || trim($this->appInfo) === ''
            ? self::USER_AGENT
            : self::USER_AGENT.' '.trim($this->appInfo);
    }

    public function emails(): Emails
    {
        return $this->emails ??= new Emails($this);
    }

    public function domains(): Domains
    {
        return $this->domains ??= new Domains($this);
    }

    public function suppressions(): Suppressions
    {
        return $this->suppressions ??= new Suppressions($this);
    }

    public function webhooks(): Webhooks
    {
        return $this->webhooks ??= new Webhooks($this);
    }

    public function apiKeys(): ApiKeys
    {
        return $this->apiKeys ??= new ApiKeys($this);
    }

    public function smtpCredentials(): SmtpCredentials
    {
        return $this->smtpCredentials ??= new SmtpCredentials($this);
    }

    public function usage(): Usage
    {
        return $this->usage ??= new Usage($this);
    }

    /**
     * Returns a client that uses `$apiKey` for every request, for example a tenant's key.
     */
    public function usingApiKey(string $apiKey): self
    {
        return $this->copy(apiKey: $apiKey, sendingKey: null, managementKey: null);
    }

    /**
     * Returns a client that sends with a different domain's sending key, keeping
     * every other setting, including the management key.
     */
    public function withSendingKey(string $sendingKey): self
    {
        if (trim($sendingKey) === '') {
            throw new ConfigurationException('withSendingKey() requires a non-empty sending key.');
        }

        return $this->copy(apiKey: $this->apiKey, sendingKey: $sendingKey, managementKey: $this->managementKey);
    }

    private function copy(?string $apiKey, ?string $sendingKey, ?string $managementKey): self
    {
        return new self(
            http: $this->http,
            apiKey: $apiKey,
            baseUrl: $this->baseUrl,
            timeout: $this->timeout,
            connectTimeout: $this->connectTimeout,
            appInfo: $this->appInfo,
            retries: $this->retries,
            sendingKey: $sendingKey,
            managementKey: $managementKey,
        );
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $uri, array $query = []): array
    {
        return $this->send('GET', $uri, query: $query);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    public function post(string $uri, array $data, array $headers = [], bool $sending = false): array
    {
        return $this->send('POST', $uri, data: $data, headers: $headers, sending: $sending);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function patch(string $uri, array $data): array
    {
        return $this->send('PATCH', $uri, data: $data);
    }

    public function delete(string $uri): void
    {
        $this->send('DELETE', $uri);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    private function send(
        string $method,
        string $uri,
        array $query = [],
        array $data = [],
        array $headers = [],
        bool $sending = false,
    ): array {
        $uri = $this->relativeUri($uri);
        $apiKey = $this->keyFor($sending);

        if ($apiKey === null) {
            [$role, $variable] = $sending ? ['sending', 'PRONTUNO_SENDING_KEY'] : ['management', 'PRONTUNO_MANAGEMENT_KEY'];

            throw new ConfigurationException("{$method} /{$uri} requires a {$role} API key. Set {$variable} (or PRONTUNO_API_KEY).");
        }

        $request = $this->http
            ->baseUrl(rtrim($this->baseUrl, '/'))
            ->acceptJson()
            ->asJson()
            ->withToken($apiKey)
            ->withUserAgent($this->userAgent)
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->withHeaders($headers);

        $retrySafe = $method === 'GET' || $method === 'DELETE' || isset($headers['Idempotency-Key']);

        // Set once an attempt may have reached the API without a usable response, so a
        // retried DELETE that finds the resource already gone counts as success.
        $mayHaveApplied = false;

        if ($retrySafe && $this->retries > 0) {
            $request->retry(
                $this->retries + 1,
                fn (int $attempt, mixed $exception): int => $this->retryDelay($attempt, $exception),
                function (?Throwable $exception) use (&$mayHaveApplied): bool {
                    if ($exception === null || ! $this->shouldRetry($exception)) {
                        return false;
                    }

                    $mayHaveApplied = $mayHaveApplied || self::outcomeUnknown($exception);

                    return true;
                },
                throw: false,
            );
        }

        $options = [];

        if ($query !== []) {
            $options['query'] = array_filter($query, static fn (mixed $value): bool => $value !== null);
        }

        if ($data !== []) {
            $options['json'] = $data;
        }

        try {
            $response = $request->send($method, $uri, $options);
        } catch (ConnectionException $exception) {
            throw new ProntunoConnectionException(
                'Could not connect to the Prontuno API: '.$exception->getMessage(),
                0,
                $exception,
            );
        }

        if (! $response->successful()) {
            $exception = ApiException::fromResponse($response);

            if ($mayHaveApplied && $method === 'DELETE' && self::isAlreadyDeleted($exception)) {
                return [];
            }

            throw $exception;
        }

        return $this->json($response);
    }

    /**
     * Require HTTPS so the API key is never sent in plain text. Plain HTTP is
     * only accepted for loopback hosts, for local development.
     */
    private static function assertSecureBaseUrl(string $baseUrl): void
    {
        $parts = parse_url($baseUrl);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new ConfigurationException('Prontuno base URL must be an absolute URL, such as https://api.prontuno.net/v1.');
        }

        $scheme = strtolower($parts['scheme']);
        $loopback = in_array(strtolower($parts['host']), ['localhost', '127.0.0.1', '[::1]'], true);

        if ($scheme !== 'https' && ! ($scheme === 'http' && $loopback)) {
            throw new ConfigurationException('Prontuno base URL must use https (plain http is only allowed for loopback hosts).');
        }
    }

    private function keyFor(bool $sending): ?string
    {
        foreach ([$sending ? $this->sendingKey : $this->managementKey, $this->apiKey] as $key) {
            if ($key !== null && trim($key) !== '') {
                return trim($key);
            }
        }

        return null;
    }

    private function shouldRetry(Throwable $exception): bool
    {
        if (! $exception instanceof RequestException) {
            return $exception instanceof ConnectionException;
        }

        $status = $exception->response->status();

        return $status === 408
            || $status === 429
            || $status >= 500
            || ($status === 409 && $exception->response->json('error.code') === 'idempotency_request_in_progress');
    }

    /**
     * A connection failure, timeout, or server error may have been processed by the API.
     */
    private static function outcomeUnknown(Throwable $exception): bool
    {
        if (! $exception instanceof RequestException) {
            return true;
        }

        $status = $exception->response->status();

        return $status === 408 || $status >= 500;
    }

    /**
     * A DELETE retried after an attempt that may have succeeded finds the resource gone.
     */
    private static function isAlreadyDeleted(ApiException $exception): bool
    {
        return $exception->status() === 404
            || ($exception->status() === 409 && $exception->errorCode() === 'already_revoked');
    }

    private function retryDelay(int $attempt, mixed $exception): int
    {
        $retryAfter = $exception instanceof RequestException
            ? $exception->response->header('Retry-After')
            : '';

        if (is_numeric($retryAfter) && (int) $retryAfter >= 0) {
            return min((int) $retryAfter * 1000, self::MAX_RETRY_DELAY_MILLISECONDS);
        }

        return (int) min(500 * (2 ** max($attempt - 1, 0)), 5000);
    }

    private function relativeUri(string $uri): string
    {
        if (
            str_starts_with($uri, '//')
            || str_contains($uri, '#')
            || str_contains($uri, '\\')
            || preg_match('/[\x00-\x20\x7f]/', $uri) === 1
        ) {
            throw new InvalidArgumentException('Prontuno request URI must be a relative API path.');
        }

        $uri = ltrim($uri, '/');

        if (preg_match('/\A[a-z][a-z0-9+.-]*:/i', $uri) === 1) {
            throw new InvalidArgumentException('Prontuno request URI must be a relative API path.');
        }

        $path = explode('?', $uri, 2)[0];

        for ($iteration = 0; $iteration < 3; $iteration++) {
            $decodedPath = rawurldecode($path);

            if ($decodedPath === $path) {
                break;
            }

            $path = $decodedPath;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('Prontuno request URI must be a relative API path.');
            }
        }

        return $uri;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        if ($response->status() === 204) {
            return [];
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw ApiException::unexpectedResponse($response);
        }

        return $json;
    }
}
