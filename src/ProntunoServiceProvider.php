<?php

declare(strict_types=1);

namespace Prontuno\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Mail\MailManager;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Prontuno\Laravel\Http\Middleware\VerifyProntunoWebhook;
use Prontuno\Laravel\Mail\ProntunoTransport;
use Prontuno\Laravel\Webhooks\WebhookVerifier;

class ProntunoServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/prontuno.php', 'prontuno');

        $this->app->singleton(Prontuno::class, function (Application $app): Prontuno {
            /** @var array{api_key?: mixed, sending_key?: mixed, management_key?: mixed, base_url?: mixed, timeout?: mixed, connect_timeout?: mixed, app_info?: mixed, retries?: mixed} $config */
            $config = $app->make('config')->get('prontuno', []);

            return new Prontuno(
                http: $app->make(Factory::class),
                apiKey: is_string($config['api_key'] ?? null) ? $config['api_key'] : null,
                baseUrl: is_string($config['base_url'] ?? null) && trim($config['base_url']) !== '' ? trim($config['base_url']) : Prontuno::DEFAULT_BASE_URL,
                timeout: is_numeric($config['timeout'] ?? null) ? (int) $config['timeout'] : 30,
                connectTimeout: is_numeric($config['connect_timeout'] ?? null) ? (int) $config['connect_timeout'] : 10,
                appInfo: is_string($config['app_info'] ?? null) ? $config['app_info'] : null,
                retries: is_numeric($config['retries'] ?? null) ? (int) $config['retries'] : 2,
                sendingKey: is_string($config['sending_key'] ?? null) ? $config['sending_key'] : null,
                managementKey: is_string($config['management_key'] ?? null) ? $config['management_key'] : null,
            );
        });

        $this->app->singleton(WebhookVerifier::class, function (Application $app): WebhookVerifier {
            /** @var array{signing_secret?: mixed, tolerance?: mixed} $config */
            $config = $app->make('config')->get('prontuno.webhooks', []);

            return new WebhookVerifier(
                signingSecret: is_string($config['signing_secret'] ?? null) ? $config['signing_secret'] : null,
                tolerance: is_numeric($config['tolerance'] ?? null) ? (int) $config['tolerance'] : 300,
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->callAfterResolving(MailManager::class, function (MailManager $manager): void {
            $manager->extend('prontuno', function (array $config): ProntunoTransport {
                $client = $this->app->make(Prontuno::class);
                $key = $config['key'] ?? null;

                return new ProntunoTransport(is_string($key) && trim($key) !== '' ? $client->withSendingKey($key) : $client);
            });
        });

        $this->callAfterResolving('router', function (Router $router): void {
            $router->aliasMiddleware('prontuno.webhook', VerifyProntunoWebhook::class);
        });

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/prontuno.php' => config_path('prontuno.php'),
            ], ['prontuno', 'prontuno-config']);
        }
    }
}
