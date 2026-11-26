# AUTO-1c recovery and temporal correction

Tree `/workspace/KPOne-auto1c`, branch `recovery/auto1c-doctor-warning`, current-main base23639679e. Exact source recovered from `feature/auto-1c-doctor-warning` head6fd30499b, design from `docs/auto-1c-design` head98fc448d. Original14-file binary patch preserved `/workspace/kpone-review-bundles/AUTO-1c-recovered.patch` before correction. No commits/push/merge.

## Existing approvals

Recovered design§7 records all6 recommendations approved24Nov and confirmed25Nov “Setuju”; handoff26Nov grants ship-it after independent design/reviewer OK. No new permission or migration. Existing `QueuePerformanceTest` ceiling21→22 is recovered unchanged from6fd30499b, whose commit and inline comment expressly record owner approval25Nov. No other historical assertion changed.

## Found and corrected

Original warning used today's leave-filtered roster to determine off duty. That missed a shift ending later the same day, and full-day leave disappeared from that roster and was labelled off roster. Independent staff/design review confirmed approvedQ1 requires availability **now** and no later decision supersedes it.

Added HR read-only batched `RosterOnDuty::availabilityForWarnings`: approved active/non-on-call shifts before leave filtering, scheduled-today/current-shift booleans, and approved leave covering exact now via existing LeaveCalendar UTC-slot handling. No leave reason/balance/name reaches Queue. Projection gives leave precedence, warns before/after a current shift, retains four existing warning codes and existing absent-day label, and uses “Off duty now” when the doctor is scheduled today but currently off duty. Existing other-free filtering remains on-duty-now, and no visit assignment changes automatically.

## New evidence

NEW DoctorWarningTemporalTest covers exact shift start included/end excluded, before/after shift, full-shift leave classification, partial-leave exact boundary and return, overnight continuation/end, and unchanged assignment. Existing frontend4 tests pass; Pint/diff checks pass. PostgreSQL focused DoctorWarningTest + new temporal tests + QueuePerformanceTest + RosterOnDutyTest PASS24 tests/268 assertions on isolated kpone_auto1c_test after coordinator's ICU restart (25.4s); log /tmp/kpone-auto1c-tests.log. Full release gate not claimed.

## Scoped release extraction after Q16d

Recovered into `/workspace/KPOne-auto1c-release` from actual main83c5999, then normally merged the independently published Q16d #1161 batch-room dependency. Archive #1142 is not an ancestor. The release diff against Q16d comprises the original18 AUTO1c files, with QueueDirectory shared hunks reconciled against merged Q16c and the reviewed complete-array PHPStan follow-up included. Existing assertion replacement remains only the historical owner-approved GET budget21→22; no additional ceiling or assertion changes.

A source review found the recovered `another_free` check filtered a recommendation list by free/current duty without requiring room, although approved design Q2 (design lines38/71/94) explicitly requires an alternative with a room. The warning-only correction now requires that room, keeps the same day-eligible/current-duty intersection and waiting/busy membership, and batches room and busy reads. Existing recommendation/pick APIs and ranking rules are unchanged. No alternative identity or sales value is returned.

New regressions prove a free no-room alternative does not warn until a manual room is selected, and populated one-versus26 distinct-doctor warnings use equal query counts. Existing leave/shift/overnight/role/branch/read-only tests remain unchanged. Focused PostgreSQL15/172, allfrontend2214/2214, scoped PHPStan0/lint/format and fulltypes/build passed. Independent follow-up review is recorded in `/workspace/kpone-validation/queue-release-path/AUTO1C-BATCH-INDEPENDENT-REVIEW.md`; final browser and immutable manifest follow in that folder. No migration, permission, clinical-state mutation or production action is introduced.

Final synthetic browser proof:20 checks/16 captures cover Queue and Registration × own/branch ×390/1440×light/dark. Authorized supervisor sees neutral off-roster chips and the existing Edit visit link; resident doctor receives neither warning nor Reassign action. No overflow/JS errors. Registration explicitly filtered26–27 Nov to include the unchanged fixture visits after MYT midnight; no dates were fabricated in the application. All visit assignments/status/versions and queue status/versions equal the separate untouched restored synthetic preview (`auto1c-readonly-proof.json`).

Final visual follow-up: measured Reassign links on both boards were16px high on desktop and44px below1024. Added only `min-h-9` to the existing link; after production rebuild actual DOM is36px at1440 and44px at768/390, with unchanged href/authority. Measurements are in `auto1c-hitboxes-before.json` and `auto1c-hitboxes-after.json`; no global component rewrite.
