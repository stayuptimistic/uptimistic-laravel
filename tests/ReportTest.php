<?php

namespace Uptimistic\Laravel\Tests;

use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase;
use Uptimistic\Laravel\Report;
use Uptimistic\Laravel\UptimisticServiceProvider;

class ReportTest extends TestCase
{
    private const SECRET = 'a-report-secret-of-forty-characters-long';

    protected function getPackageProviders($app)
    {
        return [UptimisticServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('uptimistic.secret', self::SECRET);
        $app['config']->set('app.name', 'Shop');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');

        $fixtures = __DIR__.'/fixtures/app';
        $app->singleton(Report::class, function ($app) use ($fixtures) {
            return new Report($app, $fixtures.'/vendor/composer', $fixtures);
        });
    }

    /**
     * The headers Uptimistic sends, signed the same way.
     *
     * @return array<string, string>
     */
    private function signed(string $uri, ?int $timestamp = null, string $secret = self::SECRET): array
    {
        $timestamp = $timestamp ?? time();

        return [
            'X-Uptimistic-Timestamp' => (string) $timestamp,
            'X-Uptimistic-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$uri, $secret),
        ];
    }

    /**
     * @return list<array<string, string|null>>
     */
    private function components(string $uri = '/uptimistic/report', array $server = []): array
    {
        $response = $this->withHeaders($this->signed($uri))->withServerVariables($server)->get($uri);
        $response->assertOk();

        return $response->json('components');
    }

    /**
     * @param  list<array<string, string|null>>  $components
     * @return array<string, string|null>|null
     */
    private function find(array $components, string $name, ?string $ecosystem = null): ?array
    {
        foreach ($components as $component) {
            if ($component['name'] === $name && ($ecosystem === null || ($component['ecosystem'] ?? null) === $ecosystem)) {
                return $component;
            }
        }

        return null;
    }

    public function test_a_signed_request_gets_the_report(): void
    {
        $response = $this->withHeaders($this->signed('/uptimistic/report'))->get('/uptimistic/report');

        $response->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('format', 'uptimistic.app-report/1')
            ->assertJsonPath('app.name', 'Shop')
            ->assertJsonPath('app.environment', 'testing');

        $components = $response->json('components');
        $this->assertSame(['type' => 'platform', 'name' => 'Laravel', 'version' => $this->app->version()], $components[0]);
        $this->assertSame(['type' => 'runtime', 'name' => 'PHP', 'version' => PHP_VERSION], $components[1]);
        $this->assertSame('database', $this->find($components, 'sqlite')['type']);
    }

    public function test_the_report_sets_no_cookies(): void
    {
        $response = $this->withHeaders($this->signed('/uptimistic/report'))->get('/uptimistic/report');

        $this->assertSame([], $response->headers->getCookies());
    }

    public function test_composer_packages_are_reported_without_the_dev_ones(): void
    {
        $components = $this->components();

        $this->assertSame(
            ['type' => 'package', 'ecosystem' => 'Packagist', 'name' => 'laravel/framework', 'version' => 'v10.48.4'],
            $this->find($components, 'laravel/framework', 'Packagist')
        );
        $this->assertNotNull($this->find($components, 'guzzlehttp/guzzle', 'Packagist'));
        $this->assertNull($this->find($components, 'phpunit/phpunit', 'Packagist'));
    }

    public function test_composer_1_package_lists_are_read_too(): void
    {
        $fixtures = __DIR__.'/fixtures/composer1';
        $this->app->singleton(Report::class, function ($app) use ($fixtures) {
            return new Report($app, $fixtures, $fixtures);
        });

        $this->assertSame('6.5.8', $this->find($this->components(), 'guzzlehttp/guzzle', 'Packagist')['version']);
    }

    public function test_npm_dependencies_and_shipped_dev_dependencies_are_reported(): void
    {
        $components = $this->components();

        $this->assertSame('3.7.1', $this->find($components, 'jquery', 'npm')['version']);
        $this->assertSame('19.1.0', $this->find($components, 'react', 'npm')['version']);
        $this->assertSame('4.1.4', $this->find($components, 'tailwindcss', 'npm')['version']);
        $this->assertNull($this->find($components, 'vite', 'npm'));
    }

    public function test_the_web_server_comes_from_the_request(): void
    {
        $components = $this->components('/uptimistic/report', ['SERVER_SOFTWARE' => 'Apache/2.4.62 (Unix)']);

        $this->assertSame(['type' => 'server', 'name' => 'Apache', 'version' => '2.4.62'], $this->find($components, 'Apache'));
    }

    public function test_a_server_without_a_version_is_reported_without_one(): void
    {
        $components = $this->components('/uptimistic/report', ['SERVER_SOFTWARE' => 'LiteSpeed']);

        $this->assertSame(['type' => 'server', 'name' => 'LiteSpeed', 'version' => null], $this->find($components, 'LiteSpeed'));
    }

    public function test_php_s_built_in_server_is_not_a_web_server(): void
    {
        $components = $this->components('/uptimistic/report', ['SERVER_SOFTWARE' => 'PHP 8.4.1 Development Server']);

        $this->assertSame([], array_values(array_filter($components, function (array $component) {
            return $component['type'] === 'server';
        })));
    }

    public function test_debug_mode_is_reported(): void
    {
        config(['app.debug' => true]);

        $this->withHeaders($this->signed('/uptimistic/report'))->get('/uptimistic/report')
            ->assertJsonPath('app.debug', true);
    }

    public function test_the_query_string_is_part_of_the_signature(): void
    {
        $this->withHeaders($this->signed('/uptimistic/report?site=shop'))->get('/uptimistic/report?site=shop')->assertOk();
        $this->withHeaders($this->signed('/uptimistic/report'))->get('/uptimistic/report?site=shop')->assertNotFound();
    }

    public function test_unsigned_requests_get_a_404(): void
    {
        $this->get('/uptimistic/report')->assertNotFound();
    }

    public function test_a_wrong_signature_gets_a_404(): void
    {
        $this->withHeaders($this->signed('/uptimistic/report', null, str_repeat('x', 40)))->get('/uptimistic/report')->assertNotFound();
    }

    public function test_an_old_or_future_request_gets_a_404(): void
    {
        $this->withHeaders($this->signed('/uptimistic/report', time() - 301))->get('/uptimistic/report')->assertNotFound();
        $this->withHeaders($this->signed('/uptimistic/report', time() + 301))->get('/uptimistic/report')->assertNotFound();
    }

    public function test_a_signature_is_accepted_once(): void
    {
        $headers = $this->signed('/uptimistic/report');

        $this->withHeaders($headers)->get('/uptimistic/report')->assertOk();
        $this->withHeaders($headers)->get('/uptimistic/report')->assertNotFound();
    }

    public function test_without_a_long_enough_secret_nobody_gets_the_report(): void
    {
        config(['uptimistic.secret' => 'short']);

        $this->withHeaders($this->signed('/uptimistic/report', null, 'short'))->get('/uptimistic/report')->assertNotFound();
    }

    public function test_the_command_shows_the_report_and_the_url(): void
    {
        $this->assertSame(0, Artisan::call('uptimistic:report'));
        $output = Artisan::output();

        $this->assertStringContainsString('uptimistic.app-report/1', $output);
        $this->assertStringContainsString('Monitor URL: http://localhost/uptimistic/report', $output);
    }

    public function test_the_command_warns_without_a_secret(): void
    {
        config(['uptimistic.secret' => null]);

        $this->assertSame(1, Artisan::call('uptimistic:report'));
        $this->assertStringContainsString('UPTIMISTIC_REPORT_SECRET is not set', Artisan::output());
    }
}
