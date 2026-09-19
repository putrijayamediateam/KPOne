<?php

return [
    'enabled' => filter_var(
        env(
            'PUBLIC_PATIENT_INTAKE_ENABLED',
            in_array((string) env('APP_ENV', 'production'), ['local', 'testing'], true),
        ),
        FILTER_VALIDATE_BOOL,
    ),
    'privacy_notice_version' => 'uat-draft-2026-09-19-v1',
    'minor_age' => (int) env('PUBLIC_PATIENT_INTAKE_MINOR_AGE', 18),
    'link_ttl_days' => (int) env('PUBLIC_PATIENT_INTAKE_LINK_TTL_DAYS', 90),
    'submission_session_ttl_minutes' => (int) env('PUBLIC_PATIENT_INTAKE_SESSION_TTL_MINUTES', 15),
    'retention_days' => 30,
    'trusted_proxies' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('PUBLIC_PATIENT_INTAKE_TRUSTED_PROXIES', '')),
    ))),
];
