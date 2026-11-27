# Registration Find/New composition — proposed bounded contract

Base: published search-state #1219, `7efdd0786659564e9c35758d18785977a8c4f03f`. Inspect actual `RegisterVisit.dc.html` Patient section: Find/New is beside the section heading; selected identity belongs to Find. This is a presentation control over existing state, not a new patient creation policy.

## Owned files

- `resources/js/components/registration/RegistrationCreateForm.vue`: small derived availability/state and Patient header controls/help only.
- One new actual-SFC runtime test file; any discovered existing source pins get an exact separate proposal before adaptation.

## Exact interaction

Use a labelled group of two native buttons, Find and New, with `aria-pressed` for current mode. This preserves browser Tab/Enter/Space semantics without falsely claiming custom radio keyboard behavior; visual segmented styling follows the demo. All controls remain inside existing `draftLocked` fieldset. Mobile targets 44px; desktop at least36px; wrap header rather than compress labels.

Find is pressed whenever `quickMode` is false, including selected patient. Activating already-current Find is a no-op (does not clear a selection). Switching New→Find calls existing `changePatient`, including existing search focus/recent restoration. Existing Change patient remains the explicit way to clear selected patient.

New is pressed when quickMode is true; activating it again is a no-op. From Find, New is enabled only when existing Quick create action would be rendered: no selected patient, no quick mode, and either (not searching, zero rows, nonempty trimmed query) or (showing recent, zero rows). Its handler rechecks eligibility and `draftLocked`, then calls existing `startQuickCreate`. No successful-search prerequisite; short queries, current error, and empty recent behavior remain unchanged. The original inline Quick create actions remain, using their unchanged conditions. This avoids removing existing discovery paths in the same slice.

When New is unavailable, adjacent concise help explains: “Find an existing patient first. New is available when no patients are listed.” Do not imply a completed duplicate search. Current pending/error/accepted-empty copy remains from #1219. Replace inherited inaccurate search help with “Search for an existing patient. If none are listed, Quick create can open a new patient draft.”

Quick draft is retained in its existing reactive state when returning to Find; no promise of storage across navigation. Returning New remains subject to the same current eligibility as before. No generated identifier, new request, changed key, backend rule, role, wizard/step, or required confirmation. Existing form submit, immutable command/display snapshot, refusal errors, field IDs, and full-page fallback remain unchanged.

## Proof

Actual compiled SFC tests: selected Find cannot clear patient; New unavailable with recent/results/loading; existing short/empty/error eligibility; current-mode no-op; switching restores existing focus and retains quick draft; locked/uncertain mode refuses switch; no mode action submits or searches itself. Prove payload remains existing selected/quick mapping through inherited immutable tests.

Browser: fullpage/dialog390/1440both themes, selected and new mode, keyboard native activation, no implicit write, old inline action intact, retained quick name after Find→eligible New, loading/results lockout, controlled uncertain state both buttons disabled. Use real authorized synthetic data, qualify any controlled failure. Keep exact-source/template scope and current command protections in independent review.

## Decisions and source checkpoint

Staff independently cleared the bounded contract and exact `PROPOSED-help-test.patch` under delegated technical authority on 27 Nov 2026 (UTC rollover; programme approvals remain 26 Nov). The one existing assertion changes only the help sentence regex; both original availability assertions and its accessible association remain unchanged. Four new actual-SFC cases plus six search-state, five immutable-dialog and five affected UI-polish tests pass (20 total). Script parity confirms the existing entire script is unchanged after removing the new availability computed and guarded mode dispatcher. No exact owner answer is invented for this delegated presentation decision.
