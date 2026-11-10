<?php

/*
 * HR-13 public job-application form (HR-PLAN 15.2). Off by default until the owner approves the privacy notice; the
 * notice version shown and recorded with each application is a draft until then.
 */
return [
    'enabled' => filter_var(env('HR_APPLICATIONS_ENABLED', false), FILTER_VALIDATE_BOOL),
    'privacy_notice_version' => (string) (env('HR_APPLICATIONS_PRIVACY_NOTICE_VERSION') ?: 'draft-hr17-applications-v1'),
    // A form sent sooner than this after it was opened is treated as a robot and not stored.
    'minimum_fill_seconds' => 5,
    // A form older than this asks the applicant to reload it.
    'form_ttl_minutes' => 60,
];
