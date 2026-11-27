# AUTO1c independent design/source review — correction required

Authority checked: recovered AUTO-1c-DESIGN.md §7 records all six recommended options adopted and owner confirmation25Nov. Q1 in §6 says approved roster "now"; §3 explicitly includes shift ended. Coordinator confirms no later user Q1 supersedes it. Current-shift correction therefore implements accepted scope, not a new business decision.

Recovered DoctorWarningProjection uses onDutyOn(day) for off_roster. A doctor whose08:00–17:00 shift has ended remains in that day set, absent from onDutyNow, and falls through to no warning. Approved full-day leave removes the shift from onDutyOn, so label becomes off_roster before on_leave. These are correctness defects; assigned waiting patients should retain assignment but receive the relevant current-fact warning.

Proposed correction sent to queue owner: batched current approved shift and leave-now classification, leave precedence, explicit start-inclusive/end-exclusive semantics. Avoid inferring leave from day versus now sets. New boundary tests only: exact start/end, after shift, approved full/partial leave and return, overnight coverage; preserve existing assertions except historicallyapproved performance21→22. Source owned by queue; no reviewer edits.

Presentation review: warning remains label+icon and neutral Reassign link, no names/figures of alternative doctors. Server projection only exposed where update action authorised; waiting consultation only.44px mobile Reassign target present. Reassign currently opens existing Edit visit route rather than directly focusing doctor field; small usability difference from design wording, not a temporal-correctness blocker. Fix re-review pending queue implementation.

## Fix re-review — resolved

Independently reviewed final availabilityForWarnings HR abstraction, DoctorWarningProjection and new DoctorWarningTemporalTest. Approved shifts are read before leave filtering; scheduledNow uses starts<=now and ends>now, overnight shifts overlap local day. LeaveCalendar overlapping performs exact UTC-slot intersection, so full-day/partial leave classify correctly at start and clear at end. Projection prioritises on_leave, warns off_roster outside current shift, retains original label for absent-day roster and uses Off duty now for ended/not-started shifts. Alternative-doctor availability still checks onDutyNow. No extra identity/leave reason leaves HR.

Four new temporal tests cover exact shift start/end/after, full-shiftleave, partialleave/return and overnight end; assignment persistence checked. Original assertions untouched except recovered historicallyapproved21→22 upper bound. Owner reports focused PostgreSQL24tests/268assertions pass, frontend4 pass, PHP lint/Pint/diff pass. No remaining blocker identified in this scoped correction. No new business decision needed; acceptedQ1 implemented. Reviewer did not duplicate heavyweight tests. Complete redesign/fullAUTO UAT not inferred.
