# Purchase-order worklist — bounded read/UI plan

Base optional metadata1216. Read locked Stock.dc.html C0.po/C0.ordered/C0.recv and InvCreatePO/InvPoApprove mappings, actual InventoryOperationsDirectoryService, InventoryOperationsPanel, Index workspace tabs, StockTransferList and VerifiedReceivingForm. No source edits yet.

## Exact existing read contract

The directory returns newest50 POs scoped to active branch destinations plus warehouse only when existing warehouse permission allows; lines are batch-fetched for that bounded list. It already supplies publicId/number/status/version, supplier and destination labels, exact ordered/received/outstanding units, line count via actual lines length, source request, independent-actor denial flags, short-close evidence and saved expected date/reference. Directory does not return whole-dataset status counts, dispatched-to-supplier state or Returned lifecycle. Do not invent these or aggregate mismatched units. Existing permissions/stock state machine govern create/update/submit/approve/cancel/close/shortclose/receive and print; no broadening.

## First concrete composition

Within the existing Stock operations workspace, replace the long always-expanded PO article list with one named Purchase orders worklist: rows ORDER / SUPPLIER+destination / actual LINE COUNT / actual STATUS, mobile labelled stacks, named Open/Close disclosure and focused selected detail. Caption explicitly says newest50 loaded orders and any local filtering is within that window. Optional local Show status and search over supplied order/supplier/destination labels use current loaded rows only; counts say matching loaded orders, never clinic-wide or waiting-for-you. Exact backend states only: draft/submitted/approved/partially_received/fully_received/closed/cancelled, with shortclosed distinction preserved. No 'Sent to supplier' or 'Returned' fiction.

Create PO draft stays independently permission-gated in a collapsible persistent form before the worklist, using existing fields including metadata1216. Context says save draft first, then existing Submit action; no new transition. The selected detail reuses every original parent-owned action/edit/legacy receiving/VerifiedReceivingForm slot unchanged. Original global ReceivingReviews and global operation error remain visible outside the row list. Existing print route remains, no new export.

New local StockPurchaseOrderList component owns disclosure/filter/focus state; keyed rows and v-show details preserve drafts. A selected request cannot be hidden by filter/collapse while commands are pending. Parent busy already covers router actions; VerifiedReceivingForm owns separate useForm.processing, so add a UI-only processing-change emit/watch with unmount cleanup, aggregated by order ID into the worklist busy state. Preserve submit payload/scanner/retry/version methods exactly; new event only communicates rendering lock. Branch/order replacement resets selection and returns focus to surviving same-row trigger or named list when focus was inside; never steal intentional outside focus. Disabled-button blur handled as existing Counts/Case worklists. No SSR document access without guard.

## Exact intended files

InventoryOperationsPanel.vue; new StockPurchaseOrderList.vue; VerifiedReceivingForm.vue only optional processing event; new actualSFC list/processing/parent action-target tests; TEAM. Existing source assertion owners remain where possible; any exact extraction/heading pin changes require separate peer review before application. No migration, permission, new query or backend change anticipated. Metadata1216 remains separate dependency.

## Acceptance and remaining gaps

Test real status/filter/recent-window semantics, independent approval/receive/print authority slots, line count not totalquantity, selected identity/draft survival, busy selection/filter lock including childreceiving, focus fallback/no-steal/SSR and immutable confirmation target/version when listrefreshes. Actual synthetic create→draftedit→submit→independentapprove→openreceiving and refused/stale actions through normal UI; no stockwrite required just to validate worklist (prior receipt proof remains separate). Measure opened create/edit/receiving controls and row/filter/newbuttons36desktop44mobile at390/768/1440lightdark; report inherited fields separately if outside this slice rather than silently certifying them. Empty/local-no-match/read-only/error states and keyboard disclosure required.

This closes bounded real PO list composition inside current Stock operations. Full demo purchasing-group navigation, whole-dataset search/counts/pagination, separate On order lines, Returned workflow, supplier-sent state, pack conversion and lead-time suggestions are separate contracts. No totals fabricated. Peer review should decide whether truthful loaded-window local filters are preferable to a new server-paginated read slice; existing50 cap preserved by default.

## Implemented technical decisions (27 November)

Independent Staff review cleared the exact seven-file ASSERTION-PROPOSALS.patch under delegated technical authority. Applied only heading/layout and whitespace adaptations; retained every no-hide, authority, monetary-help, accessible-name and short-close check. Affected 30 tests pass. Prior full run 2262/2269 is preserved; final full rerun is separate. Per-instance Symbol ownership prevents an old receiving child's unmount from unlocking a replacement child; parent original command script is unchanged apart from this UI busy aggregation/import. The optional emit harness adaptation was separately reviewed; no existing assertions removed.

Browser harness correction: the initial busy-pause interceptor targeted `/receive`, while the actual unchanged VerifiedReceivingForm endpoint is `/receipts`. The real refused request completed but the harness waited for an unmatched route. Corrected the harness only, added a bounded timeout, and reran with a fresh synthetic draft reference. No application change or payload interception rewrite.
