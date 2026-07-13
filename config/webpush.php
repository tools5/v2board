<?php

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
];
