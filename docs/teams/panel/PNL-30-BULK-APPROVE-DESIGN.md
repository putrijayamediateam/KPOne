# PNL-30: Approve selected Panel price changes (design note)

Owner decision (25 Nov 2026, relayed by B.E.R.N.I.S.S): the catalogue import (IMP-1) files the Panel prices as proposals, and the Director or a Finance Officer approves them before Monday. Puchong's file may hold about 300, and the page approves one at a time (throttle 20 a minute). Built under the owner's "build everything before Monday" and the fast process.

## What it does
- On the Waiting tab, an approver (`panel.prices.approve.organisation`: Finance Officer, Director) ticks rows, or ticks "Select all N that someone else proposed", presses **Approve selected (N)** and confirms once.
- One request, `POST /panel-price-changes/approve-selected` (throttle 6 a minute, `approvals.forget`), with `expected_branch_id` and, per row, the proposal's public id and the `lock_version` the approver saw.
- The waiting list now shows the oldest 200 (was 100) and says "Showing the oldest 200 of N waiting. Approve these and the next ones appear." So 300 proposals take two presses.
- No new permission, table, migration or role. Panel Officers see none of it and get 403 on the route.

## Rules (unchanged, per proposal)
`PanelPriceProposalService::approveMany` calls the existing `approve()` for each selected proposal, oldest first, **each in its own transaction**:
- fresh permission and branch check under lock; the approver is never the proposer (that row is skipped and named; the page does not offer it);
- row locks in the one existing order (charge, price book, proposal) and `lock_version` check; a stale base price is refused ("The price changed after this was proposed");
- the first price for a Panel creates its price book inside that approval (advisory lock), as today;
- one `panel_price.approved` and one `billing.price_published` audit row per proposal, ids and codes only.

**Partial success on purpose.** With about 300 rows, one stale row must not undo or block the other 299. A proposal that cannot be approved is skipped, stays waiting, and the page lists how many and the first reasons ("3 could not be approved and are still waiting. This proposal has changed. ..."). Nothing is half-applied inside a proposal. The batch writes one extra audit row, `panel_price.bulk_approved`, holding only the counts (requested, approved, skipped).

**Limits.** At most 200 per request (`BULK_LIMIT`), no repeated id, at least one; refused before anything is decided. The controller raises the PHP time limit to 180 s (about 50 to 100 ms a proposal is expected, so 200 rows is a few seconds). The throttle of 6 a minute is for the batch route only; the single approve route keeps 20.

## Tests
`PanelPriceBulkApproveTest` (service and route): all approved with own entry and audit and one counts-only batch row; stale, already decided and own-proposal rows skipped and named while the rest go through; a Panel Officer changes nothing (403); empty, oversized and repeated selections refused; the route toast and the skipped-rows message. Frontend source test `pnl-30-bulk-approve.test.mjs`.

## Review notes (Priya)
Priya's review found one blocker, fixed before this PR: the bulk panel had been placed between the empty state and the list, so the list lost its `v-else` and the rows never showed. The panel now sits before the empty state, a frontend test pins the order, and a browser pass confirms five rows with checkboxes and the confirm step at 1440 and 390 px. Also fixed: an upper-case proposal id is matched (lower-cased). Known and accepted: a permission failure part-way through a batch leaves earlier approvals in place (each has its own audit row, but no batch row is written); after a partly skipped batch the selection is cleared and the skipped rows stay waiting; a proxy or web server timeout shorter than the batch time would cut a very large press, so keep batches at 100 to 200.

## Not in this slice
A two-process race of two approvers on one batch (each approval is the existing, race-tested `approve()`); an "approve everything without ticking" button; rejecting in bulk.

## Current-main continuation, 26 November 2026

Codex billing_panel reproduced two additional edge cases on the recovered candidate: a Panel Officer submitting only absent UUIDs reached the counts audit without the approval permission check, and case variants of one UUID bypassed `distinct` before normalization. The batch now checks fresh transactional approval authority before lookup and again before its counts audit; UUID duplicate validation ignores case. Per-item approval checks remain unchanged. Two new regression tests cover these cases; existing assertions remain untouched. Partial-result validation responses now clear selection and close confirmation through the Inertia error callback, matching the behavior described above. No new role grants or migrations.
