<?php

return [
    'resend' => [
        'key' => env('RESEND_KEY'),
        // The SDK defaults to api.resend.com over HTTPS. Retaining the optional
        // override in configuration lets the production guard reject HTTP or
        // non-canonical endpoints before the mailer is constructed.
        'base_url_override' => env('RESEND_BASE_URL'),
    ],
];
