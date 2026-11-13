<?php

return [
    'mailbox' => [
        // gmx_manual = Admin attaches GMX email+password after Candidate create.
        'mode' => env('JOINERA_MAILBOX_MODE', 'gmx_manual'),
        // gmx | fake — fake used in Feature tests via AppServiceProvider override.
        'driver' => env('JOINERA_MAILBOX_DRIVER', 'gmx'),
        'allowed_hosts' => array_values(array_filter(array_map(
            static fn (string $host): string => strtolower(trim($host)),
            explode(',', (string) env('JOINERA_GMX_ALLOWED_HOSTS', 'gmx.de,gmx.net')),
        ))),
        'imap' => [
            'host' => env('JOINERA_GMX_IMAP_HOST', 'imap.gmx.net'),
            'port' => (int) env('JOINERA_GMX_IMAP_PORT', 993),
            'encryption' => env('JOINERA_GMX_IMAP_ENCRYPTION', 'ssl'),
        ],
        'smtp' => [
            'host' => env('JOINERA_GMX_SMTP_HOST', 'mail.gmx.net'),
            'port' => (int) env('JOINERA_GMX_SMTP_PORT', 587),
            'encryption' => env('JOINERA_GMX_SMTP_ENCRYPTION', 'tls'),
        ],
        // Legacy keys kept empty so old env files do not silently re-enable Mailgun.
        'provider' => env('JOINERA_MAILBOX_PROVIDER'),
        'registrar' => env('JOINERA_DOMAIN_REGISTRAR'),
    ],
    'sites' => [
        // Add a board (N-site; no runner change):
        // (a) Choose exact source key string (must match Listing.source / sites.v1 entry).
        // (b) Implement SiteIntakeAdapter + Fake HTTP + fixtures under tests/Fixtures/intake/{key}/.
        // (c) registry->register(key) in AppServiceProvider.
        // (d) Append key to JOINERA_SITES_V1 / sites.v1 default.
        // Q12 v1 boards: ausbildung.de, azubiyo, arbeitsagentur.de (Bundesagentur Jobbörse + Ausbildungssuche).
        // Comma-separated env; Q12 boards enabled by default. Tests override via config().
        'v1' => array_values(array_filter(array_map(
            static fn (string $site): string => trim($site),
            explode(',', (string) env('JOINERA_SITES_V1', 'ausbildung.de,azubiyo,arbeitsagentur.de')),
        ))),
        'allow_non_de' => (bool) env('JOINERA_SITES_ALLOW_NON_DE', false),
        'adapters' => [
            'ausbildung_de' => [
                'search_url' => env('JOINERA_AUSBILDUNG_DE_SEARCH_URL', 'https://www.ausbildung.de/suche/'),
                'base_url' => env('JOINERA_AUSBILDUNG_DE_BASE_URL', 'https://www.ausbildung.de'),
                'max_pages' => max(1, (int) env('JOINERA_AUSBILDUNG_DE_MAX_PAGES', 5)),
                // live | fake — testing env always uses Fake in AppServiceProvider.
                'http' => env('JOINERA_AUSBILDUNG_DE_HTTP', 'live'),
            ],
            'azubiyo' => [
                'search_url' => env('JOINERA_AZUBIYO_SEARCH_URL', 'https://www.azubiyo.de/stellenmarkt/'),
                'base_url' => env('JOINERA_AZUBIYO_BASE_URL', 'https://www.azubiyo.de'),
                'max_pages' => max(1, (int) env('JOINERA_AZUBIYO_MAX_PAGES', 5)),
                // live | fake — testing env always uses Fake in AppServiceProvider.
                'http' => env('JOINERA_AZUBIYO_HTTP', 'live'),
            ],
            'arbeitsagentur_de' => [
                'jobs_base_url' => env(
                    'JOINERA_ARBEITSAGENTUR_DE_JOBS_BASE_URL',
                    'https://rest.arbeitsagentur.de/jobboerse/jobsuche-service',
                ),
                'ausbildung_base_url' => env(
                    'JOINERA_ARBEITSAGENTUR_DE_AUSBILDUNG_BASE_URL',
                    'https://rest.arbeitsagentur.de/infosysbub/absuche',
                ),
                'portal_base_url' => env(
                    'JOINERA_ARBEITSAGENTUR_DE_PORTAL_BASE_URL',
                    'https://www.arbeitsagentur.de',
                ),
                'max_pages' => max(1, (int) env('JOINERA_ARBEITSAGENTUR_DE_MAX_PAGES', 5)),
                // live | fake — testing env always uses Fake in AppServiceProvider.
                'http' => env('JOINERA_ARBEITSAGENTUR_DE_HTTP', 'live'),
                // Public clientIds (X-API-Key); not Candidate credentials.
                'jobs_api_key' => env('JOINERA_ARBEITSAGENTUR_DE_JOBS_API_KEY', 'jobboerse-jobsuche'),
                'ausbildung_api_key' => env('JOINERA_ARBEITSAGENTUR_DE_AUSBILDUNG_API_KEY', 'infosysbub-absuche'),
            ],
        ],
    ],
    'queues' => [
        'intake' => 'intake',
        'alerts' => 'alerts',
        'match' => env('JOINERA_QUEUE_MATCH', 'match'),
        'docs' => env('JOINERA_QUEUE_DOCS', 'docs'),
    ],
    'apply' => [
        // Q17: Applications per Candidate per active window (not profile CVs).
        'cap' => max(1, (int) env('JOINERA_APPLY_CAP', 10)),
        'cap_window' => env('JOINERA_APPLY_CAP_WINDOW', 'daily'), // daily|weekly
    ],
    // P0-13 preparing_docs: cover (+ CV when category differs) via LlmClient + PdfRenderer.
    'docs' => [
        'disk' => env('JOINERA_DOCS_DISK', 'local'),
        'regenerate_max_per_hour' => max(1, (int) env('JOINERA_DOCS_REGENERATE_MAX_PER_HOUR', 3)),
        'listing_description_max_chars' => max(200, (int) env('JOINERA_DOCS_LISTING_DESCRIPTION_MAX_CHARS', 2000)),
    ],
    // LLM port for preparing_docs / cover+CV. Swap driver without rewriting jobs.
    'ai' => [
        // openrouter | fake — testing env always uses Fake in AppServiceProvider.
        'driver' => env('JOINERA_AI_DRIVER', 'openrouter'),
        'openrouter' => [
            'api_key' => env('OPENROUTER_API_KEY'),
            'base_url' => rtrim((string) env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'), '/'),
            // Primary model. For free-only experiments use openrouter/free or a *:free id.
            'model' => env('OPENROUTER_MODEL', 'openrouter/free'),
            // Comma-separated extra fallbacks tried after primary (rate-limit / downtime).
            // Keep only :free / openrouter/free entries to avoid paid surprise bills.
            'fallback_models' => array_values(array_filter(array_map(
                static fn (string $model): string => trim($model),
                explode(',', (string) env('OPENROUTER_FALLBACK_MODELS', '')),
            ))),
            'http_referer' => env('OPENROUTER_HTTP_REFERER'),
            'app_title' => env('OPENROUTER_APP_TITLE', 'Joinera'),
            'timeout' => max(5, (int) env('OPENROUTER_TIMEOUT', 60)),
        ],
    ],
];
