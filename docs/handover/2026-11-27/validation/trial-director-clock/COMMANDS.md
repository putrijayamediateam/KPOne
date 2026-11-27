# Reproduction and validation commands

Working directory: /workspace/KPOne-trial-director-clock, base main d0f37b5c.
Explicit synthetic-only environment: PATH=/workspace/kpone-tools/bin:$PATH; APP_ENV=testing; APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=; DB_CONNECTION=pgsql; DB_HOST=127.0.0.1; DB_PORT=55439; DB_SSLMODE=disable; DB_DATABASE=kpone_trial_director_clock_test; DB_USERNAME=agent; DB_PASSWORD empty; CACHE_STORE=array; SESSION_DRIVER=array; QUEUE_CONNECTION=sync.

Before source fix:
`php artisan test /workspace/kpone-validation/trial-director-clock/PhaseOneClockDiagnosticTest.php --filter=test_capture_phase_one_previous_branch_day_refusal`
Result1/2 PASS proves observed command refusal; external diagnostic captures actual output/dates in refusal.json. Do not rerun this old expected-refusal diagnostic after fixture correction as if it were a current passing regression.

After fixture fix:
`php artisan test tests/Feature/TrialDataCommandTest.php --filter='test_phase_one_accepts_fixture_coverage_on_the_previous_branch_day|test_phase_one_still_refuses_coverage_that_starts_after_the_frozen_day|test_phase_five_fills_kpone_hr_once_with_fictional_payroll_tables'`
Result3/48 PASS.

`php -l tests/Feature/TrialDataCommandTest.php`
`vendor/bin/pint --test tests/Feature/TrialDataCommandTest.php`
Both PASS.

`php artisan test tests/Feature/TrialDataCommandTest.php`
Full class running at packet checkpoint; full-class.log will contain final count including four midnight provider cases.
