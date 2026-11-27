Emergency alerts now use one authorized shared host across workspaces. The recovered UI keeps Seen separate from Take, retains exact commands after uncertain responses, caps device mute at120 seconds and connects the current consultation's existing Save/Discard/Cancel decisions to Hold before Take. Take does not call the red patient or automatically resume the current consultation.

This scoped recovery also closes a reviewed teardown gap: an unmounted host cancels its Hold continuation, and retained composable callbacks cannot start requests after disposal. Existing Treatment panels and clinical form behavior are preserved; no backend, permission or schema changes are included.

**Stack:** #1188 presence → #1185 lane backend → #1182 lifecycle → #1180/#1172 Treatment → #1169 call. Family #1148 remains an explicit combined-workflow dependency. Lane UI is the next separate slice. Never merge archive #1142; this PR does not authorize production activation or claim full demo parity.

Validation: full frontend2247/2247, types/build, changed-file lint/format; independent11 lifecycle/composable tests and exact18-file artifact review. Actual synthetic browser: Cancel preserves draft; Save→Hold→lost Take response→unchanged retry; explicit Discard and hold without encounter PATCH; separate Seen;390/768/1440 light/dark; recorder restrictions and bounded mute. Stage-specific DB proofs9+7 confirm Waiting/uncalled red patients, retained episodes, exact claims and preserved saved notes. Harness-only wrong-route/old-button-label attempts were excluded and rerun against actual routes/labels.

Frozen patch SHA256: `5eb0103ba50a218072ef61fcd4a541ecba6f630b851d3934fb1ec0395b88a033`. Recovery and approved assertion provenance: `docs/reviews/TRI2-SHARED-UI-RECOVERY-2026-11-26.md`.
