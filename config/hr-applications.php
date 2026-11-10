<?php

/*
 * HR-13 public job-application form (HR-PLAN 15.2). Off by default until the owner approves the privacy notice; the
 * notice version shown and recorded with each application is a draft until then.
 */
return [
    'enabled' => filter_var(env('HR_APPLICATIONS_ENABLED', false), FILTER_VALIDATE_BOOL),
    // HR-17e: the version of the talent-pool tick wording, kept with each tick (Suresh, HR-17e M2).
    'pool_text_version' => (string) (env('HR_APPLICATIONS_POOL_TEXT_VERSION') ?: 'draft-hr17e-pool-v1'),
    'privacy_notice_version' => (string) (env('HR_APPLICATIONS_PRIVACY_NOTICE_VERSION') ?: 'draft-hr17-applications-v1'),
    // A form sent sooner than this after it was opened is treated as a robot and not stored.
    'minimum_fill_seconds' => 5,
    // A form older than this asks the applicant to reload it.
    'form_ttl_minutes' => 60,
    // HR-17b: hiring email through Laravel's default mailer (the same MAIL_* settings as staff messages). Off until the
    // owner approves the notice; while off, every message is shown in KPOne only. The provider key lives only in the
    // environment's secrets.
    'email_enabled' => filter_var(env('HR_HIRING_EMAIL_ENABLED', false), FILTER_VALIDATE_BOOL),
    // The clinic's hiring mailbox for the auto-reject reminder (no candidate name is sent there).
    'hiring_mailbox' => env('HR_HIRING_MAILBOX') ?: null,
    // Working days an application may wait at New or Phone screening before the daily check closes it (owner: 3).
    'auto_reject_working_days' => 3,
    // The candidate's private link cookie, kept for this many days (the link itself lapses with the purge).
    'status_cookie' => 'kpone_career_status',
    'status_cookie_days' => 180,
];
