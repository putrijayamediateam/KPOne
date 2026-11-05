# Inventory & Stock builder team

Status: **set up with the owner on 2026-11-05.** This folder is the team's memory: what is built, what
the owner decided, the rules, and what to build next. The team reads it before every task and updates it in
the same PR as the code.

## 1. Team

| Role | Name | Job |
|---|---|---|
| Lead | **Zulkifli** | Plans each piece, builds it (backend, pages, tests), keeps this file current |
| Reviewer | **Mei Ling** | Checks every piece against the rules below, the tests and the owner's decisions before it reaches the owner |

Both are Claude builder sessions working for the owner through the coordinator session. Names chosen by the
coordinator at the owner's request (2026-11-05).

## 2. What this area covers

**In scope (the team builds and maintains):**
- Stock references: items, SKUs, verified base stock units, locations, batches, medicine-to-SKU mapping.
- Opening balances and their independent sign-off (Pilot readiness).
- Verified receiving: delivery references, split lots, accepted / quarantined / rejected outcomes, delivery
  exception approval, reversing corrections.
- Batch safety holds and release; one shared batch eligibility rule; FEFO exception reasons.
- Stock review worklists (low stock, expiring, expired) and acknowledgements.
- Governed stock count, recount and write-off approval.
- Transfers and stock requests; reorder levels; suppliers.
- The stock movement ledger and balances, and the Stock movement report's data source.
- Pages: `Inventory/Index.vue`, `Readiness.vue`, `BatchHolds.vue`, `StockReview.vue`, `GovernedStock.vue`, the
  Suppliers settings screen.

**Shared with other teams (change only through a small agreed change):**
- Dispensing allocation and stock debit at Complete; drug-label batch and expiry: **Dispensary & OTC** team
  (Aisyah and Hakim). Their DSP-2 prints batch and expiry on labels; scanning stays here.
- Stock figures in reports: **Reports** team.
- Medicine catalogue and Unified Catalogue Setup: **Catalogue & settings** team.
- Agents that read stock (Hana, Rizal): **Agent platform** team, read-only through data sources.
- Permissions catalogue: changed only by an owner-approved migration.

## 3. Where the code lives

| Kind | Files |
|---|---|
| Domain services | `app/Domain/Organisation/Inventory/Services/` — `InventoryMovementService`, `InventoryBatchEligibility`, `InventoryBatchHoldService`, `GovernedStockService`, `InventoryStockReviewService`, `InventoryReadinessService`, `ProcurementService`, `ReceivingReviewService`, `ReceiptCorrectionService`, `StockRequestService`, `InventoryReferenceAdministrationService`, `SupplierSettingsService`, `InventoryPickerService`, `InventoryMovementProjection`, `StockUnitQuantity`, `LegacyStockPath` and others |
| Models | `app/Domain/Organisation/Inventory/Models/` — items, SKUs, batches, locations, balances, movements, purchase orders, goods receipts, stock requests, stocktakes, adjustments, reorder levels, suppliers |
| Pages | `resources/js/pages/Inventory/*.vue` |
| Analysis | `docs/reports/ANALYSIS-STOCK.md` (proposals STK-1 to STK-10, owner and pharmacist questions) |
| Delivery reports | `docs/reports/STK-1.md` to `STK-6A.md` |
| Tests | `tests/Feature/Inventory/*` (including the PostgreSQL contention tests), `PostgresDispensaryInventoryRegressionTest`, `InventoryOperationsTest`, `NAV01ExplicitRedirectsTest` |

## 4. Permissions (who can do what today)

Source of truth: `app/Domain/Access/PermissionCatalogue.php`. Key ones:

| Permission | Holders | Use |
|---|---|---|
| `inventory.view.branch` | Director, CA, CA Supervisor, Finance Officer, Administrator | See stock and worklists |
| `inventory.references.manage.organisation` | Director, CA Supervisor, Administrator | Items, SKUs, units, locations, batches |
| `inventory.opening.verify.branch` | CA Supervisor, Finance Officer, Director | Independent opening sign-off |
| `inventory.receiving.exceptions.approve.branch` | CA Supervisor, Finance Officer, Director | Delivery exception approval |
| `inventory.batch_holds.place.branch` / `.release.branch` | place: CA Supervisor, Finance Officer, Director; release: Finance Officer, Director | Batch holds |
| `inventory.stock_reviews.manage.branch` | CA Supervisor, Finance Officer, Director | Stock review acknowledgements |
| `inventory.variances.approve.branch` | CA Supervisor, Finance Officer, Director | Count and write-off approval |

## 5. What is built (history)

| Slice | What it did | PR |
|---|---|---|
| I1A / I1B | Inventory reference governance, demo workspace | #20, #21 |
| I2 | Inventory operations demo v1 (suppliers, purchase orders, receiving, requests, transfers, stocktake, adjustment, reorder levels) | #25 to #29 |
| UI-1 / NAV-01 | Stock setup screens; explicit Inventory redirects | #36, #40 |
| Unified Catalogue Setup | Medicine setup creates or reuses its item and SKU, optional batch and opening stock | #43 |
| SET-03 | Suppliers settings | #106 |
| Pilot scale limits | Bounded inventory picker, smaller Inventory payload | #176 |
| STK-1 | One verified base stock unit per SKU; Pilot readiness; independent opening sign-off | #180 |
| STK-2 | Branch batch holds, shared eligibility, FEFO exception reasons | #182 |
| STK-3 | Verified receiving with delivery reference, split lots, outcomes, exception approval, corrections; real expiry required | #183 |
| STK-4 | Stock review worklists and acknowledgements | #185 |
| STK-5 | Governed count, recount and write-off approval | #186 |
| STK-6A | Legacy stocktake and manual adjustment closed; ledger writer allowlist | #188 |
| RPT-01e | Stock movement summary (quantities only) | #148 |
| CLOCK-1 | Stock review test uses branch dates | #208 |
| STK-6 | Transfer shortages: receipt lines (accepted, short, damaged, extra), only accepted enters stock, append-only differences resolved by a different write-off approver (domain and PostgreSQL) | pending (branch `feature/stk-6-transfer-shortages`) |

## 6. Owner decisions already made (never ask again)

1. One verified base stock unit per SKU; dispensing refused when units differ (2026-10-27).
2. A real, human-entered expiry date is required on every new receipt; no substitute date (2026-10-28).
3. A delivery reference is required on every new receipt (2026-10-28).
4. Independent people: the opening recorder cannot sign their own opening; delivery exceptions, releases and
   write-offs are approved by a different person (2026-10-27 to 2026-10-28).
5. Batch hold covers one batch at one branch; release by Finance Officer or Director (2026-10-27).
6. The legacy stocktake and manual adjustment path is closed; the governed Stock count and Write-off is the
   only path (2026-10-29).
7. Pilot: the CA Supervisor is the responsible person; independent approvals by two CA Supervisors, a Director
   and a Finance Officer; a signed stock admission procedure; a pharmacist review stays open (2026-10-30).
8. Production procurement, receiving, stocktake and adjustment workflows and stock migration remain not
   authorised beyond the merged STK slices (BASELINE section 4).
9. Valuation (STK-10) needs an accountant-approved cost policy before any build (2026-11-03).
10. Transfer differences are resolved by the write-off approvers (CA Supervisor, Finance Officer, Director),
    never by the receiver (2026-11-05).
11. Supplier returns: a CA Supervisor requests; a Finance Officer or Director approves, never the requester
    (2026-11-05).
12. Treatment-room usage is recorded by CA and CA Supervisor, audited and shown in stock reports (2026-11-05).
13. STK-6: the person who dispatched a transfer may resolve its differences; only the receiver is barred
    (2026-11-05, at "ship it").

## 7. Open items and known limits

- **Planned, not built:** STK-7 supplier returns and credit-note references, STK-8 treatment-room (non-dispensed) usage, STK-9 barcode scanning (label batch and expiry moves
  to the Dispensary team's DSP-2), STK-10 valuation (accountant policy first).
- **Parked review (2026-10-21):** no match against a supplier's invoice, no printable purchase order, reorder levels raise no alert by themselves (STK-4 worklists now show
  low stock). `InventoryController::referenceData()` still projects all items, SKUs and locations unbounded.
- **Professional questions** for a pharmacist or the regulator: regulated items, registers, FEFO policy,
  retention.
- Quarantine holds the whole batch at that branch; corrections reverse a whole untouched receipt; a wrong
  item is rejected, never substituted.
- Privileged direct SQL is outside the ledger guard; no PostgreSQL guard was added in STK-6A. STK-6 adds one
  narrow guard: a new `transfer_receipt` movement must have receipt-line or resolution evidence.
- STK-6 limits: a difference is resolved whole, once (no partial resolution); extra stock never enters stock
  through a transfer (keep it via a governed stock count); an item that arrived but was never on the dispatch
  is not recorded here; receiving without lines still means "everything arrived"; direct one-step transfers
  within a branch have no in-transit stage and are unchanged; a warehouse destination needs the existing
  warehouse permission to receive or resolve.

## 8. Rules (every task)

- Read `CLAUDE.md`, `AGENTS.md`, `docs/BASELINE.md`, `docs/reports/ANALYSIS-STOCK.md` and this file first.
- Never open, print or edit `.env`. Synthetic `.test` data only.
- Every state change: backend permission and branch scope, `DB::transaction`, row locks, `lock_version`,
  idempotency where retried, and a structural audit row with identifiers only.
- Every stock movement goes through the ledger writer allowlist; never write balances or movements directly.
- Exact thousandth arithmetic through `StockUnitQuantity`; refuse negative stock and overflow.
- Independent-person rules are never weakened without an owner decision recorded in section 6.
- Do not change any existing test assertion without the owner's approval.
- Full gate before hand-off on a task-owned PostgreSQL 18 database, including every inventory contention
  test with none skipped; screenshots at 1440 and 390 px, light and dark, for any screen change.
- Commit, push and PR only after the owner says "ship it"; never merge.
- Record every new owner decision in section 6 and every finished piece in section 5, in the same PR.

## 9. Backlog (next pieces, in order)

Chosen by the owner on 2026-11-05; built one by one in this order, each its own PR. Choosing them is the owner's
authorisation to build them; record that in `docs/BASELINE.md` with the first PR of each. Each follows the
matching proposal in `docs/reports/ANALYSIS-STOCK.md` section 8 unless an owner decision below says otherwise.

1. **STK-6 Transfer shortages.** *Built 2026-11-05, awaiting owner review.* The receiving branch records actual receipt lines (accepted, short, damaged,
   extra); dispatch and receipt reconcile without forcing a full receipt; a different person resolves each
   difference, append-only. **Owner decision 2026-11-05: resolvers are the write-off approvers (CA Supervisor,
   Finance Officer, Director), never the person who received the transfer.**
2. **STK-7 Supplier returns.** Return request, approval and dispatch tied to the receipt and batch, with a
   credit-note reference; no payment engine; Finance sees commercial references without patient data. **Owner
   decision 2026-11-05: a CA Supervisor requests; a Finance Officer or Director approves, never the requester.**
3. **STK-8 Treatment-room usage.** Dedicated non-dispensed usage and waste records and movements (not hidden
   in adjustments); no billing bundles; not linked to a patient unless separately authorised. **Owner decision
   2026-11-05: CA and CA Supervisor record usage; every entry audited and shown in stock reports.**
4. **STK-9 Barcode scanning.** Scan a known code to pick the item and batch when receiving, counting or
   dispensing; codes map locally; no external lookup; scanning never authorises an action by itself. Label
   batch and expiry printing belongs to the Dispensary team's DSP-2.
