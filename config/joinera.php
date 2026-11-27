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
            // 143 + STARTTLS: implicit SSL on 993 is commonly blocked on Windows/VPN setups.
            'port' => (int) env('JOINERA_GMX_IMAP_PORT', 143),
            'encryption' => env('JOINERA_GMX_IMAP_ENCRYPTION', 'starttls'),
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
                'http_timeout' => max(5, (int) env('JOINERA_AUSBILDUNG_DE_HTTP_TIMEOUT', 30)),
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
        'apply' => env('JOINERA_QUEUE_APPLY', 'apply'),
    ],

    'match' => [
        // When true, RematchCandidateAgainstListings SQL-prefilters DE-compatible +
        // industry-compatible listings (Proposed set preserved; Skipped for excluded
        // hard-fail listings may not refresh). Default off for full-scan parity.
        // Prod enable: human-approve listings.country index if EXPLAIN requires it.
        'rematch_listing_prefilter' => (bool) env('JOINERA_REMATCH_LISTING_PREFILTER', false),
    ],

    'apply' => [
        // Q17: Applications per Candidate per active window (not profile CVs).
        'cap' => max(1, (int) env('JOINERA_APPLY_CAP', 10)),
        'cap_window' => env('JOINERA_APPLY_CAP_WINDOW', 'daily'), // daily|weekly
    ],

    // Plan 32 / P0-14 — human-like anti-block layers (null = inactive / configured=false).
    'anti_block' => [
        'jitter_min_seconds' => env('JOINERA_ANTI_BLOCK_JITTER_MIN') !== null && env('JOINERA_ANTI_BLOCK_JITTER_MIN') !== ''
            ? max(0, (int) env('JOINERA_ANTI_BLOCK_JITTER_MIN'))
            : null,
        'jitter_max_seconds' => env('JOINERA_ANTI_BLOCK_JITTER_MAX') !== null && env('JOINERA_ANTI_BLOCK_JITTER_MAX') !== ''
            ? max(0, (int) env('JOINERA_ANTI_BLOCK_JITTER_MAX'))
            : null,
        'max_per_hour_user_site' => env('JOINERA_ANTI_BLOCK_MAX_PER_HOUR') !== null && env('JOINERA_ANTI_BLOCK_MAX_PER_HOUR') !== ''
            ? max(1, (int) env('JOINERA_ANTI_BLOCK_MAX_PER_HOUR'))
            : null,
        'cooldown_seconds_after_block' => env('JOINERA_ANTI_BLOCK_COOLDOWN_SECONDS') !== null && env('JOINERA_ANTI_BLOCK_COOLDOWN_SECONDS') !== ''
            ? max(0, (int) env('JOINERA_ANTI_BLOCK_COOLDOWN_SECONDS'))
            : null,
    ],

    // Mode B browser worker (Playwright Node). Tests always bind FixtureModeBBrowserAdapter.
    'mode_b' => [
        // fixture | http — http calls the Playwright worker; fixture is local stub.
        'driver' => env('JOINERA_MODE_B_DRIVER', 'fixture'),
        'worker_url' => rtrim((string) env('JOINERA_MODE_B_WORKER_URL', 'http://127.0.0.1:3911'), '/'),
        'worker_secret' => (string) env('JOINERA_MODE_B_WORKER_SECRET', ''),
        'timeout' => max(5, (int) env('JOINERA_MODE_B_TIMEOUT', 120)),
        // Optional until profiles.phone exists — Softgarden often requires Telefon.
        'fallback_phone' => (string) env('JOINERA_MODE_B_FALLBACK_PHONE', ''),
        // Legacy board Easy-Apply claim/path (Azubiyo/BA love-path probes). Default off — company site only.
        'allow_board_apply' => (bool) env('JOINERA_MODE_B_ALLOW_BOARD_APPLY', false),
        // AI Step Navigator — worker POSTs scrubbed control snapshots; default off.
        'ai_step_nav' => [
            'enabled' => filter_var(env('JOINERA_MODE_B_AI_STEP_NAV', true), FILTER_VALIDATE_BOOLEAN),
            'min_confidence' => max(0.0, min(1.0, (float) env('JOINERA_MODE_B_AI_STEP_NAV_MIN_CONFIDENCE', 0.6))),
            'timeout' => max(3, (int) env('JOINERA_MODE_B_AI_STEP_NAV_TIMEOUT', 15)),
            'max_tokens' => max(64, (int) env('JOINERA_MODE_B_AI_STEP_NAV_MAX_TOKENS', 200)),
            // P1 cache TTL (seconds); unused in P0 but reserved.
            'cache_ttl_seconds' => max(0, (int) env('JOINERA_MODE_B_AI_STEP_NAV_CACHE_TTL', 604800)),
        ],
    ],
    // P0-13 preparing_docs: cover (+ CV when category differs) via LlmClient + PdfRenderer.
    'docs' => [
        'disk' => env('JOINERA_DOCS_DISK', 'local'),
        'regenerate_max_per_hour' => max(1, (int) env('JOINERA_DOCS_REGENERATE_MAX_PER_HOUR', 3)),
        'listing_description_max_chars' => max(200, (int) env('JOINERA_DOCS_LISTING_DESCRIPTION_MAX_CHARS', 2000)),
    ],
    // Staff document ingestion (CV / passport / certs). Matching engine untouched.
    'documents' => [
        // ingestion | fixture — testing env always uses FixtureCvParser.
        'cv_parser' => env('JOINERA_CV_PARSER', 'ingestion'),
        // tesseract | null — testing env always uses NullDocumentOcrClient.
        'ocr' => env('JOINERA_DOCUMENT_OCR', 'tesseract'),
        // Residual text-only LLM gap-fill after heuristics/OCR (experiences, skills, name, DOB, …).
        // Default false to minimize tokens. Enable on staging/VPS after OPENROUTER_API_KEY (or
        // JOINERA_AI_DRIVER) is configured — heuristics alone cover clear Lebenslauf text, residual
        // fills OCR/layout misses. Env: JOINERA_DOCUMENT_RESIDUAL_LLM=true
        'residual_llm' => (bool) env('JOINERA_DOCUMENT_RESIDUAL_LLM', false),
    ],

    // LLM port for preparing_docs / cover+CV / staff document analyze.
    'ai' => [
        // openrouter | gemini | fake — testing env always uses Fake in AppServiceProvider.
        'driver' => env('JOINERA_AI_DRIVER', 'openrouter'),
        // PHPUnit only: set true in a test to exercise live key/credit probes (Http::fake).
        'force_readiness_check' => false,
        'gemini' => [
            'api_key' => env('GEMINI_API_KEY'),
            'base_url' => rtrim((string) env(
                'GEMINI_BASE_URL',
                'https://generativelanguage.googleapis.com/v1beta',
            ), '/'),
            // Prefer lite on free tier (higher RPM/RPD). Gemini 3 Flash free is ~5 RPM / 20 RPD.
            'model' => env('GEMINI_MODEL', 'gemini-3.1-flash-lite-preview'),
            'fallback_models' => array_values(array_filter(array_map(
                static fn (string $model): string => trim($model),
                explode(',', (string) env(
                    'GEMINI_FALLBACK_MODELS',
                    'gemini-3.1-flash-lite-preview,gemini-3-flash-preview,gemini-flash-latest',
                )),
            ))),
            'timeout' => max(5, (int) env('GEMINI_TIMEOUT', 180)),
            'connect_timeout' => max(5, (int) env('GEMINI_CONNECT_TIMEOUT', 45)),
            'retries' => max(1, (int) env('GEMINI_RETRIES', 2)),
        ],
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
            // Batch PDF analyze often needs >60s (OpenRouter file-parser + model).
            'timeout' => max(5, (int) env('OPENROUTER_TIMEOUT', 180)),
            // SSL handshake to openrouter.ai can be slow on some Windows/VPN networks.
            'connect_timeout' => max(5, (int) env('OPENROUTER_CONNECT_TIMEOUT', 45)),
            'retries' => max(1, (int) env('OPENROUTER_RETRIES', 3)),
            // mistral-ocr | cloudflare-ai | native — used by staff analyze-documents PDF plugin.
            'pdf_engine' => env('OPENROUTER_PDF_ENGINE', 'mistral-ocr'),
        ],
    ],
];
