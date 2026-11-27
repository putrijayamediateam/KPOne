# Independent Inventory plan review

Corrected Prepare-only F06 plan is clear against the actual DispensaryListService in KPOne-dsp-workspace. Append an optional argument to preserve callers; normalize after resolving the effective tab. Apply the immutable case_type predicate only to Prepare rows/count, leaving search independent of all counters and Waiting/Done counters unchanged. Retain existing branch/org/view authority, ordering, 25-row clamp and eager-load bounds. Done uses branch-local **today**, not a month window; preserve that exact contract.

Required validation: crafted check/done requests with a type normalize to All; leaving Prepare clears selection, returning begins All; search/pagination/refresh preserve selection only within Prepare; no extra queries or broader names. No new policy or mutation. This is plan/source-contract review only; no implementation review or tests rerun.
