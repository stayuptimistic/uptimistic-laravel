# Uptimistic for Laravel

Lets the **App report** monitor in [Uptimistic](https://github.com/stayuptimistic) see what a Laravel app
runs on: Laravel, PHP, the web server, the database, Redis, and every Composer and npm package, all with
their versions. Uptimistic uses this list to show updates, end-of-life dates and known vulnerabilities
across all your apps.

The report is read-only. It answers only requests that Uptimistic signs with your secret; any other
request gets a plain 404.

Supports Laravel 8–13 and PHP 7.4 or later, so it also covers the older apps that most need watching.

## Installation

```bash
composer require stayuptimistic/uptimistic-laravel
```

1. In Uptimistic, add a monitor of the **App report** type. The form creates a secret for you.
2. Put the secret in the app's `.env`:

   ```dotenv
   UPTIMISTIC_REPORT_SECRET=the-secret-from-the-monitor
   ```

3. Set the monitor's URL to the app's address followed by `/uptimistic/report`, for example
   `https://shop.example.com/uptimistic/report`, then save the monitor.

To check the setup, run `php artisan uptimistic:report`. It prints the report and the monitor URL, or a
warning if the secret is missing.

If you ran your config cache (`php artisan config:cache`), run it again after changing `.env`.

## What is reported

```json
{
    "format": "uptimistic.app-report/1",
    "app": {"name": "Shop", "environment": "production", "debug": false},
    "components": [
        {"type": "platform", "name": "Laravel", "version": "12.3.0"},
        {"type": "runtime", "name": "PHP", "version": "8.4.5"},
        {"type": "server", "name": "nginx", "version": "1.26.2"},
        {"type": "database", "name": "mysql", "version": "8.0.41"},
        {"type": "package", "ecosystem": "Packagist", "name": "guzzlehttp/guzzle", "version": "7.9.2"},
        {"type": "package", "ecosystem": "npm", "name": "react", "version": "19.1.0"}
    ]
}
```

- **Laravel and PHP**: the versions in use.
- **Web server**: taken from the request's `SERVER_SOFTWARE`, where the server reveals it.
- **Database**: the driver of the default connection and the server's version.
- **Redis**: reported only when the cache, queue or sessions use it.
- **Composer packages**: everything installed for production, read from `vendor/composer/installed.json`.
  Development-only packages are left out. Works with Composer 1 and 2.
- **npm packages**: everything in `package.json`'s `dependencies`, plus the `devDependencies` that end up
  in the built front end (React, Vue, Tailwind CSS, jQuery and others; see the config). Versions come from
  `package-lock.json`. Build tools such as Vite are left out, so their advisories don't count against the app.
- **Debug mode**: whether `app.debug` is on. Uptimistic flags it when it's on in production.

The report contains no paths, host names, credentials or other settings.

## Security

- **Signed requests**: each request carries `X-Uptimistic-Timestamp` and
  `X-Uptimistic-Signature: sha256=HMAC-SHA256(secret, "{timestamp}.{path and query}")`. The signature is
  compared in constant time.
- **No replays**: a request is valid for 5 minutes (`tolerance`), and each signature is accepted once.
- **Strong secret required**: without a secret of at least 32 characters, the endpoint answers nobody.
- **The route stays hidden**: every refusal is a 404, so a scan can't tell that the route exists.
- **No session or cookies**: the route is outside the `web` middleware group, and it is rate limited
  (`throttle:10,1`).
- **No caching**: responses are sent with `Cache-Control: no-store` and `X-Robots-Tag: noindex`.

## Configuration

These environment variables are enough for most apps:

| Variable                   | Default             |
|----------------------------|---------------------|
| `UPTIMISTIC_REPORT_SECRET` | none (report off)   |
| `UPTIMISTIC_REPORT_PATH`   | `uptimistic/report` |

For the rest (middleware, tolerance, which npm `devDependencies` are reported), publish the config file:

```bash
php artisan vendor:publish --tag=uptimistic-config
```

## Coming from the setup snippet

If the app already has the route from Uptimistic's monitor form, remove these from the app:

- the `uptimistic/report` route in `routes/web.php`;
- the `uptimistic` entry in `config/services.php`.

The package reads the same `UPTIMISTIC_REPORT_SECRET`, so `.env` and the monitor stay as they are.

## Testing

```bash
composer install
vendor/bin/phpunit
```

## License

MIT. See [LICENSE](LICENSE).
