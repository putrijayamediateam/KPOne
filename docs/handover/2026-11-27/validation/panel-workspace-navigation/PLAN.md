# Panel operational workspace navigation — bounded next plan

## Evidence and scope

The effective HTML wrapper directs PanelClaims, ClaimsFollowUp and PanelPayments into the Panel workspace. The extracted Panel contract SHA e64a285e5430b20e8fb5d59460dd84071f005d2ed3939db637e3b6508c8ffc5e lists To claim, Submitted, Follow up, Waiting for payment, Paid and Queried. Actual source uses three route-backed surfaces: `/panel-claims`, `/panel-claims/follow-up` and `/panel-remittances`. Claims has its own All/Pending/Overdue/Submitted/Approved/Rejected/Received status model; remittances is a record/split/history screen, not a pure Paid board. Do not rename these as if the data contracts were identical.

Proposed coherent first slice: a local permission-filtered PanelWorkspaceNav, labelled Claims / Follow-up / Record payment, shared by the three actual pages. Keep active route aria-current, visible focus and36px desktop/44px below1024. No sidebar to squeeze the wide Claims table, no new summary/KPI, and no new mutation. Preserve existing page headings/descriptions except remove redundant cross-page buttons once equivalent navigation is verified. Do not alter status tabs, selected claim identities, filters, drafts, versions, pack/CSV behavior, or money logic.

## Owned files

- New `resources/js/components/panel/PanelWorkspaceNav.vue`.
- `resources/js/pages/PanelClaims/Index.vue`.
- `resources/js/pages/PanelClaims/FollowUp.vue` (stack on reviewed PR1198).
- `resources/js/pages/PanelRemittances/Index.vue`.
- New rendered navigation tests; exact existing appearance/navigation assertions adapted only after delegated peer review if necessary.

No shared shell primitive or backend ownership. Page scripts may add only the component import; preserve remaining bytes. Route middleware grants Claims/FollowUp through panel.claims.manage.branch and Remittances through panel.remittances.record.branch. Claims' current work.can.recordRemittances derives from the same remittance permission. Use signed-in shared auth.permissions solely for visibility; existing route/controller/service authority remains authoritative. Test claims-only, remittance-only, both and neither; do not assume one permission implies the other.

## Risks and validation

Avoid duplicate navigation after insertion and avoid making settings/report/price-change links appear without their own permissions. Current route parameters remain bound to their existing page controls; navigation between different pages intentionally starts that destination's existing default state, with no invented cross-page filter persistence. Any existing unsaved-change behavior must be preserved, especially the payment split form; inspect its guards before choosing Link integration.

Run focused existing and new frontend tests, lint/format/types/build in a coordinated slot, then390/768/1440 light/dark actual synthetic navigation and permission visibility. Include payment form draft/leave behavior and return route. Preserve existing financial tests and no mutations merely to prove nav. Freeze exact diff/source hashes, independent Staff source/visual review, then scoped stacked draft under standing authorization. No new grant, schema, production action or merge.

Unresolved full-demo gaps remain explicit: demo global Paid/Queried/status counts and all-branch aggregate view require reconciliation with actual backend projections. This navigation slice is not full six-tab parity and will not invent empty links or misleading counts.
