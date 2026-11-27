# DSP28 bounded independent review by Inventory — 26 November 2026

Reviewed candidate /workspace/KPOne-dsp28-publication source diff, recovery review note, new DispensaryReturnsHistoryTest and controller/PharmacySpace changes. No source edits or heavy test rerun.

No blocking finding within requested authorization, branch/month/query and privacy scope. waitingList refreshes active actor and requires view plus mayCheck; effective active branch/org assignment checked. Waiting and checked queries share the scoped base. CarbonImmutable branch-local month start converts to UTC half-open bounds without mutating month label. Controlled filtering intentionally uses current catalogue status and treats null as not controlled. waitingTotal retains waiting/default filter semantics while suppressing other-tab count. Non-string view/controlled request values safely fall back.

Projection adds only outcome/refusal code/checker/time, excludes free-text patient/refusal notes, and continues staff-directory visibility-filtered names with fallback. Original case checking/stock mutation rules are untouched; history and filters are read-only GETs. New tests cover checked transition/count, before-local-month exclusion, both filters, note exclusion, CA denial and array fallback. Optional exact-start inclusion/next-month exclusion regression would strengthen boundary evidence; not a blocker from source inspected.

This is not a visual or full CI signoff. Existing review's empty filtered-state copy limitation and desktop32px filter pills are outside this bounded backend/source review; designer/coordinator own those gates.
