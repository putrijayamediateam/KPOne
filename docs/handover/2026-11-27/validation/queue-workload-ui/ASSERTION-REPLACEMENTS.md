# Recorded faithful assertion replacements

Authority: owner resumed existing work and delegated technical decisions/exact replacements on26November, relayed by coordinator. No runtime grant/migration changed. ASSERTION-REPLACEMENTS.patch is the exact reviewable diff against frozen draft1153. Independent review required before readiness.

- Q5: old page wiring to all-time removed/latest25 helper becomes Done today independent full day count and real page metadata. Utility edge-case tests retained unchanged. Backend now tests MYT boundary and filtered membership.
- Q5b: old Serving Now includes-held/latest25 wiring becomes own Seeing now / branch Serving now with independent not-held count and paginated data. Existing utility edge tests retained. Backend proves active hold exclusion.
- Q15: old Completed-tab passive badge placement becomes real Needs me→Medicine checks tab using its own scoped full count. Shared badge accessibility and old poll key/shape assertions remain unchanged. Actual tab behavior/own-only grouping checked in tab-counts test.
- TAB-COUNTS: actual extracted Queue tabs now tested against independent server aggregates, own/branch modes, Needs me and unavailable counts; no loaded25 inference. Registration assertions unchanged.
- Workspace watcher harness now supplies the added actual state dependencies and asserts reset of tab,page,workload request on branch/scope change in addition to original group reset.
- Fetch client test supplies standard URLSearchParams global and verifies explicit applied-filter querystring; original cancellation/stale-response tests retained.

Before replacement:22tests17passed5expected old-wiring failures. After replacement:22/22passed. No broad assertion relaxation or test deletion; app behavior changed to match the real bounded data semantics.

Full frontend run surfaced two further frozen-v4 presentation expectations (2281/2283 passed):
- UX-3 Queue heading: replace static hidden Consultation queue with actual AST-evaluated own My patients/branch Queue expression, while retaining exactly-one-h1, visible heading, main landmark and header placement. Registration and TV expectations unchanged.
- Panel-note shared board: retain every Payment/coverage/note tooltip/default mobile assertion; replace only empty-row colspan9 with exact queueLayout?6:9 condition. The focused queue layout has six columns; default Registration remains nine.

Staff reviewer notified of these exact replacements. Extra heading/column/reset19 focused tests passed. Reset regression executes actual page handler for all six tabs through the real queuePollPayload helper, always using Waiting transport; no assertion is removed to permit invalid backend status.
