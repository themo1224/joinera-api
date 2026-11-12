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
    ],
];
