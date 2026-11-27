# Dispensary case disclosure — bounded implementation plan

Owner/coordinator authorized this next existing-page presentation slice on 26 November 2026. Base: local 0f2c59a8, reviewed PR1139 foundation merged with main e5e72; publication stacking is separately coordinated. PR1140 and1155 remain frozen.

Files: resources/js/pages/Dispensary/Show.vue; one new tests/Frontend/dsp-case-disclosures-runtime.test.mjs; docs/teams/dispensary-otc/TEAM.md. No backend, routes, permissions, schema, payload or lifecycle changes.

Reference: locked PharmacyCase.dc.html renders compact medicine identity/directions/quantity with a batch chooser and explicit add controls. The details disclosure is an approved adaptation for real clinical fields absent from the mock, not a control copied from the artifact. Existing real Show retains clinical validation, batch availability, label budgets, allergy confirmation, pack conversions and save recursion. This partial slice preserves those controls mounted with v-show and uses explicit accessible disclosure buttons; it does not claim full demo parity.

Saved medicine cards show persisted quantity/directions plus a Not saved badge when draft differs; pending and allergy-unconfirmed lines start open. Every editable line has Edit details/Close details with aria-expanded/aria-controls and44px mobile/36px desktop target. Closing never resets drafts, selections, allergy confirmations or errors. Existing scan warning remains outside disclosure. Readonly doctors retain existing details without editing controls.

Actual-source correction to earlier NEXT-CASE-SLICE: pointAtBatch scrolls only; scanner focus deliberately stays in scanner. Open the target before nextTick/scroll, never focus/select a batch. Saving explicitly keeps its editor open through the pending→saved response, avoiding hidden focused Save controls; Save error opens affected editor; Save all validation opens offending editor but retains unchanged guard order, payloads and done callbacks. Existing completion blockers are independent of visibility.

Add medicine/service blocks use mounted disclosures. Closing preserves query/results/chosen draft. Disable new collapse while busy/searching or medicine scanner open, preserving scanner focus paths. Existing service line editors remain unchanged. No Ready state, follow-up, return-check navigation, or default return reason.

Risks/tests: hidden dirty content, scan scroll/focus, server refresh preserving draft, failed save reopen, sequential Save all callbacks, readonly guards, add draft retention. Run existing dispensary frontend regressions unchanged first; record any exact assertion impact before adapting. Add tests executing actual SFC script/rendered component with mocked transport, then actual synthetic browser390/1440 light/dark with dirty-collapse/reopen, scan, error, empty/add and readonly states. Scoped lint/format, types/build and independent design review. No commit/push until final reviewed packet coordination.
