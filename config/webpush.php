<?php

/*
|--------------------------------------------------------------------------
| Web Push defaults (env fallback)
|--------------------------------------------------------------------------
|
| Admin panel settings are saved into storage/app/webpush-settings.json.
| Runtime prefers those values and falls back to this file / .env when unset.
|
*/

$vapidSubject = (string)env('WEB_PUSH_VAPID_SUBJECT', env('APP_URL', ''));
if (!preg_match('/^(https:\/\/|mailto:)/i', $vapidSubject)) {
    $mailFromAddress = (string)env('MAIL_FROM_ADDRESS', '');
    $vapidSubject = filter_var($mailFromAddress, FILTER_VALIDATE_EMAIL)
        ? 'mailto:' . $mailFromAddress
        : 'mailto:admin@localhost';
}

return [
    'enabled' => (bool)env('WEB_PUSH_ENABLED', false),
    'vapid' => [
        'subject' => $vapidSubject,
        'public_key' => env('WEB_PUSH_PUBLIC_KEY'),
        'private_key' => env('WEB_PUSH_PRIVATE_KEY'),
    ],
    'ttl' => (int)env('WEB_PUSH_TTL', 86400),
    'urgency' => env('WEB_PUSH_URGENCY', 'normal'),
    'batch_size' => (int)env('WEB_PUSH_BATCH_SIZE', 500),
    'request_timeout' => (int)env('WEB_PUSH_REQUEST_TIMEOUT', 30),
    'proxy' => env('WEB_PUSH_PROXY'),
    'ca_bundle' => env('WEB_PUSH_CA_BUNDLE'),
    'allowed_endpoint_hosts' => preg_split(
        '/[\s,]+/',
        (string)env('WEB_PUSH_ALLOWED_ENDPOINT_HOSTS', ''),
        -1,
        PREG_SPLIT_NO_EMPTY
    ),
    'remind' => [
        'expire_enabled' => (bool)env('WEB_PUSH_REMIND_EXPIRE', true),
        'traffic_enabled' => (bool)env('WEB_PUSH_REMIND_TRAFFIC', true),
        'expire_days' => array_values(array_unique(array_map('intval', array_filter(
            explode(',', (string)env('WEB_PUSH_REMIND_EXPIRE_DAYS', '3,1,0')),
            function ($value) {
                return trim((string)$value) !== '';
            }
        )))),
        'traffic_percent' => max(1, min(99, (int)env('WEB_PUSH_REMIND_TRAFFIC_PERCENT', 95))),
        'expire_url' => env('WEB_PUSH_REMIND_EXPIRE_URL', ''),
        'traffic_url' => env('WEB_PUSH_REMIND_TRAFFIC_URL', ''),
    ],
];
