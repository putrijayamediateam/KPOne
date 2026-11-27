Connects the existing Claims, Follow-up list and Record Panel payment pages with one permission-filtered Panel navigation. The active page is a non-navigating item, so activating it does not reload a payment draft. Other cross-route navigation keeps its existing fresh-page behavior; this adds no unsaved-change guard.

Claims and remittance destinations retain independent permissions, with the Claims server capability allowed to narrow payment visibility. Branchless navigation is hidden. All three page scripts are unchanged apart from imports; status filters, split calculations, versioned requests and payment recording are preserved. The Remittances page also removes its duplicate branch description and uses a 44px mobile / 36px desktop Panel selector.

Validation: 25 focused and 2,216 full frontend tests, types and production build; final scoped lint/format/build after the local presentation fixes. Four real-component navigation tests independently passed. Existing route/wording assertions were relocated to the imported component after independent delegated review, preserving financial, permission and invoice-page checks.

Actual synthetic browser evidence covers both permission scopes at 390/768/1440 light/dark, active payment draft retention, keyboard navigation and the existing cross-route draft reset, with zero payment POSTs. No backend/grant/schema changes or production activity. This is bounded route continuity, not the demo's complete six-tab/global payment projection.

Stacked on PR #1198; the diff contains only this navigation slice. Root coordinates serial merge.

Final recapture: 33 states pass with no document/navigation overflow and the corrected selector measured 36px desktop / 44px mobile. Target certification covers navigation and that selector; older compact body controls remain separate sizing debt.
