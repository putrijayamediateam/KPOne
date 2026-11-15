<?php

/*
 * HR-14 staff messages (HR-PLAN 15.3). A channel sends only when it is connected here AND switched on in KPOne with a
 * monthly cap. Email goes through Laravel's own mail settings (MAIL_*), so it works with whatever mail service the
 * clinic sets up; its keys live in the environment's secrets, never in code. SMS and WhatsApp have no provider yet
 * (owner, 2026-11-10), so they are never connected in this version.
 */
return [
    'email' => [
        'connected' => filter_var(env('HR_MESSAGES_EMAIL_ENABLED', false), FILTER_VALIDATE_BOOL),
    ],
    'sms' => ['connected' => false],
    'whatsapp' => ['connected' => false],
];
