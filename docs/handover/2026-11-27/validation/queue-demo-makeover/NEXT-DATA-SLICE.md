# Required next Queue data slice

Source inspection after the first real layout candidate:

- `QueueDirectoryService` returns latest25 serving and completed entries. `QueueRow.isHeld` and treatment progress exist, but neither a full held list/count nor a full treatment-return list exists. Filtering those25 locally would silently omit legitimate cases and is rejected.
- `DoctorDispensaryAttentionService` provides `checksWaiting` count and per-loaded-visit flags. `/dispensary/doctor-checks` is restricted to supervisor override authority; redirecting own doctors there would be wrong. The per-encounter doctor check projection is authorized but is not a complete directory.
- Own scope cannot call the branch Rooms endpoint. Its branch held counts therefore cannot be reused to invent a doctor tab total.

Bounded proposed follow-up: independent read projection for queue workload sections (held / treatment outcomes requiring attention / own medicine checks), with existing active-branch and queue-own/branch authority plus the stricter Treatment/Dispensary read policies. Each section needs its own database count, stable ordered25-row page, and complete eligible query independent of ordinary loaded pages. Reuse existing row mapper/action versions. Preserve treatment progress/current-outcome definitions; do not infer physical patient return, automatically resume, or acknowledge a medicine check merely by opening a tab. Confirm exact back-from-treatment membership against approved MA design before implementation.

Prefer a separate response/endpoint rather than adding undocumented keys to old exact tabCounts contracts. No new migration or role grant is needed for the read plan. New boundary tests should cover26eligible rows, older held record beyond serving25, doctor A/B isolation, effective branch changes, and no clinical outcome leakage to general queue-view roles. Existing label assertions (q5/q5b/q15) need a concrete approved semantic replacement when the final real tab set is implemented, rather than weakening them for this first visual slice.

This document is an implementation plan only. No new query, endpoint, action or grant has been added by the first presentation candidate.
