# Waiting for payment: bounded read contract proposal

Source inspection26November2026, implementation not started. Follows dedicated Queried slice; source reference PanelClaimDirectoryService::claimBase/summary and PanelRemittanceService::openClaims plus PanelClaimPaymentReversal::NOT_REVERSED_P. Backend alignment is authorized by coordinator under owner delegation. This proposal changes no financial transition, ledger or authority.

## One screen/read slice

Add `waiting_payment` to the existing current-branch Panel claims list, with visible label Waiting for payment. Keep Approved and every existing tab unchanged. Eligible status is exactly accepted, partially_accepted or partially_paid; accepted_amount_sen is non-null; accepted minus sum of nonreversed claim payments is strictly positive. Bind payment organisation, branch and claim ID as the remittance read already does. Never count reversed entries; exact integer sen only. A paid/rejected/cancelled/prepared/submitted/queried claim is excluded. A legitimate payment reversal that reopens a payable claim returns it to this list through existing service behavior.

Rows retain existing claim/patient/Panel/invoice identity and authorized detail action, with additional explicitly labelled Accepted, Received and Unpaid values. Claimed amount remains identifiable rather than silently being relabelled. New read requires only existing panel.claims.manage.branch, because the claim detail already exposes these amounts. Record-payment navigation independently requires panel.remittances.record.branch. No all-branch identity or totals; no new grant.

## Scope, totals and bounds

Reuse existing directory branch/date/search predicates before eligibility and pagination. The date remains claim-created date in branch timezone and must be described explicitly; Received here means cumulative nonreversed remittances on these claims, not cash received within the selected period. Existing Amount filter remains claimed amount; label this clearly on the new view. Panel/name/invoice/claim filters remain exact existing semantics.

Return at most20 rows, full matching count, clamped currentPage/lastPage and matching-set accepted/received/unpaid integer totals from the same SQL statement snapshot. Prefer a small dedicated projection consuming the already-scoped filtered query, using a materialized base/eligible CTE with aggregate metadata and bounded row selection. Empty output still returns metadata; no application materialization of all claims. Do not use RemittanceService's selected-Panel200-row window or change its behavior. Directory's new waiting-payment badge should use this same computed count, avoiding a second divergent count for the active view. Existing summary cards remain unchanged; new matching totals are labelled separately, not substituted into old global/date summaries.

## Intended files and risks

New PanelWaitingPaymentProjection under existing Billing services; narrow directory integration and controller whitelist; existing PanelClaims/Index conditional columns/cards/context; new backend projection/controller tests and actual-render frontend tests; TEAM continuation. No schema/migration or new endpoint required. Parameterize all SQL fragments/bindings from trusted query builder, avoid interpolating user filters. Query count must be bounded independently of matching rows. Preserve existing bulk pack selection, actions, filter navigation and page reset behavior; no payment action added to read-only users.

Tests before publication: mixed accepted/partial/paid/nonpayable states; positive/zero balances; full/partial reversal using actual services; actor/branch/organisation denial; Panel/name/date/claimed-amount filtering; >200 matching rows or an equivalent isolated synthetic read fixture proving no200 cap;20+1 pagination/clamping/empty; exact full-set totals vs visible page; one-vs-many query bound and deterministic concurrent-insert observer demonstrating coherent metadata/rows from one statement. Render both list/card variants, zero-match totals, exact labels and permission combinations; browser390/1440 light/dark and keyboard access. No existing assertions changed without exact peer-reviewed adaptation.

## Separate unresolved contracts

Paid-date reporting (last payment, completion date or ledger period) and all-branch report authority remain separate. This slice deliberately delivers real waiting-payment balances now, rather than making missing backend data permanent visual debt. No bank/portal matching or statement-derived status is inferred from the demo's illustrative filters. Staff and independent backend plan review precede implementation.
