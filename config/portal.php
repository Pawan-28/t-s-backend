<?php

/*
| Portal-specific settings (Truth & Social). Everything secret comes from .env.
*/

$rate = fn (string $scope, string $default) => env('THROTTLE_'.strtoupper($scope).'_RATE', env('APP_ENV') === 'testing' ? '100000/minute' : $default);

return [

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),

    // ---- DRF-compatible pagination -------------------------------------
    'page_size' => 20,
    'search_page_size_max' => 50,

    // MySQL/MariaDB FULLTEXT: InnoDB ignores words shorter than innodb_ft_min_token_size (3 by default;
    // not changeable on shared hosting). Keep in sync if the server value differs.
    'search' => ['min_token_size' => (int) env('SEARCH_MIN_TOKEN_SIZE', 3)],

    // ---- Auth tokens ----------------------------------------------------
    'auth' => [
        'access_minutes' => (int) env('AUTH_ACCESS_TOKEN_MINUTES', 15),
        'refresh_days' => (int) env('AUTH_REFRESH_TOKEN_DAYS', 7),
    ],

    // ---- Rate limiting (scope => "N/period"), same numbers as Django ----
    // period: second|minute|hour|day. Keys: ip | identity | account | user
    'throttle' => [
        'anon' => ['rate' => $rate('anon', '100/minute'), 'key' => 'ip'],
        'user' => ['rate' => $rate('user', '1000/minute'), 'key' => 'user'],
        'article_view' => ['rate' => $rate('article_view', '60/minute'), 'key' => 'ip'],
        'auth_login_ip' => ['rate' => $rate('auth_login_ip', '20/minute'), 'key' => 'ip'],
        'auth_login_identity' => ['rate' => $rate('auth_login_identity', '10/hour'), 'key' => 'identity'],
        'auth_login_account' => ['rate' => $rate('auth_login_account', '30/hour'), 'key' => 'account'],
        'auth_register' => ['rate' => $rate('auth_register', '10/hour'), 'key' => 'ip'],
        'otp_request_burst' => ['rate' => $rate('otp_request_burst', '2/minute'), 'key' => 'user'],
        'otp_request' => ['rate' => $rate('otp_request', '5/hour'), 'key' => 'user'],
        'otp_verify_burst' => ['rate' => $rate('otp_verify_burst', '5/minute'), 'key' => 'user'],
        'otp_verify' => ['rate' => $rate('otp_verify', '20/hour'), 'key' => 'user'],
        'checkout' => ['rate' => $rate('checkout', '10/minute'), 'key' => 'user'],
        'verify_payment' => ['rate' => $rate('verify_payment', '10/minute'), 'key' => 'user'],
        'upload' => ['rate' => $rate('upload', '30/minute'), 'key' => 'user'],
    ],

    // ---- Images ---------------------------------------------------------
    'images' => [
        'max_upload_mb' => (int) env('MAX_IMAGE_UPLOAD_SIZE_MB', 5),
        'max_dimension_px' => (int) env('IMAGE_MAX_DIMENSION_PX', 2000),
        'min_dimension_px' => 32,
        'jpeg_quality' => 85,
    ],

    // ---- Bunny.net Storage ---------------------------------------------
    'bunny' => [
        'storage_zone' => env('BUNNY_STORAGE_ZONE'),
        'api_key' => env('BUNNY_STORAGE_API_KEY'),
        'region' => env('BUNNY_STORAGE_REGION', ''),
        'pull_zone_url' => env('BUNNY_PULL_ZONE_URL'),
        'timeout' => 30,
        // Optional override of the storage API origin (integration/sandbox use only), e.g. http://127.0.0.1:9101
        'api_base' => env('BUNNY_STORAGE_API_BASE'),
    ],

    // ---- Razorpay (one-time payments only) -----------------------------
    'razorpay' => [
        'key_id' => env('RAZORPAY_KEY_ID'),
        'key_secret' => env('RAZORPAY_KEY_SECRET'),
        'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
        'api_base' => env('RAZORPAY_API_BASE', 'https://api.razorpay.com/v1'),
    ],

    // ---- WATI (WhatsApp sender only) -----------------------------------
    'wati' => [
        'endpoint' => env('WATI_API_ENDPOINT'),
        'token' => env('WATI_ACCESS_TOKEN'),
        'templates' => [
            'otp' => env('WATI_OTP_TEMPLATE_NAME'),
            'subscription_activated' => env('WATI_SUBSCRIPTION_ACTIVATED_TEMPLATE_NAME'),
            'payment_failed' => env('WATI_PAYMENT_FAILED_TEMPLATE_NAME'),
            'subscription_expiring' => env('WATI_SUBSCRIPTION_EXPIRING_TEMPLATE_NAME'),
            'subscription_expired' => env('WATI_SUBSCRIPTION_EXPIRED_TEMPLATE_NAME'),
            'article_published' => env('WATI_ARTICLE_PUBLISHED_TEMPLATE_NAME'),
        ],
        'timeout' => 30,
    ],

    // ---- OTP -------------------------------------------------------------
    'otp' => [
        'ttl_minutes' => 10,
        'max_attempts' => 5,
        // Cap on code requests per destination number (any account), per hour; over the cap the API still answers 200 but sends nothing.
        'max_requests_per_phone_hour' => 5,
    ],

    // ---- Subscriptions -----------------------------------------------------
    'subscriptions' => [
        'expiry_reminder_days' => 3,
    ],

    // ---- AI analysis provider ------------------------------------------------
    // AI_PROVIDER=gemini|openai. When unset: gemini if GEMINI_API_KEY is set, else openai.
    'ai' => [
        'provider' => strtolower(trim((string) env('AI_PROVIDER', ''))),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.5-flash'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'timeout' => 60,
    ],

    // ---- OpenAI (optional alternative provider) --------------------------------
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'timeout' => 60,
        'max_content_chars' => 12000,
    ],

    // ---- Copyleaks -----------------------------------------------------------
    'copyleaks' => [
        'email' => env('COPYLEAKS_EMAIL'),
        'api_key' => env('PLAGIARISM_API_KEY'),
        'webhook_base_url' => env('COPYLEAKS_WEBHOOK_BASE_URL'),
        // Shared secret embedded in the webhook URL path (Copyleaks does not
        // document a signature scheme we can rely on; see report).
        'webhook_secret' => env('COPYLEAKS_WEBHOOK_SECRET'),
        'sandbox' => filter_var(env('COPYLEAKS_SANDBOX_MODE', true), FILTER_VALIDATE_BOOL),
        // Optional overrides (integration/sandbox use only); defaults are the public Copyleaks hosts.
        'login_base' => env('COPYLEAKS_LOGIN_BASE'),
        'api_base' => env('COPYLEAKS_API_BASE'),
        'timeout' => 30,
        'pending_timeout_hours' => 24,
    ],

    // ---- Analytics -------------------------------------------------------------
    // driver: `database` (default; no Redis needed: dedupe via the cache store + atomic upsert straight into
    // article_daily_views, so views are visible immediately and nothing needs flushing) or `redis` (Redis
    // counters on the `analytics` connection, drained into article_daily_views by the FlushArticleViews job).
    'analytics' => [
        'driver' => strtolower((string) env('ANALYTICS_DRIVER', 'database')),
        'view_cooldown_seconds' => (int) env('ANALYTICS_VIEW_COOLDOWN_SECONDS', 30),
        'flush_lock_seconds' => 300,
    ],

    // ---- Queue / scheduler on shared hosting ------------------------------------------
    // true: the scheduler itself drains the queue every minute (`queue:work --stop-when-empty --max-time=50`),
    // so ONE cron entry (`* * * * * php artisan schedule:run`) is enough and no daemon is needed. Leave false
    // when a supervisor-managed `queue:work` daemon runs (VPS). Only meaningful for QUEUE_CONNECTION=database|redis.
    'queue_via_scheduler' => filter_var(env('QUEUE_VIA_SCHEDULER', false), FILTER_VALIDATE_BOOL),
    // Connection the scheduler-driven worker drains (defaults to the default queue connection).
    'queue_via_scheduler_connection' => env('QUEUE_VIA_SCHEDULER_CONNECTION'),

    // ---- Agent5 block: AI / plagiarism check rate limits (per user, per minute) ------
    'ai_checks' => [
        'ai_per_minute' => (int) env('AI_CHECK_PER_MINUTE', env('APP_ENV') === 'testing' ? 100000 : 6),
        'plagiarism_per_minute' => (int) env('PLAGIARISM_CHECK_PER_MINUTE', env('APP_ENV') === 'testing' ? 100000 : 6),
    ],

    // Agent5 block: count ArticleViewed events fired outside the POST /analytics/articles/{slug}/view/ beacon
    // (Django counted only the beacon; enabling this while the frontend beacon still runs double counts).
    'analytics_tracker' => [
        'count_detail_events' => filter_var(env('ANALYTICS_COUNT_DETAIL_EVENTS', false), FILTER_VALIDATE_BOOL),
    ],
];
