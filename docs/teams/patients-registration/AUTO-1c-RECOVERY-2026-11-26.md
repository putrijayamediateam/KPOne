# AUTO-1c recovery and temporal correction

Tree `/workspace/KPOne-auto1c`, branch `recovery/auto1c-doctor-warning`, current-main base23639679e. Exact source recovered from `feature/auto-1c-doctor-warning` head6fd30499b, design from `docs/auto-1c-design` head98fc448d. Original14-file binary patch preserved `/workspace/kpone-review-bundles/AUTO-1c-recovered.patch` before correction. No commits/push/merge.

## Existing approvals

Recovered design§7 records all6 recommendations approved24Nov and confirmed25Nov “Setuju”; handoff26Nov grants ship-it after independent design/reviewer OK. No new permission or migration. Existing `QueuePerformanceTest` ceiling21→22 is recovered unchanged from6fd30499b, whose commit and inline comment expressly record owner approval25Nov. No other historical assertion changed.

## Found and corrected

Original warning used today's leave-filtered roster to determine off duty. That missed a shift ending later the same day, and full-day leave disappeared from that roster and was labelled off roster. Independent staff/design review confirmed approvedQ1 requires availability **now** and no later decision supersedes it.

Added HR read-only batched `RosterOnDuty::availabilityForWarnings`: approved active/non-on-call shifts before leave filtering, scheduled-today/current-shift booleans, and approved leave covering exact now via existing LeaveCalendar UTC-slot handling. No leave reason/balance/name reaches Queue. Projection gives leave precedence, warns before/after a current shift, retains four existing warning codes and existing absent-day label, and uses “Off duty now” when the doctor is scheduled today but currently off duty. Existing other-free filtering remains on-duty-now, and no visit assignment changes automatically.

## New evidence

NEW DoctorWarningTemporalTest covers exact shift start included/end excluded, before/after shift, full-shift leave classification, partial-leave exact boundary and return, overnight continuation/end, and unchanged assignment. Existing frontend4 tests pass; Pint/diff checks pass. PostgreSQL focused DoctorWarningTest + new temporal tests + QueuePerformanceTest + RosterOnDutyTest PASS24 tests/268 assertions on isolated kpone_auto1c_test after coordinator's ICU restart (25.4s); log /tmp/kpone-auto1c-tests.log. Full release gate not claimed.
