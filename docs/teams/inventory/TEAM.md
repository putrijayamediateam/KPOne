# Inventory & Stock builder team

Status: **set up with the owner on 2026-11-05.** This folder is the team's memory: what is built, what
the owner decided, the rules, and what to build next. The team reads it before every task and updates it in
the same PR as the code.

## 1. Team

| Role | Name | Job |
|---|---|---|
| Lead | **Zulkifli** | Plans each piece, builds it (backend, pages, tests), keeps this file current |
| Reviewer | **Mei Ling** | Checks every piece against the rules below, the tests and the owner's decisions before it reaches the owner |
| UI/UX designer | **Wei Jie** | Sketches every screen change before it is built and checks the 390 and 1440 px light and dark screenshots before hand-off; works under the Lead UI/UX Designer, Daniel (Design team) |

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
- The 2026-11-11 programme (section 6 item 32): purchase requests, PO approval and print, supplier invoice match,
  supplier price lists and lead times, reorder and min/max suggestions, stock other branches need, cycle counts,
  the draft controlled-drug register, recalls across branches, GS1 hints at receiving, the opening-stock import,
  and the pilot procedure and pharmacist pack under `docs/pilot/inventory/`.
- Pages: `Inventory/Index.vue`, `Readiness.vue`, `BatchHolds.vue`, `StockReview.vue`, `GovernedStock.vue`, the
  Suppliers settings screen, and the programme pages (`PurchaseRequests.vue`, `PurchaseOrderPrint.vue`,
  `SupplierInvoices.vue`, `SupplierTerms.vue`, `SupplierReturns.vue`, `TreatmentUsage.vue`,
  `ReorderSuggestions.vue`, `BranchReplenishment.vue`, `ControlledRegister.vue`, `ControlledRegisterShow.vue`,
  `ControlledRegisterPrint.vue`, `Recalls.vue` and `Skus.vue`).

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
| Domain services | `app/Domain/Organisation/Inventory/Services/` — `InventoryMovementService`, `InventoryBatchEligibility`, `InventoryBatchHoldService`, `GovernedStockService`, `InventoryStockReviewService`, `InventoryReadinessService`, `ProcurementService`, `ReceivingReviewService`, `ReceiptCorrectionService`, `StockRequestService`, `SupplierReturnService`, `TreatmentUsageService`, `InventoryCodeLookupService`, `InventoryReferenceAdministrationService`, `SupplierSettingsService`, `InventoryPickerService`, `InventoryMovementProjection`, `StockUnitQuantity`, `LegacyStockPath` and others |
| Models | `app/Domain/Organisation/Inventory/Models/` — items, SKUs, batches, locations, balances, movements, purchase orders, goods receipts, stock requests, stocktakes, adjustments, reorder levels, suppliers |
| Pages | `resources/js/pages/Inventory/*.vue` |
| Analysis | `docs/reports/ANALYSIS-STOCK.md` (proposals STK-1 to STK-10, owner and pharmacist questions) |
| Delivery reports | `docs/reports/STK-1.md` to `STK-9.md`, `STK-10B.md`, `STK-11.md` |
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
| `inventory.supplier_returns.request.branch` | CA Supervisor | Request, cancel (own) and dispatch supplier returns (STK-7) |
| `inventory.supplier_returns.approve.branch` | Finance Officer, Director | Approve or reject supplier returns (never their own request) and record the credit note (STK-7) |
| `inventory.usage.record.branch` | CA, CA Supervisor | Record treatment-room usage and waste (STK-8); reversal uses `inventory.variances.approve.branch` |
| `inventory.cost.view.organisation` | Director, Finance Officer | See inventory cost (STK-13) |
| `inventory.medicine_sales.view.organisation` | Director, Finance Officer | The medicine sales page (STK-44, P9) |
| `inventory.purchase_orders.approve.organisation` | Director, Finance Officer | Approve purchase orders, never one they raised or submitted (STK-19) |
| `inventory.purchase_requests.create.branch` | CA, CA Supervisor | Raise purchase requests; converting one uses the purchase order create permission (STK-18) |
| `inventory.supplier_invoices.record.branch` / `.approve.branch` | record: CA Supervisor, Finance Officer; approve: Finance Officer, Director | Supplier invoice match; the recorder never decides (STK-22) |
| `inventory.controlled_register.view.branch` / `.check.branch` | CA Supervisor, Finance Officer, Director | Draft controlled-drug register and its second-person checks (STK-24) |
| `inventory.recalls.manage.organisation` / `.lift.organisation` | start: Director; lift: Director, Finance Officer | Recalls across branches; never lifted by the starter (STK-25) |

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
| STK-6 | Transfer shortages: receipt lines (accepted, short, damaged, extra), only accepted enters stock, append-only differences resolved by a different write-off approver (domain and PostgreSQL) | #217 |
| STK-7 | Supplier returns: request from a verified receipt lot, approval by a different person (Finance Officer or Director), dispatch through a new `supplier_return` ledger movement, credit-note reference (no payment); append-only evidence guarded by PostgreSQL | #233 |
| STK-8 | Treatment-room usage and waste: CA or CA Supervisor records stock used or wasted outside dispensing (new `treatment_usage` and `treatment_waste` ledger movements), optional treatment room, no patient link or bill; a different CA Supervisor, Finance Officer or Director may reverse an entry once (credit back as `adjustment_in`); append-only evidence guarded by PostgreSQL | #244 |
| STK-9 | Barcode scanning on Inventory screens (receiving, governed count and write-off, treatment-room usage, supplier returns): handheld scanner, typing, or the browser's built-in camera reader; one barcode, one SKU per organisation (domain and PostgreSQL, existing duplicates listed on Pilot readiness); a scan only finds | merged in PR #253 (`4445021`) |
| STK-11 | Patient returns location for DSP-7: a `dispensary_returns` location type that is never picked or dispensed from, the `dispensary_return` movement written only by `InventoryMovementService::recordDispensaryReturn` (for the Dispensary return check), returns stock kept out of every usable-stock figure, and out only by STK-5 or a move to another returns location | merged in PR #279 (`d3fcea1`) |
| STK-10B | Receipt cost evidence (option B of the STK-10 analysis): each delivered batch records the total paid, as on the supplier invoice, marked actual; a blank cost keeps the order estimate, marked estimate; both receiving forms take the total; the order estimate says it is per stock unit; no cost shown anywhere | merged in PR #309 (`a1a44d8`) |
| DD-12 | Inventory page header and task links | #496, #512 |
| STK-12 | Patient-returns stock listed by itself ("Patient returns only" filter) | #497 |
| STK-13 | Supplier price list and lead time per SKU; cost seen only by Director and Finance Officer | #500 |
| STK-14 | General SKU edit screen | #505 |
| STK-15, STK-15b | Treatment-room waste and approved write-offs on Insights → Inventory | #604 (carries #506) |
| STK-15c | A reversed governed count loss leaves Recorded wastage | #614 |
| STK-16 | Read-only reorder suggestions (usage and lead time) | #509 |
| STK-17 | Return several lots from one delivery together | #518 |
| STK-18 | Purchase requests: a CA asks, another person turns it into a draft purchase order | #565 |
| STK-19 | Purchase orders approved by a different Finance Officer or Director (domain and PostgreSQL) | #520 |
| STK-20 | Printable A4 purchase order | #522 |
| STK-22 | Supplier invoice three-way match; a difference decided by a Finance Officer or Director who did not record it; KPOne pays nothing | #527 |
| STK-23 | Cycle counts by ABC class, with blind counting, feeding the STK-5 governed count | #528 |
| STK-24 | Controlled-drug register with second-person checks (DRAFT, pending pharmacist review) and its review sheet `docs/pilot/controlled-register/REVIEW.md` | #533 |
| STK-25 | Recalls across branches: a Director starts, another Director or a Finance Officer lifts | #539 |
| STK-26 | Verified receiving names the GS1 carton's batch and expiry on the scanned line (hint only) | #598 (in batch #601) |
| STK-30 | Optional maximum on reorder levels; suggestions fill up to it | #606 |
| STK-30b | Version and idempotency key on saving a reorder level | #611 |
| STK-31 | Stock other branches need: suggestions sent as an ordinary stock request from the branch that holds the stock; a stock request may now point at another branch | #615 |
| STK-21 | Close the rest of a partly received purchase order (short close by a Finance Officer or Director who did not raise or submit it; no stock or money change) | #525 |
| STK-35 | Import opening stock from one CSV: check first, then every row or none; each opening still needs STK-1 sign-off by someone else | #617 |
| STK-34 | Draft stock admission procedure, `docs/pilot/inventory/STOCK-ADMISSION.md` (docs) | #618 |
| STK-36 | Pharmacist review pack for the controlled register and FEFO, `docs/pilot/inventory/PHARMACIST-REVIEW-PACK.md` (docs) | #619 |
| STK-37 | Stock moves use the STK-2 usable-batch rule: dispatch always; a direct transfer too, except between returns locations and within a branch into a store that is neither a dispensary nor returns (the STK-25 quarantine shelf) | #624 |
| STK-33 | Stock health (`/inventory/stock-health`): days on hand, turnover and expiry loss per SKU and location, quantities only | #626 |
| DD-75, DD-76 | Inventory task links: three groups side by side from 1024 px, folding groups with a count on phones (44 px summaries); a two-line Pilot readiness card pointing to the pharmacist review pack | #629 |
| DD tiles | Inventory summary tiles (SKUs with stock, Total batches, Expired batches) stay in one row at every width, sentence case; fits 320 px | #633 (in batch #634) |

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
9. Valuation (STK-10) needs an accountant-approved cost policy before any build (2026-11-03). *Overtaken: built on
   draft defaults (item 32, STK-27 to STK-29); the accountant approved the policy verbally on 25 Nov 2026 (decision 49).*
10. Transfer differences are resolved by the write-off approvers (CA Supervisor, Finance Officer, Director),
    never by the receiver (2026-11-05).
11. Supplier returns: a CA Supervisor requests; a Finance Officer or Director approves, never the requester
    (2026-11-05).
12. Treatment-room usage is recorded by CA and CA Supervisor, audited and shown in stock reports (2026-11-05).
13. STK-6: the person who dispatched a transfer may resolve its differences; only the receiver is barred
    (2026-11-05, at "ship it").
14. STK-7 permissions: two new ones, `inventory.supplier_returns.request.branch` for CA Supervisor and
    `inventory.supplier_returns.approve.branch` for Finance Officer and Director, through one additive
    migration (2026-11-05).
15. STK-7 ledger: a new `supplier_return` movement type with one allowlisted writer
    (`InventoryMovementService::recordSupplierReturn`, called only by `SupplierReturnService`); the expected list
    in `LegacyStockWriterBoundaryTest` is updated for it (owner-approved assertion change, 2026-11-05).
16. A "ship it", or any other owner decision, relayed by B.E.R.N.I.S.S (formerly LUCY, the coordinator session) counts as the owner's
    own; the team acts on it without asking again. Still never merge, never edit `docs/BASELINE.md`, and stop
    and ask the owner if a relayed message conflicts with something the owner said directly (2026-11-05,
    confirmed by the owner in the team's own session).
17. STK-8 permission: one new permission, `inventory.usage.record.branch`, for CA and CA Supervisor only,
    through one additive migration (2026-11-05).
18. STK-8 ledger: two new movement types, `treatment_usage` and `treatment_waste`, with one allowlisted writer
    (`InventoryMovementService::recordTreatmentUsage`, called only by `TreatmentUsageService`); the expected list
    in `LegacyStockWriterBoundaryTest` is updated for it (owner-approved assertion change, 2026-11-05).
19. STK-8 mistakes: a CA Supervisor, Finance Officer or Director who did not record an entry may reverse it once,
    with a controlled reason; the stock goes back as `adjustment_in` (2026-11-05).
20. STK-8 report: the Stock movement summary is left unchanged; usage and waste show there under "Adjusted" until
    the Reports team adds a column (2026-11-05).
21. STK-8 waste rule: usage and waste are for usable stock only; expired, held or unavailable stock is refused and
    goes through the governed Write-off, keeping its second approver (2026-11-05, after Mei Ling's review).
22. STK-9 screens: scanning on Inventory screens only (receiving, governed count and write-off, treatment-room
    usage, supplier returns); dispensing is the Dispensary & OTC team's own piece, reusing `GET /inventory/scan`
    (2026-11-05).
23. STK-9 codes: one barcode, one SKU per organisation (trimmed, case-insensitive), enforced in the domain and
    PostgreSQL; existing duplicates are not changed and are listed on Pilot readiness to fix by hand; the batch is
    still picked by hand (2026-11-05).
24. STK-9 hardware: handheld USB or Bluetooth scanners, typing, and the phone or tablet camera through the
    browser's built-in barcode reader only (Android Chrome); no barcode library, so iPhone and iPad fall back to a
    scanner or typing (2026-11-05).
25. Design team rule (relayed by LUCY, 2026-11-05): every piece that changes a screen has Wei Jie sketch it before
    building and check the screenshots against `docs/design/CHECKLIST.md` before hand-off; a new page or page layout
    change also goes to Daniel through B.E.R.N.I.S.S. Pieces with no screen change skip this.
26. Backup push (relayed by LUCY, 2026-11-05): whenever the team stops to wait for the owner (a "ship it", a
    question or the end of a turn) with work not yet on GitHub, it commits that work to the piece's own feature
    branch and pushes it (`git push -u origin <branch>`). Backup only: no PR, never `main` or another team's branch,
    never merge, never force-push. If the branch already has an open PR, push only when the change is ready (a push
    re-runs CI). The PR still opens only after "ship it", from the same branch, with the latest `origin/main`
    merged first. After a session restart on a fresh machine, fetch the branch before continuing.
27. STK-11 for DSP-7 (the owner's "go with recommendations for DSP-7", relayed by B.E.R.N.I.S.S, 2026-11-05):
    - a patient returns location type, `dispensary_returns`, at a branch: never allocated, picked or dispensed
      from, enforced in the domain and PostgreSQL; a location never changes to or from that type;
    - one new movement type, `dispensary_return`, written only by `InventoryMovementService::recordDispensaryReturn`,
      called only by the Dispensary return check (DSP-7); `LegacyStockWriterBoundaryTest` lists it (assertion change
      approved by the owner in the team's own session, 2026-11-05: "yes, approve the test change for STK-11"; the
      same kind as STK-7 and STK-8);
    - the Stock movement summary counts it under "Adjusted", as for the STK-8 types;
    - returns stock is never usable stock: it is left out of availability, pickers, STK-4 low stock and expiring,
      Insights inventory figures and the agent inventory sources;
    - it leaves only by an STK-5 count or write-off, or a direct move to another returns location (coordinator's
      rule, so returned medicine never becomes dispensable again; a move back to normal stock would need the
      owner);
    - no new permission (the Dispensary checker uses `inventory.variances.approve.branch`); migration
      `2026_11_07_000400`. The contract was agreed with Aisyah (Dispensary & OTC).
28. STK-10 analysis docs PR: "ship it", given as **B.E.R.N.I.S.S for the owner** (2026-11-05, ~22:25 UTC, under
    the owner's overnight hand-over of 22:12 UTC), because it is docs only and approves no valuation, like #172 to
    #174. On the owner's board for review. Waiting on the owner in person: the accountant's name and package,
    whether to do option B, and who may see cost.
29. STK-10B, the owner's own choices in the team's session (2026-11-06): "yes, do option B"; staff type the **total
    paid for each delivered batch**, exactly as on the supplier invoice, so no per-unit cost is rounded; cost stays
    **optional**, and a blank cost keeps the order's estimate **marked as an estimate**; **both** receiving forms take
    the lot total, and the owner approved changing the existing assertions in `i2-inventory-operations.test.mjs` and
    `unified-catalogue-setup.test.mjs` for it. Still waiting on the owner: the accountant's name and package.
    Migration `2026_11_07_000700`.
30. Who may see cost (owner, relayed by B.E.R.N.I.S.S in the owner's words, 2026-11-06): "only Director sees cost".
    If KPOne ever shows a stock cost, only the Director sees it. *Superseded by item 33 (STK-13): the Director and
    the Finance Officer see cost.* The same relay said "option A" for STK-10; that
    conflicted with "yes, do option B" in this session, so the team asked the owner here, and the owner chose
    "Option B, keep building".
31. STK-10B "ship it" from the owner in the team's session (2026-11-06); PR #309. Design review by Daniel (Lead UI/UX
    Designer): OK with changes. His must-fix (the cost help sat under the required delivery reference) was fixed
    before merge: each cost field has its own help line and the label says "(RM, optional)". Labels for the
    placeholder-only Batch, Expiry and Quantity boxes on the Post receipt row stay with design debt DD-12, agreed
    with Daniel. Merged as `a1a44d8` (head `703f534`, CI green).

32. **Inventory programme (owner, 2026-11-11):** "ill agree to add those but no need to wait for accountant, just do
    it until done." and "so a until g will be build right? u have my approval". The team builds STK-12 to STK-36
    slice by slice (section 9). Valuation figures are labelled "Management report — cost policy to be confirmed by
    the clinic's accountant" (*until REL-STK, decision 49, 25 Nov 2026*); the pharmacist review does not block building; each slice still needs its new
    permissions approved, Daniel's look for new screens, and green CI.
33. Programme choices (owner, "agree all", 2026-11-11, and later answers): only a Director or Finance Officer sees
    inventory cost (STK-13); a Finance Officer also approves purchase orders, never one they raised or submitted
    (STK-19); a CA Supervisor or Finance Officer records a supplier invoice and a Finance Officer or Director, never
    the recorder, decides a mismatch (STK-22); the controlled-drug register is viewed and checked by a CA
    Supervisor, Finance Officer or Director, with a second-person check (STK-24); purchase requests by a CA or CA
    Supervisor ("agree purchase requests", 2026-11-12, STK-18); a Director starts a recall and a Director or Finance
    Officer who did not start it lifts it ("recall A", 2026-11-12, STK-25).
34. Always brought to the owner: go-live, real data, legal, PDPA, tax, new permissions or modules, money or
    stock policy, deleting data, and anything that costs money. The owner's own words in a team session always
    win. (The delegation note recorded here on 2026-11-12 was removed on the owner's decision of 2026-11-13, "1b".)
35. Merging (B.E.R.N.I.S.S, 2026-11-12): she merges team PRs in batch PRs and may refresh a PR's head herself. The
    team does not push to a PR while it is in a batch, except for a tweak she asked for.
36. STK-31 (B.E.R.N.I.S.S for the owner, 2026-11-12): option (A), push from the branch that holds the stock. A stock
    request may point at an active location of another branch when the stock comes from a branch; only the
    destination branch receives; a cross-branch line is capped at what the source can spare; the page is visible
    with `inventory.view.branch` (cross-branch on-hand and levels, no cost or patient data) and "Request these"
    needs `inventory.stock_requests.create.branch`.
37. Pilot documents (B.E.R.N.I.S.S for the owner, 2026-11-12): the stock admission procedure (STK-34) and the
    pharmacist review pack (STK-36) stay DRAFT, say they are not legal or regulatory advice, quote only screens on
    `main`, and leave responsible-person names and retention years blank for the clinic.
38. STK-37 (B.E.R.N.I.S.S for the owner, 2026-11-12): moving held, recalled or expired stock is a safety gap, not a
    policy question. Dispatch always needs a usable batch; a direct transfer needs one except between two returns
    locations and within one branch into a location that is neither a dispensary nor returns. Stock in an
    organisation store has no branch hold. The FEFO trail and a short-dated warning wait for the pharmacist's
    answers (pack questions B6.6 and B6.7).
39. STK-33 (B.E.R.N.I.S.S for the owner, 2026-11-12): Stock health is read-only, quantities only, with
    `inventory.view.branch` and no cost; the Reports team (Farah) was told. STK-32 (seasonal forecast) is parked
    until there is real history.
40. Approved changes to existing tests (B.E.R.N.I.S.S for the owner, 2026-11-12): the STK-35 readiness pin
    ('No import' → 'No balance correction'); the STK-33 Inventory task link pin (adds '/inventory/stock-health');
    and, in STK-21, `SupplierInvoiceTest` closing its part-received order through the real short close instead of
    raw SQL (setup only).
41. Approved changes to existing tests (B.E.R.N.I.S.S for the owner, 2026-11-12): in DD-75, the DD-12 task link pin
    expects each link twice (`[...grouped, ...grouped]`), once in the desktop groups and once in the phone groups.
    The compact tiles (#633, Daniel's OK) changed no existing assertion; they add one test.
42. STK-40 blind count (owner, "blind count yes", 2026-11-15; option A approved by B.E.R.N.I.S.S for the owner):
    while someone may check a controlled register now, the server sends no figure that gives its balance away (the
    list's on hand, the register's balances, in and out, earlier checks' quantities) and the print waits. Everything
    comes back once they have checked, or whenever a rule blocks them; their check then shows the expected balance and
    the difference. The checker rules, locks and audit are unchanged; a count that differs is still refused until a
    reason is chosen, which tells the checker only that it differs. Known limit, accepted: other stock pages still
    show levels; a determined checker could look there.
43. STK-40 follow-ups (B.E.R.N.I.S.S for the owner, 2026-11-15): each refused "differs" try (no reason, or "other"
    with no note) writes one audit row, `inventory.controlled_register.check_refused`, with ids and the code only,
    after the check transaction has rolled back. Approved changes to existing tests: in `ControlledRegisterTest` the
    list's first row expects `[null, true, 'never', null]` (balance, blind, status, last checked) plus two lines for
    the recorder, and three tests view as the supervisor who recorded the movements (`$f['proposer']`), assertions
    unchanged; in `v2-inventory-controlled-register.test.mjs` the intro regex drops "since the last check." and a new
    assertion pins the blind sentence. A new test shows a Finance Officer and a Director get the full register after
    their own check. Each check also shows "N earlier attempts" (refused tries since the previous check, read from
    those audit rows; nothing when there were none). Option (b), never saying "differs" before recording, is an open
    owner question.
44. STK-40b (owner, 16 Nov 2026: "go with option b for blind count"): option (b) chosen. Record check always records
    what was counted, so KPOne never says "differs" before the count is saved. A check that differs is recorded with
    no reason, and the person who recorded it adds the reason afterwards, once, in an append-only step
    (`inventory_controlled_register_check_reasons`; PostgreSQL refuses anyone else, a second reason, a check that
    matches, and any change). Until then the medicine shows "Difference: reason needed" in words, on the list, the
    register and the print. The owner decided: no second person on the reason; a reason still needed never blocks the
    next check; `check_refused` is no longer written, older rows stay readable so checks recorded before STK-40b keep
    "N earlier attempts", and the live "since the last check" line is retired. The checker rules (second person, not
    the previous checker) and the locks are unchanged; a new audit event, `inventory.controlled_register.check_explained`,
    holds ids and the reason code only. The owner approved the changed assertions, listed before and after in the PR.
    Daniel's look (OK with two changes): while "Add the reason" shows, Record check is an outline button, so only one
    filled button is on screen; and a later checker sees "Check N differs from the register and still waits for its
    reason from the person who recorded it. You can still record your check." The owner then decided (16 Nov, his
    own choice): **only the recorder adds the reason, for now**; a missing reason keeps showing "Difference: reason
    needed" to everyone, and later checks still go ahead.
45. DEMO-2 (owner, 16 Nov 2026: "build the inventory walk seeder", for Kavitha's Malay CA Supervisor guide):
    `KPOneInventoryWalkSeeder`, run after DEMO-1 on a disposable database, adds a Cheras store, two fictional
    suppliers, a controlled medicine with stock, a second, earlier-expiring Paracetamol batch, and one approved
    purchase order waiting to be received, all through the domain services as demo accounts (recorder and
    verifier, raiser and approver kept apart). Local and testing only; `.test` accounts only; reruns add nothing.
    Commands and details in `docs/DEVELOPMENT.md`.
46. STK-41 (owner, 18 Nov 2026, "Approve prepares real work"; plan agreed with Irfan and approved by B.E.R.N.I.S.S for the
    owner): approving Hana's transfer suggestion may prepare a governed stock request, and a restock suggestion a purchase
    request, through `InventoryProposalPreparer` (the agent platform's `PreparesWork`, behind `agents.prepare_enabled`,
    OFF by default). The record is made by the normal service as the approver (`createFromProposal`, `sendFromProposal`),
    so every permission, branch, lock, spare-stock and audit rule applies; the audit row gains `source: ai_proposal` and
    the proposal id, and `ai_proposal_id` on the request is a trail only (unique, never changed). Owner decisions: a
    Finance Officer or Director decides these types as a decision only (preparing needs the create permission, so in
    practice a CA Supervisor); an agent-prepared stock request is never approved by its requester (service and
    PostgreSQL); the approver may lower the quantity and change the locations. A transfer is prepared only at the branch
    that sends the stock (STK-31); a restock is refused when the live Stock review row shows stock back above the level,
    and the inbox may warn when an open purchase request or order already holds the item for that location.
47. Purchasing order and answers (owner, 22 Nov 2026, relayed by B.E.R.N.I.S.S: "order ok, (a) Finance only, (b) existing
    warehouse, (c) warn"): build in this order: STK-44 medicine sales, STK-45 report a difference, STK-50 Director-only
    purchase order approval, STK-51 Finance-only supplier bill acceptance, STK-46 hand-typed cost, STK-49 payment terms
    and due dates with "paid in Bukku", STK-48 RFQ, STK-47 central store. (a) Only Finance accepts supplier bills, so the
    Director loses accept (a revoke; the existing assertions go to the owner first). (b) The Cheras central store is the
    existing organisation warehouse. (c) The Thursday request day warns only, never blocks.
48. STK-44 medicine sales (owner decision 26; P9 permission `inventory.medicine_sales.view.organisation`, Director and
    Finance Officer only, separate from cost view): rank by Sales or Gross profit only, never by units, because units
    can't be compared across medicines (B.E.R.N.I.S.S for the owner, 23 Nov). A line charged RM 0.00 is a "free line",
    not sold below cost, and its cost still counts. The link sits on the Inventory home, not in Clinic management; the
    phone count becomes `reportsCount` (owner-approved change to `dd-12-inventory-headers.test.mjs`, 23 Nov).
49. REL-STK cost policy approved (owner, 25 Nov 2026, relayed by B.E.R.N.I.S.S: "Lulus + stok pembukaan juga"): the
    clinic's accountant approved the weighted average cost policy verbally, as built in STK-27 (rules in
    `docs/teams/inventory/REL-STK.md`), and opening stock at its entered unit cost. The disclaimer becomes "Weighted
    average cost, as approved verbally by the clinic's accountant on 25 Nov 2026; not a statutory financial
    statement."; the banner drops "cost policy to be confirmed by the clinic's accountant"; the journal account
    "Opening stock (to be confirmed by accountant)" becomes "Opening stock"; the `AGENTS.md` inventory clause is
    reworded in the same PR. Two owner-approved existing assertion changes (`StockJournalSummaryTest.php:133`,
    `stk35-opening-import.test.mjs:11`). No change to how cost is worked out, who sees it, or any stored figure.
    When the accountant's approval is written down, REL-STK.md can point to it.
50. STK-45 Report a difference (owner, 25 Nov 2026, relayed by B.E.R.N.I.S.S: "Lulus nota + kebenaran", then
    "Lulus + ship it"): note `docs/teams/inventory/STK-45-DESIGN.md` revision 4; P10 permission
    `inventory.differences.report.branch` for CA, CA Supervisor, Store Admin, Branch Manager and Medical Assistant. A
    report opens the governed STK-5 count or joins the newest live one and never moves stock; the reporter never counts,
    recounts or approves that case (service and PostgreSQL: STK-5's `guard_governed_stock_action` gains the reporter
    exclusion, and `down()` restores the STK-5 body). Owner-approved existing assertion changes: one line each in
    `RoleOneRolesTest::tables()` (branch_manager, store_admin) and `MedicalAssistantRoleTest::EXPECTED`.
51. IMP-1 catalogue import (owner, 25 Nov 2026, relayed by B.E.R.N.I.S.S, for the Puchong live test): a one-off console
    command, `inventory:import-catalogue {catalogue} --branch-prices= --actor= --branch= --dry-run`, run by the
    coordinator. It creates medicines, SKUs (stock unit = dispensing unit, conversion 1), HQ Self-pay prices, branch
    Self-pay prices and the controlled flag through the existing services (UnifiedCatalogueSetupService,
    MedicineAdministrationService, PricingReferenceAdministrationService, PricePublicationService) as one named actor,
    in one transaction: both files are checked first and refused with every problem listed, then everything is written
    or nothing. `--dry-run` does every write and rolls it back. An existing medicine code, inventory item code, SKU code
    or barcode is refused, so a second run changes nothing. Branch prices only for medicines in the same file; the actor
    must be assigned to the home branch and every priced branch; an inactive branch book is refused, never switched
    back on, and every charge is new (Mei Ling's review). No new permission, screen or migration; one audit row
    `inventory.catalogue_import.recorded` with counts only. The actor must hold all four of
    `medicines.manage.organisation`, `prices.publish.organisation`, `pricing.references.manage.organisation` and
    `inventory.references.manage.organisation` (on main: Administrator and CA Supervisor; the Director lacks
    `prices.publish`); the owner chose a named Administrator. Panel prices (owner, 25 Nov 2026): `hq_panel_rm` is never
    published; each value is filed through PanelPriceProposalService as a proposal for the default Panel book, as a
    named Panel Officer (`--panel-proposer`, `--panel-reference`, today's date), and a Director or Finance Officer
    approves it (decision 27 unchanged). Opening stock and its unit cost stay with the STK-35 opening import and its STK-1 sign-off.

## 7. Open items and known limits

- **Recall lifting with one Director (owner, 16 Nov 2026: "note it, decide before go-live"):** only a Director
  holds `inventory.recalls.lift.organisation`, and the Director who started a recall can never lift it (STK-25:
  service and PostgreSQL). With one Director, a recall started in the pilot cannot be lifted. Options to decide
  before go-live: (a) a second Director account; or (b) let a Finance Officer lift a recall a Director started,
  which grants a new permission and needs the owner's approval. Found while checking Kavitha's guide (#906); the
  guide says it plainly.
- **STK-10 valuation:** built as STK-27 to STK-29; policy approved verbally by the accountant (decision 49). STK-10B (cost evidence) is merged; its
  limits: opening balances still take a whole-sen cost per stock unit with no cost source; the legacy goods
  receipt line keeps only `unit_cost_sen`; Ask KPOne does not show `cost_total_sen` or `cost_source` until the
  Agent platform's AG-7b. Label batch and expiry is the Dispensary
  team's DSP-2 (merged).
- **Parked review (2026-10-21):** supplier invoice match (STK-22), the printable purchase order (STK-20) and reorder
  suggestions (STK-16, STK-30) are now built. `InventoryController::referenceData()` is bounded: since the pilot scale
  limits (PR #176) each list comes from `InventoryPickerService::choices()`, 25 rows by default and 100 at most, with
  search for the rest (checked 2026-11-15; this line said "unbounded" until then).
- **Found while writing the pharmacist pack (STK-36):** the transfer gaps are closed by STK-37 (#624). Still open:
  the FEFO audit entry records that an exception was chosen, not which reason,
  and a re-save before completion loses the earlier reason; no report lists FEFO exceptions; no short-dated
  warning at Dispensary. Each waits for the pharmacist's answers and the owner.
- **STK-40 blind count limits:** other stock pages (Inventory home, stock movement report, reorder list) still show
  levels; a determined checker could look there. Guessing is closed by STK-40b: every count is recorded, so nothing is
  learnt before it is saved.
- **STK-40b limits:** only the person who recorded a differing check may add its reason, so if they leave, the check
  stays "Difference: reason needed" (no fallback person; the owner decided on 16 Nov that only the recorder adds the
  reason for now).
- **Pilot staffing:** the controlled-register checker cannot be anyone who recorded a movement on that register
  since the last check, so a controlled-delivery day needs a third person (raised with the owner, 2026-11-12).
- STK-31 limits: the cross-branch guard is insert-only; stock request creation takes an optional retry key since
  STK-39 (a double press or retry returns the request already made); one low-stock query per other branch.
- STK-37 limits (Mei Ling, low risk, the same as for dispensing): stock in transit is not re-checked on receipt, so
  a batch recalled or held while in transit still lands (dispensing refuses it there); a recall locks only the
  chosen SKU's batches, so a sibling SKU's batch with the same number is not serialised against it; item
  deactivation and expiry edits do not take the batch lock. Direct transfer has no screen today.
- STK-35 limits: the organisation lock is held while a large import records (up to 500 rows); an import racing a single opening
  is covered by a two-process test since STK-38 (`PostgresOpeningImportTest`: one opening, the other refused); `crypto.randomUUID()` needs a secure context; a held existing batch is
  reused without the row saying so. The file is never stored, only its SHA-256.
- STK-33 limits: about 8 queries per page and the whole branch loaded before slicing, not load-tested; an index on
  `(source_location_id, occurred_at)` and `(destination_location_id, occurred_at)` may be needed later; "used"
  leaves out a treatment usage reversed after the period, as Reorder suggestions and Insights do.
- STK-21 limits: a short close cannot be undone (raise a new order for the rest); the printed purchase order does
  not show a short close.
- **Professional questions** for a pharmacist or the regulator: regulated items, registers, FEFO policy,
  retention. They are collected in `docs/pilot/controlled-register/REVIEW.md` and
  `docs/pilot/inventory/PHARMACIST-REVIEW-PACK.md` (STK-36).
- Quarantine holds the whole batch at that branch; corrections reverse a whole untouched receipt; a wrong
  item is rejected, never substituted.
- Privileged direct SQL is outside the ledger guard; no PostgreSQL guard was added in STK-6A. STK-6 adds one
  narrow guard: a new `transfer_receipt` movement must have receipt-line or resolution evidence.
- STK-6 limits: a difference is resolved whole, once (no partial resolution); extra stock never enters stock
  through a transfer (keep it via a governed stock count); an item that arrived but was never on the dispatch
  is not recorded here; receiving without lines still means "everything arrived"; direct one-step transfers
  within a branch have no in-transit stage and are unchanged; a warehouse destination needs the existing
  warehouse permission to receive or resolve.
- STK-7 limits: one return covers part or all of one receipt lot (several lots need several returns); only
  lots from a verified, uncorrected receipt at the branch's own receiving location can be returned; a credit
  note is a reference and an optional amount only (no payment, invoice match or supplier balance); once
  dispatched a return cannot be undone in KPOne (a supplier that sends stock back is received as a new
  delivery); the Stock movement summary shows supplier returns under "Adjusted" (report owned by the Reports
  team); returning stock never releases a batch hold.
- STK-8 limits: one entry is one batch at one location; expired, held or unavailable batches are refused for
  use and waste (use the governed Write-off); a reversal is refused once a governed count or write-off has
  posted for that batch and location; entries are not linked to a patient or visit and create no bill; no kits
  or bundles; the treatment room is optional and only a label; a reversal returns the whole entry (no partial
  reversal) and an entry can be reversed once; the Stock movement summary shows usage, waste and their reversals
  under "Adjusted" (report owned by the Reports team).
- STK-9 limits: a scan only finds a SKU (or highlights a receiving line); it never ticks, fills or posts anything,
  and the batch is still picked by hand; supplier GS1 batch and expiry data in a barcode is not read; camera
  scanning works only where the browser has a built-in barcode reader and on a secure (https) page, so iPhone and
  iPad use a scanner or typing; a legacy shared code is refused by the lookup until it is fixed on Pilot readiness
  (reference managers only); a typed exact SKU code also matches; dispensing has no scanning yet (Dispensary team);
  there is still no general SKU edit screen in Stock setup.
- STK-11 limits: nothing writes `dispensary_return` until DSP-7 merges; the pairing of each such movement with its
  check row is Dispensary's deferred trigger (in DSP-7's migration); expired returned stock stays on the STK-4
  "expired on hand" list, on purpose, because it still has to be written off; a two-stage transfer never touches a
  returns location; there is no page that lists returns stock by itself (it shows in the stock view and STK-5).
- Debt (Daniel, V2 page 5 review): supplier-return server messages print quantities as "6.000 unit" (three decimals,
  singular unit), the Classic wording. The V2 page shows them as "6 units" in the browser; the server text and the
  Classic page are unchanged. A server fix would change existing assertions, so it waits for its own approval.
- **STK-42 limits (Mei Ling's code review):** for a cost viewer, every Stock review load (including searching or
  paging the other lists) reads every at-risk row and replays the stock history of those SKUs to value them. Fine at
  clinic scale; above `AT_RISK_VALUE_SORT_LIMIT` (2,000 rows) the list falls back to expiry order and says so. Hana
  caps a SKU's own alert days at 90, so a SKU set above 90 can be expiring on the page before the analyst sees it.
  Patient-returns stock is left out of the at-risk list by the same rule as "Expiring soon" (tested by STK-11), not by
  a test of its own.

## 8. Rules (every task)

- Read `CLAUDE.md`, `AGENTS.md`, `docs/BASELINE.md`, `docs/reports/ANALYSIS-STOCK.md` and this file first.
- Never open, print or edit `.env`. Synthetic `.test` data only.
- Every state change: backend permission and branch scope, `DB::transaction`, row locks, `lock_version`,
  idempotency where retried, and a structural audit row with identifiers only.
- Every stock movement goes through the ledger writer allowlist; never write balances or movements directly.
- Exact thousandth arithmetic through `StockUnitQuantity`; refuse negative stock and overflow.
- Independent-person rules are never weakened without an owner decision recorded in section 6.
- Any permission granted to `ca` is also granted to `medical_assistant` (MA-1a); a new grant names both.
- Do not change any existing test assertion without the owner's approval.
- Full gate before hand-off on a task-owned PostgreSQL 18 database, including every inventory contention
  test with none skipped; screenshots at 1440 and 390 px, light and dark, for any screen change.
- Screen changes: Wei Jie sketches the layout before building and checks the 390 and 1440 px light and dark
  screenshots against `docs/design/CHECKLIST.md` before hand-off; new pages and layout changes also go to Daniel
  through B.E.R.N.I.S.S (section 6 item 25).
- Backup push to the piece's own feature branch whenever stopping to wait for the owner (section 6 item 26).
- Open the PR only after the owner says "ship it" (directly, or relayed by B.E.R.N.I.S.S (formerly LUCY), the coordinator
  session; see section 6 item 16); never merge. If a relayed message conflicts with something the owner said
  directly, stop and ask the owner.
- Record every new owner decision in section 6 and every finished piece in section 5, in the same PR.
- Do not edit `docs/BASELINE.md` in a team PR; the coordinator records delivery status there. If a PR changes
  an authorisation boundary (a permission, a role grant, an owner decision), say so in the PR description.

## 9. Backlog (next pieces, in order)

Chosen by the owner on 2026-11-05; built one by one in this order, each its own PR. Choosing them is the owner's
authorisation to build them; the coordinator records it in `docs/BASELINE.md`. Each follows the
matching proposal in `docs/reports/ANALYSIS-STOCK.md` section 8 unless an owner decision below says otherwise.

1. **STK-6 Transfer shortages.** *Merged in PR #217.* The receiving branch records actual receipt lines (accepted, short, damaged,
   extra); dispatch and receipt reconcile without forcing a full receipt; a different person resolves each
   difference, append-only. **Owner decision 2026-11-05: resolvers are the write-off approvers (CA Supervisor,
   Finance Officer, Director), never the person who received the transfer.**
2. **STK-7 Supplier returns.** *Merged in PR #233.* Return request, approval and dispatch tied to the receipt and batch, with a
   credit-note reference; no payment engine; Finance sees commercial references without patient data. **Owner
   decision 2026-11-05: a CA Supervisor requests; a Finance Officer or Director approves, never the requester.**
3. **STK-8 Treatment-room usage.** *Merged in PR #244.* Dedicated non-dispensed usage and waste records and movements (not hidden
   in adjustments); no billing bundles; not linked to a patient unless separately authorised. **Owner decision
   2026-11-05: CA and CA Supervisor record usage; every entry audited and shown in stock reports.**
4. **STK-9 Barcode scanning.** *Merged in PR #253.* Scan a known code to pick the item and batch when receiving, counting or
   dispensing; codes map locally; no external lookup; scanning never authorises an action by itself. Label
   batch and expiry printing belongs to the Dispensary team's DSP-2.
5. **STK-11 Patient returns location (for DSP-7).** *Merged in PR #279.*
6. **STK-10 Valuation.** *Analysis merged in PR #289; option B (STK-10B) merged in PR #309 (`a1a44d8`), on
   the owner's "yes, do option B" (section 6 items 29 to 31).* Valuation was later built on draft defaults (item 7
   below) and the accountant approved the policy verbally on 25 Nov 2026 (decision 49, REL-STK). *Analysis only (owner request 2026-11-05):* `docs/reports/ANALYSIS-STK-10.md` lists what
   KPOne records about cost today, the gaps, and the questions an accountant must answer. Nothing is built until
   the owner chooses an option and, for a valuation or export, the accountant's written policy exists.
7. **The 2026-11-11 programme (section 6 item 32), status on 2026-11-12:**
   - Merged: STK-12 to STK-26, STK-30, STK-30b, STK-31, STK-33 to STK-37, STK-15b and STK-15c (section 5).
     Design follow-ups merged: DD-75 and DD-76 (#629) and the compact summary tiles (#633).
     STK-37 was added on 2026-11-12 from a gap found while writing STK-36.
   - Unparked on 2026-11-15: the owner has no accountant or clinic policy ("just do first ... act like one"), so
     B.E.R.N.I.S.S set draft defaults as the accountant. STK-27 (weighted average cost, the read-only engine
     `WeightedAverageCostService`, gap defaults a to f) is built; STK-28 (stock value, COGS, margin and RM losses,
     with a "supplier credit difference" line and the new permission `inventory.valuation.view.organisation` for the
     Director, to be approved) and STK-29 (monthly journal summary CSV) follow. Every figure said "Set by KPOne
     defaults at the owner's instruction; not professional accounting advice." until REL-STK (decision 49).
   - STK-28 merged (#829): the permission is the owner's decision (Director only, with margin). STK-29 (this PR): the
     monthly journal summary CSV on the Stock value page, one calendar month, plain journal lines with debit and
     credit columns, a check line that must be exactly 0.00 (an unbalanced journal is refused), and one audit row per
     download. The supplier credit difference sits between Stock losses and Accounts payable (a credit note does not
     move the average); All branches counts stock in transit, so transfers net to zero (B.E.R.N.I.S.S for the owner).
     For the accountant's review (Daniel, readability, not built): a header line "Lines are dated at the end of the
     period shown."; and the memo amounts (opening, closing, check) in their own "Memo (RM)" column or block rather
     than in the Note column.
   - Parked until there is real usage history: STK-32 (seasonal usage forecast).

### Batch safety holds composition — 26 November 2026

Scoped follow-on to Count case worklist: responsive real branch batch worklist, separate global Batch status and Branch hold, unchanged authorized Place/Release slots, server search/page projection and safety context. No invented location/quantity/held total. Existing mutation/reason/version/retry handlers are unchanged; dialog close returns to the original surviving action/row/list without crossing branch or stealing outside focus. Six new runtime cases cover row semantics and focus. D010 harness now renders the extracted real child; all existing assertions remain unchanged, independently reviewed under delegated technical authority.

Validation: 2,245 frontend tests, types/lint/format/build, and 19 backend tests /203 assertions passed. Actual synthetic three-actor UI proved Place, independent Release, stale-version rejection with dialog retained, and successful focus return to row/action. Four viewport/theme captures have no overflow/errors and owned controls meet44px mobile/36px desktop. Stock movements and all balance quantities were identical before/after. Final independent source/visual review and scoped draft publication recorded in the review artifact; no production action or merge.

### Write-off case worklist — 26 November 2026

Scoped follow-on to Batch safety holds: write-off mode reuses the tested local case disclosure primitive with a separate accessible label and exact requested quantity/unit/reason summary. Zero remains zero; missing quantity is Unavailable. Count defaults and the disabled primitive path remain supported. Original detail/history/authority slots and the entire GovernedStock script remain unchanged except contextual reason wording: governed reversal correction reads Correction rather than the receipt-specific shared label. Approval/reversal endpoint, balance/case versions and retry identity remain unchanged. No backend/grant/schema change.

Six additive runtime tests cover contextual reversal wording, actual page summaries/denials, zero/null, blind count regression and original approval/reversal dialog targets under projection replacement. Existing assertions remain intact. Full2,251 frontend tests, types/lint/format/build and existing backend30tests548assertions passed. Final actual synthetic browser evidence and independent frozen review are recorded in stock-writeoff-cases validation artifacts. Multi-line cases, Returned workflow and proposer display metadata remain separate full-demo contract gaps; no such data or commands were fabricated.

### Verified receiving composition and refused-draft preservation — 26 November 2026

Existing split-lot form now groups real order/delivery context, independently supplied ordered/received/outstanding quantities, physical count and numbered outcome-lot cards with contextual guidance. No invented received-at/by, central-store routing, delivery-wide cost or new mandatory checkbox. Manual Add/Remove restores lot-input focus; scanner continues to highlight/hint only. A demonstrated scalar-watcher correction preserves draft/errors/idempotency keys for unchanged branch/order/version response props; real context changes reset current branch/version/nonce/lines. Original receiving/cost/scanner command semantics remain.

Full2,257 frontend/type/lint/format/build and backendVerifiedReceiving/ReceiptCostEvidence23tests301assertions pass;31focused tests pass. Five exact source-pin adaptations independently reviewed under delegation retain all behavior/permission/raw-value guards. Actual synthetic UI verifies scanner focus/no auto-fill, Add/Remove focus, accepted/quarantined/rejected lots, refused receipt with complete draft retained, self-approval absent, independent Director exception approval and one consumed receipt. SQL records accepted1/quarantined1 movements, rejected0movement/nullcost, one quarantine hold and partial order received2; real actual costs125sen/0sen retained. Six390/768/1440lightdark target matrices44/36 with no overflow/errors. Frozen final source/visual review and draft publication recorded in stock-verified-receiving artifacts; no production or merge.

Receiving final layout follow-up uses an actual container-width breakpoint so the existing narrow desktop parent cannot squeeze the lot fields with a side rail. Final layout-only scoped36tests/file static/build7.97s passed after the earlier full2,257/type/backend gates; final390/768/1440 captures record actual widths. Full suite was not repeated for this two-class layout correction.

### PO optional delivery metadata — delegated continuation,26 November2026

Coordinator B.E.R.N.I.S.S approved exact additive migration76d874383747b49f34bc94e87a9ca90e63f57ae6899a4236a224e4adb0ac96c7 under the owner's technical delegation, not a new owner reply. Optional calendar expected-delivery date and bounded supplier reference use existing draft create/update authority and version. Omitted updates preserve, explicit blank clears; no backfill, lead-time inference, new grant, pack conversion or approval transition. Independent changed-metadata UPDATE guard requires draft→draft/version+1; original approval/short-close guards unchanged; no INSERT restriction; no-op down preserves history. Audit contains field names only, never metadata values.

Create/edit/read/print bind actual saved fields; null is Not specified, date-only formatting avoids timezone shift. Same-identity UI refresh retains draft/error, genuine version/branch resets metadata, absent legacy DTO fields are omitted until edited. Initial40 backend tests426 assertions and new actualSFC3 pass; invalid year-zero date additionally rejected with final7tests73assertions. Final full frontend/browser and independent frozen review tracked in `/workspace/kpone-validation/stock-po-metadata`; no production execution or full-demo claim. Existing migration and permission approvals remain distinct from this delegated technical continuation.

Final bounded evidence: full2260frontend/types/resourceformat/build pass; fullESLint found nine owned formatting issues, repaired with scoped lint pass; Pint and PHPStan0. Actual own3162 create/edit/stale-refusal/submit/independent approval/CA no-price print and explicit-clear UI pass. Approved order version5 keeps3Dec2026/reference, creator/submitter5 and approver7; no stock effect (24movements unchanged, all balances identical). New controls measured44mobile/tablet/36desktop across390/768/1440lightdark; final synthetic logo file restored from original preview storage before print capture. Final frozen independent review/publication recorded in external packet. Existing narrower PO line-layout controls are outside this metadata slice; no full PO/demo parity claim.

### Unified PO worklist composition — delegated continuation, 27 November 2026

Purchase orders now uses a responsive named worklist over the existing newest50 scoped projection. Search/status/counts explicitly describe loaded orders; exact supplier/destination, line count and real status remain distinct. Persistent keyed details preserve edit/receiving drafts. Existing parent commands, authority, versions, retry keys, reviews and confirmation target are retained. Optional receiving processing events use per-instance tokens so old unmount cleanup cannot unlock a replacement child; no backend/query/schema/grant change.

Nine new runtime cases plus six receiving regressions pass, including draft/filter/busy ownership, focus/SSR and immutable confirmation target. Independently reviewed old pins change only heading/layout/whitespace; original authority/accessibility/monetary checks remain. Full2269frontend, types/lint/resourceformat/build and backend12tests92assertions pass. Actual own3178 synthetic create/edit/submit/independent approval and refused receipt prove child busy lock, retained error/draft and read-only action absence. Six390/768/1440lightdark worklist matrices show no overflow/errors,44mobile/tablet36desktop owned targets. All balances and24movement rows unchanged. Full-dataset navigation/counts, supplier-sent/Returned lifecycle and pack/lead-time semantics remain separate contracts. Final independent artifact review and scoped draft publication recorded externally; no production or merge.

### Supplier invoice match worklist — delegated continuation, 27 November 2026

Existing server-paginated invoice statuses/counts now use a responsive invoice/order/authorized-total/status worklist with persistent match details. Quantity-only viewers see Not shown for summary totals and retain original quantity-only line checks; hidden raw totals never reach the summary formatter. Existing page command script is import-only changed. Original recorder exclusion, independent decision dialog target/version, fixed reasons and whole-sen semantics remain. Local status/pager/disclosures lock during pending commands; owned pager controls36desktop44mobile. No backend/query/schema/grant/payment change.

Seven new runtime tests independently pass, including hidden totals, zero/undefined, mounted detail, busy/context/focus and original decision version. Exact old recorder sentence whitespace pin independently cleared; no semantic assertion removed. Full2276frontend, types/lint/resourceformat/build and backendSupplierInvoice10tests69assertions pass. Actual own3182 Finance record→waiting/no-self-decision, CA quantity-only, separate Director accept and stale Director reject refusal pass; basicCA authority-empty view remains. Six390/768/1440lightdark matrices36/44 with nooverflow/errors. All stock balances and24movements unchanged; accepted invoice version2 recorder7 decider2. Final frozen independent review/publication tracked externally; due/paid metadata and broader Stock navigation remain separate contracts. No production or merge.


### Supplier return Show filter — delegated continuation, 27 November 2026

All/Open/Done filters reuse exact latest states for individual returns and the existing first-member latest-state projection for grouped requests. Open includes awaiting approval, approved and dispatched; Done includes credited, rejected and cancelled, with explicit credit-reference—not payment—guidance. Existing page sizes remain 25 individuals and 10 groups, with separate filtered totals; text search remains individual-only. Changing Show resets both request pages, preserves lot/delivery context and refuses navigation during either mutation. Failed GET leaves loaded selection intact. No mutation, quantity, authority, schema or grant change.

New backend and runtime coverage plus existing regressions pass: 28 backend tests/338 assertions, 24 focused frontend and all 2,279 frontend tests, types, scoped lint/Pint/PHPStan, resources formatting and build. Actual own synthetic browser verifies All31/16, Open28/13, group page2, Done3/3 and page resets, individual-only no-match search and injected network refusal. Six390/768/1440 light/dark views measure Show44/36 with no overflow. The single recorded network error is deliberately injected and retained in evidence. After fixture setup, all balances,28 movements and63 actions remain unchanged across read workflows. Independent frozen review/publication tracked in stock-supplier-return-status artifacts; broader return worklist composition remains separate.
