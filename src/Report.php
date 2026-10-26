<?php

namespace Uptimistic\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use PDO;
use Throwable;

/**
 * What the app runs on, in the "uptimistic.app-report/1" format: Laravel,
 * PHP, the web server, the database, Redis when the app uses it, the
 * Composer packages installed for production and the npm packages the front
 * end is built from. Names and versions only; no paths, hosts or settings.
 */
class Report
{
    public const FORMAT = 'uptimistic.app-report/1';

    /** @var Application */
    private $app;

    /** @var string The vendor/composer folder, where installed.json is. */
    private $composerDir;

    /** @var string */
    private $basePath;

    public function __construct(Application $app, string $composerDir, string $basePath)
    {
        $this->app = $app;
        $this->composerDir = rtrim($composerDir, '/');
        $this->basePath = rtrim($basePath, '/');
    }

    /**
     * @param  string|null  $serverSoftware  The SERVER_SOFTWARE of the request, as "nginx/1.24.0".
     * @return array{format: string, app: array{name: mixed, environment: string, debug: bool}, components: list<array<string, string|null>>}
     */
    public function build(?string $serverSoftware = null): array
    {
        $components = [
            ['type' => 'platform', 'name' => 'Laravel', 'version' => $this->app->version()],
            ['type' => 'runtime', 'name' => 'PHP', 'version' => PHP_VERSION],
        ];

        foreach ([$this->server($serverSoftware), $this->database(), $this->redis()] as $component) {
            if ($component !== null) {
                $components[] = $component;
            }
        }

        return [
            'format' => self::FORMAT,
            'app' => [
                'name' => config('app.name'),
                'environment' => (string) $this->app->environment(),
                'debug' => (bool) config('app.debug'),
            ],
            'components' => array_merge($components, $this->composerPackages(), $this->npmPackages()),
        ];
    }

    /**
     * "nginx/1.24.0", "Apache/2.4.62 (Unix)" or just "LiteSpeed".
     *
     * @return array<string, string|null>|null
     */
    private function server(?string $software): ?array
    {
        if ($software === null || preg_match('~^([A-Za-z][\w.-]*)(?:/(\S+))?~', trim($software), $match) !== 1) {
            return null;
        }

        // "PHP 8.4.1 Development Server" is php -S, not a web server.
        if ($match[1] === 'PHP') {
            return null;
        }

        return ['type' => 'server', 'name' => $match[1], 'version' => $match[2] ?? null];
    }

    /**
     * @return array<string, string|null>|null
     */
    private function database(): ?array
    {
        try {
            $connection = DB::connection();
            $driver = $connection->getDriverName();
        } catch (Throwable $e) {
            return null;
        }

        try {
            $version = $connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
        } catch (Throwable $e) {
            $version = null;
        }

        return ['type' => 'database', 'name' => $driver, 'version' => is_scalar($version) ? (string) $version : null];
    }

    /**
     * Redis, asked only when the cache, queue or sessions use it.
     *
     * @return array<string, string|null>|null
     */
    private function redis(): ?array
    {
        $uses = [config('cache.default'), config('queue.default'), config('session.driver')];

        if (! in_array('redis', $uses, true)) {
            return null;
        }

        try {
            $info = Redis::connection()->info('server');
        } catch (Throwable $e) {
            return null;
        }

        // phpredis answers flat, Predis by section.
        $version = $info['redis_version'] ?? $info['Server']['redis_version'] ?? null;

        return is_string($version) ? ['type' => 'database', 'name' => 'Redis', 'version' => $version] : null;
    }

    /**
     * The packages Composer installed, development-only ones left out.
     *
     * @return list<array<string, string|null>>
     */
    private function composerPackages(): array
    {
        $installed = $this->json($this->composerDir.'/installed.json');
        // Composer 2 wraps the list and names the dev packages; Composer 1 is a bare list.
        $packages = isset($installed['packages']) && is_array($installed['packages']) ? $installed['packages'] : $installed;
        $dev = isset($installed['dev-package-names']) && is_array($installed['dev-package-names']) ? $installed['dev-package-names'] : [];
        $components = [];

        foreach ($packages as $package) {
            if (! is_array($package) || ! is_string($package['name'] ?? null) || in_array($package['name'], $dev, true)) {
                continue;
            }

            $components[] = [
                'type' => 'package',
                'ecosystem' => 'Packagist',
                'name' => $package['name'],
                'version' => is_string($package['version'] ?? null) ? $package['version'] : null,
            ];
        }

        return $components;
    }

    /**
     * package.json's dependencies, and the devDependencies that ship in the
     * front end, at the versions package-lock.json installed.
     *
     * @return list<array<string, string|null>>
     */
    private function npmPackages(): array
    {
        $manifest = $this->json($this->basePath.'/package.json');
        $lock = $this->json($this->basePath.'/package-lock.json');
        $shipped = (array) config('uptimistic.npm.dev_dependencies', []);
        $names = array_keys(is_array($manifest['dependencies'] ?? null) ? $manifest['dependencies'] : []);

        foreach (array_keys(is_array($manifest['devDependencies'] ?? null) ? $manifest['devDependencies'] : []) as $name) {
            if (in_array($name, $shipped, true) && ! in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        $components = [];

        foreach ($names as $name) {
            // Lock file v2 and v3 list "node_modules/<name>", v1 "dependencies".
            $version = $lock['packages']['node_modules/'.$name]['version'] ?? $lock['dependencies'][$name]['version'] ?? null;

            $components[] = [
                'type' => 'package',
                'ecosystem' => 'npm',
                'name' => (string) $name,
                'version' => is_string($version) ? $version : null,
            ];
        }

        return $components;
    }

    /**
     * @return array<mixed>
     */
    private function json(string $file): array
    {
        if (! is_file($file) || ! is_readable($file)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : [];
    }
}
