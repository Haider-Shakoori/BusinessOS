# BusinessOS

Batch 1: Laravel 12 application foundation with Blade, Tailwind CSS 4, Alpine.js 3, and Vite 6.

## Local setup

Requirements: PHP 8.2+ (8.3+ preferred), Composer, and Node.js 22 LTS with npm. MySQL 8+ is the application database; it is not required to render the initial welcome page.

1. Run `composer install`.
2. Copy `.env.example` to `.env` if it does not exist.
3. Run `php artisan key:generate` once for a new installation.
4. Run `npm ci` and `npm run build`.
5. Run `php artisan serve`, or use `composer dev` for PHP and Vite development servers.

Set database credentials only in `.env`. File sessions and cache allow initial startup without database access. Only Laravel's default migrations are included; no migrations run automatically. Once a database is configured, apply them with `php artisan migrate` when needed.

The queue connection defaults to `database`. No queue processing is needed in Batch 1. Hosts without queue processing can set `QUEUE_CONNECTION=sync`; database queues require migrated tables and a worker or scheduled processing when queued features are introduced.

## Hosting baseline

Serve only the `public/` directory. Keep `.env`, source code, `vendor/`, and private storage outside the web document root. Grant the PHP process write access only where needed (`storage/` and `bootstrap/cache/`); do not use blanket world-writable permissions.

For production, set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` to the HTTPS URL, and `SESSION_SECURE_COOKIE=true`. Configure HTTPS through the host. Generate and retain a private application key per installation; never commit `.env` or rotate an existing key casually. Build assets locally and upload `public/build/` when Node.js is unavailable on the host.

The local disk uses `storage/app/private`; the public disk is separate at `storage/app/public`. No public storage link is needed in Batch 1. Logging defaults to files under `storage/logs/` and mail uses the log transport until configured.

`APP_TIMEZONE` controls the infrastructure timezone, defaulting to UTC. Business-specific localization and timezone settings belong to later batches.

Approved plans and batch status are in `docs/`. Batch 2 has not started.

Composer uses standard PSR autoloading for local setup because optimized classmap generation stalled on this Windows host. Production deployments can opt into `composer install --no-dev --optimize-autoloader`.
