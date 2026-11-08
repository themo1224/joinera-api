<?php

return [
    'mailbox' => [
        'mode' => env('JOINERA_MAILBOX_MODE'),
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
