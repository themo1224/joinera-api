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
        // Empty until Q12; tests set fixture ids via config().
        'v1' => [],
        'allow_non_de' => (bool) env('JOINERA_SITES_ALLOW_NON_DE', false),
    ],
    'queues' => [
        'intake' => 'intake',
        'alerts' => 'alerts',
    ],
];
