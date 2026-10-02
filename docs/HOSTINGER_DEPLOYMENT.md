# Deploying the Truth & Social API on Hostinger Business (shared hosting)

Status legend used below: **[verified]** = checked in this repository or in the local test environment (PHP 8.4, MariaDB 10.11);
**[unverified]** = a statement about Hostinger's platform that could not be tested here. Hostinger changes plans, PHP versions and
limits over time, so confirm every **[unverified]** item in hPanel (or with their support) before relying on it.
`php artisan portal:doctor` (section 14) checks most of the technical prerequisites on the real server.

## 1. What Hostinger Business can and cannot do for this app

| Need | Shared hosting | Notes |
|---|---|---|
| PHP 8.4, `pdo_mysql`, `gd` (+WebP), `exif`, `mbstring`, `openssl`, `curl`, `fileinfo` | yes, if selectable in hPanel **[unverified]** | Section 2. `portal:doctor` verifies each extension. |
| MySQL / MariaDB | yes (hPanel database manager) **[unverified: server version]** | App is tested on MariaDB 10.11; FULLTEXT search needs InnoDB FULLTEXT (MySQL 5.7+/MariaDB 10.3+ minimum enforced by the doctor). |
| SSH + Composer | yes on Business **[unverified]** | Otherwise upload `vendor/` built elsewhere (section 5). |
| Cron | yes, minimum interval 1 minute | Section 9. |
| Redis | generally not available | Not needed: PROFILE A works without it (section 11). |
| Long-running processes (`queue:work` daemon, `schedule:work`, supervisor) | **not viable**: processes are killed | Everything is driven by one cron entry instead (sections 9-10). |
| Outbound HTTPS (Bunny, Razorpay, WATI, OpenAI, Copyleaks, SMTP) | normally yes **[unverified]** | Some plans restrict outbound SMTP ports; use Hostinger's own SMTP host from hPanel. |
| Inbound webhooks (Razorpay, Copyleaks) | yes (public HTTPS) | Section 13. |

Architecture consequences (all implemented and tested, `docs` = this file):
- No Redis and no daemons: `CACHE_STORE=database`, `QUEUE_CONNECTION=database`, `ANALYTICS_DRIVER=database`, `QUEUE_VIA_SCHEDULER=true`.
- No uploaded files are stored on the server: article images go to Bunny.net, so no `storage:link` and no large disk use.
- Latency is bounded by the 1-minute cron (section 10).

## 2. PHP version: 8.4 is required

Select **PHP 8.4** for the API's domain/subdomain in hPanel (Advanced, PHP Configuration) **[unverified: exact menu names and whether 8.4 is offered on your plan]**.
Also check the version the **SSH/cron CLI** uses: it can differ from the web version. Run `php -v` over SSH; if it is older, call the versioned
binary explicitly (on CloudLinux hosts often `/opt/alt/php84/usr/bin/php`, **[unverified]**, find it with `ls /opt/alt/ | grep php` or `which -a php`).

What happens on PHP 8.3 (confirmed from the code in `vendor/` and `composer.lock`):
1. `composer.lock` pins `symfony/http-foundation` v8.1.8, whose own `composer.json` requires `php >= 8.4.1`. `composer install` on 8.3 therefore refuses to
   install (platform requirement), and the generated `vendor/composer/platform_check.php` throws `RuntimeException: Composer detected issues in your platform: Your Composer dependencies require a PHP version ">= 8.4.1"`
   on every web request and every `artisan` command. (The root `composer.json` still says `"php": "^8.3"`; that constraint is stale and should be raised to `^8.4` at the next dependency update.)
2. If someone bypasses that check (`--ignore-platform-reqs` and `platform-check` disabled), `Symfony\Component\HttpFoundation\Request::createFromGlobals()` (vendor/symfony/http-foundation/Request.php) calls
   PHP 8.4's `request_parse_body()` for **every** `PUT`, `PATCH`, `DELETE` (and `QUERY`) request, before it looks at the content type, and only catches `\RequestParseBodyException`.
   On 8.3 that function does not exist, so all such requests die with a fatal `Call to undefined function request_parse_body()` (HTTP 500), not just multipart ones.
   On 8.4 the same call is what makes multipart/form-data bodies of PUT/PATCH (used for PATCH with file/field uploads) populate `$_POST`/`$_FILES`. `GET` and `POST` would still work on 8.3, but the app is not supported there.
3. Conclusion: PHP < 8.4.1 = unsupported. Do not "fix" it with flags.

## 3. Create the MySQL database and user (hPanel)

1. hPanel, Databases, Management: create a database, a user and a strong password, and attach the user to the database with all privileges.
   Hostinger prefixes names (for example `u123456789_truth`, `u123456789_api`) **[unverified: exact prefix scheme]**; use the full prefixed names in `.env`.
2. `DB_HOST`: try `localhost` first; if the connection is refused use `127.0.0.1` **[unverified which one Hostinger requires]**. `DB_PORT=3306`.
3. Character set / collation: the app connects with `utf8mb4` / `utf8mb4_unicode_ci` by itself. If hPanel lets you choose, pick `utf8mb4_unicode_ci`.
   `portal:doctor` reports the database collation, time zone handling, `innodb_ft_min_token_size` and `max_allowed_packet`.
4. Time zone: the app pins its DB session to the numeric offset of `APP_TIMEZONE` (default `Asia/Kolkata`, +05:30), so no server time-zone tables and no privileges are needed. Do not set `DB_TIMEZONE` to a zone name.
5. FULLTEXT: `innodb_ft_min_token_size` cannot be changed on shared hosting (default 3). Words of 1-2 letters are not searchable. If the server value differs from 3, set `SEARCH_MIN_TOKEN_SIZE` to it (the doctor warns).
6. Only the NEW Laravel database goes here. The importer (`portal:import-django`) needs read access to the OLD Django PostgreSQL database and `pdo_pgsql`; run it on another machine (your PC or a VPS) against a copy,
   then dump the resulting MySQL database and import the dump through hPanel/phpMyAdmin or `mysql < dump.sql`. It is not meant to run on shared hosting.

## 4. Getting the code onto the server

Recommended layout (application OUTSIDE the web root; only `public/` is exposed):

```
/home/uXXXXXXXXX/domains/example.com/backend-laravel/     <- the whole repository (artisan, app/, vendor/, .env ...)
/home/uXXXXXXXXX/domains/example.com/backend-laravel/public   <- document root of api.example.com
```

- Git (hPanel Git integration or `git clone` over SSH) **[unverified: availability]**, or upload a zip through the File Manager/SFTP and extract it.
- Never upload `.env`, `storage/logs/*`, `node_modules`, tests' artefacts or the (large, 4 GB here) development `vendor/`; see section 5.
- Keep `.env` out of `public/` (it is: the repo root is above the document root).

## 5. Dependencies: `composer install --no-dev -o`

Over SSH in the project directory (use the PHP 8.4 binary for Composer too if `php` defaults to an older version, for example `/opt/alt/php84/usr/bin/php $(which composer) install ...`):

```
composer install --no-dev --optimize-autoloader --no-interaction
```

If Composer is missing or runs out of memory (`memory_limit` in CLI can be low on shared plans **[unverified]**), build `vendor/` on your PC with PHP 8.4
(`composer install --no-dev -o`), and upload the resulting `vendor/` directory (a few tens of MB without dev packages). Composer scripts run `package:discover`; if the
first `artisan` command fails because `bootstrap/cache/` is not writable see section 8.

## 6. Production `.env`

Copy `.env.example` to `.env` (`cp .env.example .env && php artisan key:generate`) and fill it in. Recommended final file for shared hosting (PROFILE A):

```
APP_NAME="Truth & Social API"
APP_ENV=production
APP_KEY=base64:...generated by key:generate...
APP_DEBUG=false
APP_URL=https://api.example.com
APP_TIMEZONE=Asia/Kolkata
APP_LOCALE=en
APP_MAINTENANCE_DRIVER=file

FRONTEND_URL=https://www.example.com
CORS_ALLOWED_ORIGINS=https://www.example.com
TRUSTED_PROXIES=127.0.0.1

LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=warning
LOG_DAILY_DAYS=14

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=u123456789_truth
DB_USERNAME=u123456789_api
DB_PASSWORD=...

CACHE_STORE=database
QUEUE_CONNECTION=database
ANALYTICS_DRIVER=database
QUEUE_VIA_SCHEDULER=true
CACHE_PREFIX=truthsocial-cache-
SESSION_DRIVER=array

AUTH_ACCESS_TOKEN_MINUTES=15
AUTH_REFRESH_TOKEN_DAYS=7

BUNNY_STORAGE_ZONE=...
BUNNY_STORAGE_API_KEY=...
BUNNY_STORAGE_REGION=
BUNNY_PULL_ZONE_URL=https://your-zone.b-cdn.net
MAX_IMAGE_UPLOAD_SIZE_MB=5
IMAGE_MAX_DIMENSION_PX=2000

RAZORPAY_KEY_ID=...
RAZORPAY_KEY_SECRET=...
RAZORPAY_WEBHOOK_SECRET=...

WATI_API_ENDPOINT=...
WATI_ACCESS_TOKEN=...
WATI_OTP_TEMPLATE_NAME=...
WATI_SUBSCRIPTION_ACTIVATED_TEMPLATE_NAME=...
WATI_PAYMENT_FAILED_TEMPLATE_NAME=...
WATI_SUBSCRIPTION_EXPIRING_TEMPLATE_NAME=...
WATI_SUBSCRIPTION_EXPIRED_TEMPLATE_NAME=...
WATI_ARTICLE_PUBLISHED_TEMPLATE_NAME=...

OPENAI_API_KEY=...
OPENAI_MODEL=gpt-4o-mini

COPYLEAKS_EMAIL=...
PLAGIARISM_API_KEY=...
COPYLEAKS_WEBHOOK_BASE_URL=https://api.example.com
COPYLEAKS_WEBHOOK_SECRET=...
COPYLEAKS_SANDBOX_MODE=false

MAIL_MAILER=smtp
MAIL_HOST=smtp.hostinger.com        # [unverified] use the SMTP host/port shown in hPanel for your mailbox
MAIL_PORT=465                        # [unverified] 465 (SSL) or 587 (TLS) as per hPanel
MAIL_USERNAME=noreply@example.com
MAIL_PASSWORD=...
MAIL_SCHEME=smtps                    # smtps for port 465 implicit TLS; null for 587 STARTTLS
MAIL_FROM_ADDRESS=noreply@example.com
MAIL_FROM_NAME="${APP_NAME}"

ANALYTICS_VIEW_COOLDOWN_SECONDS=30
```

Do not put the Redis variables in this file: with PROFILE A nothing reads them and no Redis connection is ever made (verified by running artisan and HTTP requests with the phpredis extension removed and `REDIS_HOST` unreachable).
LEGACY_DB_* stays empty. After editing `.env` run `php artisan config:cache` (env() is only read from `.env` until config is cached; after caching, re-run it whenever `.env` changes).

`TRUSTED_PROXIES` (read through `config/trustedproxy.php`, so it keeps working after `config:cache`) matters more than it looks: per-IP rate limits and the view-dedupe use `$request->ip()`. Keep `127.0.0.1` when PHP sees the real client address
(no proxy in front). If your domain sits behind Cloudflare or another proxy/CDN, its addresses must be listed here, otherwise every visitor shares one IP and the 100/minute anonymous limit will
throttle the whole site **[unverified: whether hPanel puts a proxy in front of your site]**. Check after go-live:
`SELECT `key` FROM cache WHERE `key` LIKE '%throttle:anon:%';` must show DIFFERENT real visitor IPs, not one server IP.

## 7. Document root / `public` mapping

The API must be served from `public/`. Options, best first:

1. **Subdomain with a custom document root (recommended).** hPanel, Domains/Subdomains: create `api.example.com` and set its folder (document root) to
   `domains/example.com/backend-laravel/public` **[unverified: Business plan allows an arbitrary folder]**. Nothing else to do; `.htaccess` in `public/` already routes to `index.php`
   and forwards the `Authorization` header (required for the Bearer tokens).
2. **Symlink**, if you can only choose a folder inside `public_html`: over SSH, `ln -s ../backend-laravel/public public_html/api` (or the subdomain folder)
   **[unverified: the web server must follow symlinks; test with `/api/health`]**.
3. **Copy `public/` and edit `index.php`** (works everywhere): keep the application in `~/backend-laravel` (outside `public_html`), copy the contents of `backend-laravel/public/*` (including the hidden `.htaccess`) to the
   web folder (for example `~/domains/example.com/public_html/api/`), and change the three paths in that copied `index.php`:
   ```php
   if (file_exists($maintenance = __DIR__.'/../../../backend-laravel/storage/framework/maintenance.php')) { require $maintenance; }
   require __DIR__.'/../../../backend-laravel/vendor/autoload.php';
   $app = require_once __DIR__.'/../../../backend-laravel/bootstrap/app.php';
   ```
   (adjust the number of `../` to your layout). Re-copy `public/` after each deploy that changes it (rare).

Whichever you choose, `https://api.example.com/api/health` must answer `{"status":"ok","service":"news-portal-backend","database":"ok"}` (HTTP 200; 503 when the database is down).
The API lives under `/api/...` (routes keep the Django paths, trailing slashes accepted). Force HTTPS in hPanel (Security, SSL) so bearer tokens never travel in clear text.

The `public/storage` symlink (`php artisan storage:link`) is **not needed**: the app serves nothing from `storage/` (images live on Bunny). Do not create it (symlink creation is sometimes blocked on shared plans anyway).

## 8. File permissions

```
cd ~/domains/example.com/backend-laravel
find . -type d -exec chmod 755 {} \;        # or: chmod -R u=rwX,go=rX .
find . -type f -exec chmod 644 {} \;
chmod -R ug+rwX storage bootstrap/cache
chmod 600 .env
```
On shared hosting PHP (web and cron) runs as your own account, so 755/644 (and 775 on `storage`, `bootstrap/cache`) is enough; never use 777. `portal:doctor` proves that `storage/`, `storage/logs`,
`storage/framework/cache`, `storage/framework/views` and `bootstrap/cache` accept writes. If `storage/framework/{cache,views,sessions}` or `storage/logs` are missing (some upload methods skip empty folders), create them.

## 9. Cron entries

hPanel, Advanced, Cron Jobs. There is exactly ONE entry, every minute (the minimum Hostinger allows):

```
* * * * * cd /home/uXXXXXXXXX/domains/example.com/backend-laravel && /opt/alt/php84/usr/bin/php artisan schedule:run >> /dev/null 2>&1
```
(Use the PHP 8.4 binary path valid for your account, section 2; if hPanel's form only takes a command and no schedule text, choose "every minute".)

`schedule:run` executes what is due (see `php artisan schedule:list`): publish scheduled articles (every minute), scheduler heartbeat (every minute), subscriber notifications catch-up (5 min),
plagiarism pending sweep (15 min), subscription expiry and expiry reminders (hourly), expired database-cache rows (hourly), Sanctum token and failed-job pruning (daily 03:10 / 03:20), and, with `QUEUE_VIA_SCHEDULER=true`, the queue worker (below).
`schedule:work`, `queue:work` daemons and supervisor are NOT viable on shared hosting; do not add them.

`schedule:run` starts each task as a child `php artisan ...` process with `proc_open`. If your plan disables `proc_open` (`portal:doctor` reports it as FAIL) use this fallback instead: `QUEUE_VIA_SCHEDULER=false` and one cron line per task
(`flock -n` keeps the worker from overlapping itself):
```
* * * * *    cd /path && PHP artisan articles:publish-due >> /dev/null 2>&1
* * * * *    cd /path && flock -n /tmp/ts-queue.lock PHP artisan queue:work database --stop-when-empty --max-time=50 --tries=3 >> /dev/null 2>&1
*/5 * * * *  cd /path && PHP artisan subscriptions:notify-published >> /dev/null 2>&1
*/15 * * * * cd /path && PHP artisan plagiarism:expire-pending >> /dev/null 2>&1
0 * * * *    cd /path && PHP artisan subscriptions:expire >> /dev/null 2>&1
5 * * * *    cd /path && PHP artisan subscriptions:remind-expiring >> /dev/null 2>&1
15 * * * *   cd /path && PHP artisan portal:prune-cache >> /dev/null 2>&1
10 3 * * *   cd /path && PHP artisan sanctum:prune-expired --hours=24 >> /dev/null 2>&1
20 3 * * *   cd /path && PHP artisan queue:prune-failed --hours=168 >> /dev/null 2>&1
```
(`PHP` = the full PHP 8.4 binary path; in a crontab a literal `%` must be written `\%`.) The doctor's scheduler heartbeat only advances when `schedule:run` is used, so with this fallback it will keep warning "no scheduler tick".
The fallback is **[unverified]** on Hostinger; the primary single-entry setup is what was tested locally (a real `schedule:run` published a due scheduled article and drained the resulting notification job in the same run).

## 10. Queue on shared hosting, and the honest latency

- `QUEUE_CONNECTION=database`: jobs (WhatsApp templates, queued e-mails, search reindex after taxonomy renames, subscriber notification fan-out) are rows in the `jobs` table; failures land in `failed_jobs` (`php artisan queue:failed`, `queue:retry all`).
- `QUEUE_VIA_SCHEDULER=true` adds this scheduler entry (only then; VPS deployments with a supervisor daemon leave it `false` and are unaffected):
  `queue:work database --stop-when-empty --max-time=50 --tries=3`, every minute, `withoutOverlapping(5)`. It is registered last, so within one tick it drains what the earlier tasks of that tick queued.
  The worker stops when the queue is empty (after idling about 3 s) or after 50 s, so no process outlives the cron run.
  Jobs that declare their own `$tries` keep it; `--tries=3` covers the rest. `retry_after` (180 s) is longer than the longest job timeout (120 s).
- OTP codes are sent inline in the request (never queued: a queue payload would store the code), so OTP latency is the WATI call itself.
- `dispatchAfterResponse()` was considered and deliberately not used: it would run the work in the web PHP process, subject to web execution limits, with no retry and no `failed_jobs` record.
- `QUEUE_CONNECTION=sync` also works (jobs run inside the request that queued them; no retries, no failed_jobs, slower requests). It is a last resort.

Practical latency (cron granularity is 1 minute; every task starts at the next tick, so add up to ~60 s plus cron start-up jitter **[unverified: Hostinger cron start delay under load]**):

| Action | Delay |
|---|---|
| Scheduled publishing (`articles:publish-due`) | up to ~1 minute after the scheduled time (typically < 1-2 min) |
| Subscriber notifications for a freshly published article (WhatsApp/e-mail) | same tick as publishing, otherwise the next tick: ~1-2 minutes |
| Queued e-mail / WhatsApp after payment or expiry events | next tick: up to ~1-2 minutes |
| Search index refresh after a category/tag/industry rename | up to ~1-2 minutes |
| View counts, admin analytics | immediate (ANALYTICS_DRIVER=database writes straight to `article_daily_views`) |
| Subscription expiry and "expiring soon" reminders | on the hour (up to 1 hour late) |
| Subscriber notification catch-up for other publish paths | up to 5 minutes |
| Plagiarism pending sweep | up to 15 minutes |
| Rate limiting, OTP throttling | immediate (database cache, row-locked counters) |

Execution limits: each cron run should stay well under a minute (the worker is capped at 50 s). Whether Hostinger imposes its own CPU/time limits on cron jobs, and what they are, is **[unverified]**;
CLI `max_execution_time` is normally unlimited (0) but a host can still kill long processes. If cron jobs are killed mid-run, jobs are safe: a reserved job is re-released after `retry_after` and retried; `withoutOverlapping` locks expire after 5-30 minutes.

## 11. Running without Redis (PROFILE A) versus a VPS with Redis (PROFILE B)

- PROFILE A (shared hosting): `CACHE_STORE=database QUEUE_CONNECTION=database ANALYTICS_DRIVER=database`. Rate limits (throttle scopes and named limiters `ai-check`, `plagiarism-check`), the OTP per-phone cap, scheduler mutexes (`withoutOverlapping`, `onOneServer`),
  the Copyleaks token cache and the view-cooldown all use the database cache store (`cache`, `cache_locks` tables). Verified on MariaDB with parallel processes: 800 concurrent limiter hits count exactly 800, `Cache::add` grants exactly one winner per key, 800 concurrent view records add exactly 800 views.
  `CACHE_STORE=file` also works (locks and `add()` are atomic) but its counter increment is not atomic under concurrency (400 parallel hits counted ~330), so limits become slightly permissive; prefer `database`.
  Cost: every API request performs a handful of small cache-table statements for rate limiting (`CACHE_LIMITER_STORE=file` can move only the limiter off the database if that becomes a bottleneck).
  Expired cache rows are removed hourly by `portal:prune-cache`.
- Analytics: the database driver dedupes with `Cache::add(cooldown TTL)` (viewer key = SHA-256 of user id or IP, truncated; no PII stored) and then does one atomic `INSERT ... ON DUPLICATE KEY UPDATE views = views + 1` on `article_daily_views`.
  That single statement is the durable counter (exactly-once, no buffer table, nothing to flush; `analytics:flush` is a no-op). If the increment fails the dedupe marker is removed again, and the beacon reports `recorded:false` (fail-open, never an error).
  Switching an existing deployment from `redis` to `database`: run `php artisan analytics:flush --redis` once first so pending Redis counters are not stranded.
- PROFILE B (VPS): `CACHE_STORE=redis QUEUE_CONNECTION=redis ANALYTICS_DRIVER=redis QUEUE_VIA_SCHEDULER=false` plus the `REDIS_*` variables, phpredis, and a supervisor `queue:work redis --tries=3 --max-time=3600`. The same single cron entry drives the scheduler.
- Redis is only touched when one of the three drivers says `redis`. The database/file profile creates no Redis connection at boot and works without the phpredis extension.

## 12. Upload limits (PHP ini)

Needs: `upload_max_filesize >= 6M` (images up to `MAX_IMAGE_UPLOAD_SIZE_MB`=5 MB plus overhead), `post_max_size >= 12M`, `memory_limit >= 256M` (GD decodes images in memory; the app refuses absurd canvases), `max_execution_time >= 60`.
Set them in hPanel, Advanced, PHP Configuration (PHP Options) **[unverified: which limits your plan lets you raise]**, and/or with a `.user.ini` next to `public/index.php` (honoured by PHP-FPM/LiteSpeed CGI **[unverified on Hostinger; hPanel values may override it]**):

```
upload_max_filesize = 8M
post_max_size = 16M
memory_limit = 256M
max_execution_time = 60
```
Note that `php artisan portal:doctor` reads the CLI configuration, which can differ from the web one; treat its ini warnings as a hint and confirm the web values with a temporary `phpinfo()` script that you delete immediately, or by uploading a 5 MB image through the API.

## 13. Migrate, first run, integrations

```
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan event:cache
php artisan portal:doctor
```
First-run checklist:
1. `portal:doctor` prints no `FAIL` (exit code 0). Expected `WARN`s on a fresh install: "no scheduler tick recorded yet" until the cron has fired once (re-run after 1-2 minutes; it must become `OK`), and CLI ini values that differ from the web ones.
2. `https://api.example.com/api/health` returns 200 with `"database":"ok"`.
3. Create the first admin (register through the API, then set the role in the database, or import the data): `UPDATE users SET role='ADMIN' WHERE email='you@example.com';`
4. Add the cron entry (section 9), wait two minutes, re-run `portal:doctor`: heartbeat `OK`, backlog `OK`.
5. Publish a test scheduled article 2 minutes ahead and confirm it publishes.
6. Confirm rate-limit keys show real visitor IPs (section 6).
7. `php artisan config:cache` again after any `.env` change.

Frontend: point the Next.js app's API base URL (`NEXT_PUBLIC_API_BASE_URL`, e.g. `https://api.example.com/api`) at the API; the API must list the frontend origin in `CORS_ALLOWED_ORIGINS`/`FRONTEND_URL`
(exact origin, scheme included, no trailing slash, comma separated). CORS is bearer-token only (no credentials).

Webhook URLs to register with the providers:
- Razorpay (Dashboard, Webhooks; events `payment.captured`, `order.paid`, `payment.failed`): `POST https://api.example.com/api/subscriptions/webhook`, secret = `RAZORPAY_WEBHOOK_SECRET`.
- Copyleaks: nothing to register by hand; each scan sends its own status webhook URL built as `COPYLEAKS_WEBHOOK_BASE_URL/api/ai/plagiarism-webhook/<COPYLEAKS_WEBHOOK_SECRET>/<scanId>/{STATUS}`. The base URL must be the public HTTPS API address, and the secret must be a long random string.
- WATI: outbound only (the app sends template messages); there is no inbound webhook to configure.
- OpenAI and Bunny.net: outbound only.

## 14. Diagnostics: `php artisan portal:doctor`

Read-only, prints no secrets (integrations are only "set/unset"), exits 1 on blocking problems (`--strict` also fails on warnings, `--json` for machines). It checks: PHP version and extensions (pdo_mysql, mbstring, gd+WebP, exif, openssl, json, fileinfo, curl, redis optional), `proc_open`,
upload/memory ini, MySQL/MariaDB version, FULLTEXT index, `innodb_ft_min_token_size`, `sql_mode`, collation, time zone agreement, `max_allowed_packet`, migrations state, a real round-trip on the cache store (add/get/increment/forget), queue tables and backlog age, failed jobs,
the analytics driver, Redis reachability only when a Redis driver is selected, the scheduler heartbeat (written every minute by the scheduler; older than 5 minutes = FAIL), writable `storage/` and `bootstrap/cache`, `APP_KEY`/`APP_ENV`/`APP_DEBUG`/URLs, and which integrations are configured.

## 15. Logs, backups, maintenance

- Logs: `LOG_STACK=daily` writes `storage/logs/laravel-YYYY-MM-DD.log` and keeps `LOG_DAILY_DAYS` (default 14) files, so rotation is automatic. Keep `LOG_LEVEL=warning`; check the newest file after each deploy. Rate-limit/analytics backend problems are logged as warnings and are fail-open.
- Backups: use hPanel's backup feature if your plan has it **[unverified: frequency/retention]**, and additionally a daily dump you control, for example the cron line
  `30 2 * * * mysqldump --single-transaction --no-tablespaces -h localhost -u DBUSER -p'DBPASS' DBNAME | gzip > $HOME/backups/truth-$(date +\%F).sql.gz && find $HOME/backups -name 'truth-*.sql.gz' -mtime +14 -delete`
  (`--no-tablespaces` avoids a PROCESS-privilege error on shared hosts). Copy dumps off the server. Keep a copy of `.env` (contains `APP_KEY`; without it issued tokens and encrypted values cannot be recovered) somewhere private. Images are on Bunny.net and are not part of a server backup.
- Deploying an update: upload, `composer install --no-dev -o`, `php artisan migrate --force`, `php artisan config:cache && php artisan route:cache && php artisan event:cache`, `php artisan portal:doctor`. `php artisan down` / `up` use the file maintenance driver.

## 16. Limitations (read before go-live)

- Publishing, notifications, reindexing and other background work are delayed by up to ~1-2 minutes; expiry/reminders by up to an hour. There is no real-time worker on shared hosting.
- If the cron stops (plan limits, hosting migration, wrong PHP path) nothing scheduled runs and the queue is not drained; `portal:doctor` (heartbeat, backlog) and an external uptime check on `/api/health` are your early warning. Add an alert if you can.
- Every request pays a few extra database statements for rate limiting; on a busy site consider upgrading to a VPS with Redis (PROFILE B needs only `.env` changes).
- FULLTEXT search ignores words shorter than `innodb_ft_min_token_size` and has MySQL/MariaDB relevance semantics rather than PostgreSQL ranking.
- Text comparison is case- AND accent-insensitive (`utf8mb4_unicode_ci`, also under MySQL 8): tag/category/industry names, e-mail addresses, slugs and search match `cafe` = `Café` = `CAFÉ`. Two quirks of that collation: every emoji weighs the same (two emoji-only tag names collide) and the Devanagari marks anusvara/candrabindu/nukta/visarga are ignored (`कं` = `क`); the API reports such a clash as an ordinary "already exists" 400. Opaque identifiers (Razorpay ids, Copyleaks scan ids, API token hashes) are byte-exact (`utf8mb4_bin`). Trailing spaces never matter (PAD SPACE), inputs are trimmed.
- DATETIME range is years 1000-9999 in the app time zone (the API answers 400 outside it); `max_allowed_packet` should be >= 16 MB for the largest legal article (`portal:doctor` warns); MySQL sorts NULL first ascending, the API keeps the old PostgreSQL order (NULLs last ascending / first descending) for `published_at`.
- `schedule:run` needs `proc_open`; PHP versions below 8.4.1 are unsupported (section 2); the CLI and web PHP configuration may differ (versions and ini values) and must each be checked.
- The Django importer is not designed to run on shared hosting (needs `pdo_pgsql` and network access to PostgreSQL); import elsewhere and load the MySQL dump.
- Every **[unverified]** marker above is a Hostinger platform fact that was not testable from the development environment.
