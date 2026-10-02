# Truth & Social — Laravel API

Laravel 13 (PHP 8.4) replacement for the Django/DRF backend. Drop-in compatible with the Next.js frontend
(same URLs incl. trailing slashes, JSON shapes, pagination envelope, error bodies, `{access, refresh, user}` login contract).

## Requirements
PHP >= 8.4.1 (the locked Symfony 8 packages require it) with `pdo_mysql, gd (jpeg/png/webp/gif), exif, mbstring, openssl, json, fileinfo, curl`; Composer 2;
MySQL 8 / MariaDB 10.6+ (tested on MariaDB 10.11 and MySQL 8.0). Redis (phpredis) is OPTIONAL: only for the VPS profile. `pdo_pgsql` is needed only to run the Django importer.
PHP ini: `upload_max_filesize >= 6M`, `post_max_size >= 12M`, `memory_limit >= 256M`.
Shared hosting (Hostinger Business): see `docs/HOSTINGER_DEPLOYMENT.md`; run `php artisan portal:doctor` after every deploy.

## Install
```
composer install --no-dev --optimize-autoloader
cp .env.example .env && php artisan key:generate
# edit .env: DB_*, REDIS_*, FRONTEND_URL, CORS_ALLOWED_ORIGINS, TRUSTED_PROXIES, BUNNY_*, RAZORPAY_*, WATI_*, OPENAI_*, COPYLEAKS_*, MAIL_*
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan event:cache
```
Development: `php artisan serve --port=8000` (the frontend default base URL is `http://localhost:8000/api`).

## Processes (production): two supported profiles (`.env.example`)
- PROFILE A, shared hosting, no Redis, no daemons: `CACHE_STORE=database QUEUE_CONNECTION=database ANALYTICS_DRIVER=database QUEUE_VIA_SCHEDULER=true`
  and ONE cron entry `* * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1` (the scheduler also drains the queue every minute).
- PROFILE B, VPS with Redis: `CACHE_STORE=redis QUEUE_CONNECTION=redis ANALYTICS_DRIVER=redis QUEUE_VIA_SCHEDULER=false`,
  nginx + php-fpm (document root `public/`), `php artisan queue:work redis --tries=3 --max-time=3600` under supervisor,
  and the same cron entry (or `php artisan schedule:work` under supervisor).
- Scheduled: publish due articles (1 min), scheduler heartbeat (1 min), analytics flush (1 min, Redis driver only), subscription expiry/reminders (hourly),
  notify subscribers of published articles (5 min), plagiarism pending sweep (15 min), expired database-cache rows (hourly), Sanctum + failed-job pruning (daily).
- `php artisan portal:doctor [--json] [--strict]`: read-only diagnostics (exit 1 on blocking problems).

## Importing the Django data (READ-ONLY on Django)
Set `LEGACY_DB_*` (the Django DB) and `DB_*` (the NEW empty Laravel DB — must be a different database), then:
```
php artisan migrate --force
php artisan portal:import-django --dry-run --report=storage/import/dry        # validation only; review the .md
php artisan portal:import-django --rehearse --resolve-defaults --report=storage/import/rehearse   # full import, rolled back
php artisan portal:import-django --resolve-defaults --report=storage/import/final                 # the real import
```
Exit codes: 0 ok, 2 blocking findings, 3 target not empty, 4 relationship validation failed, 10 legacy DB problem, 11 read-only guarantee failed, 12 source == target, 20 entity failure.
After import: `php artisan articles:reindex-search` (already done by the importer unless `--skip-search-index`).
Users keep their Django PBKDF2 passwords (verified by Laravel and upgraded to bcrypt on first login). JWTs are not imported: users log in again.

## Tests
Create an empty MySQL/MariaDB database, then `DB_DATABASE=<name> php artisan test`. External services are mocked. Tests marked `#[Group('redis')]` (the Redis analytics driver) need a Redis on 127.0.0.1:6379 (DBs 10-12 and 15 are used and flushed); everything else runs without Redis: `php artisan test --exclude-group=redis`, also with `CACHE_STORE=database QUEUE_CONNECTION=database ANALYTICS_DRIVER=database`.
