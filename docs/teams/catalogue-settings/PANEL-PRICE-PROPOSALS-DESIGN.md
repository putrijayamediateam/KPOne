# Design note: Panel price changes by proposal (owner decision 27, 20-21 Nov 2026)

Status: **draft for Ravi's review and Daniel's look; the owner answered a) to d) and approved both permissions on 21 Nov (section 10); no code until his ship-it.**
Owner of the slice: Adlina (Catalogue & settings). Panel side reviewed by Syafiq. Classic layout only (V2 is retired).
Source: `docs/decisions/2026-11-20-finance-inventory-proposals.md` item 27: "the Panel Officer proposes with the
agreement reference and an effective date; Finance approves before it applies; it applies to bills created on or after
the effective date; history kept."

## 1. What exists today (checked in code)

- Every price write goes through `PricePublicationService::publish()` (`prices.publish.organisation`: CA Supervisor,
  Finance Officer, Administrator). Four callers: the Pricing page, the Medicine and Clinical Service forms (default
  Panel price and per-Panel overrides, via `UnifiedCatalogueSetupService::publishPrices`) and
  `ConsultationTariffAdministrationService`.
- `PriceEntry` is append-only and versioned per (price book, charge). `publish()` stamps `effective_at = now()`.
- Billing (`PriceResolutionService`) reads entries with `effective_at <= now()` and, **within a book, the highest
  version wins**. Invoice lines keep the entry they were billed with, so a new price never changes an existing bill.
- Panel books: `price_tier = 'panel'`, `branch_id` null; `panel_id` null is the default Panel book, otherwise one Panel's book.
- Panel Officer holds no pricing permission today (only `catalogue.settings.manage.organisation`).

## 2. Rules (assuming the defaults; see section 9 for what the owner may change)

1. Only Panel price books are covered. Self-pay publishing is unchanged (default a).
2. `publish()` refuses a Panel book with "Panel prices are changed by proposal." (closed only in PR 3, section 8).
3. A **proposal** names: Panel price book, charge, proposed price (whole sen, 0 to the existing maximum), agreement
   reference (required, plain text, 1 to 120 characters, collapsed whitespace, never in audit or logs), effective date
   and time (entered as a branch-local date, stored UTC; today or later at proposal time), and the base version (the
   highest version of that book and charge when the proposal was made, 0 if none).
4. **One open proposal per (book, charge).** A second is refused with the first one's status shown.
5. **Approve** (Finance Officer or Director, not the proposer): under locks and a fresh permission check, if the base version is
   still the current highest version, write the `PriceEntry`. Otherwise refuse: "The price changed after this was
   proposed. Propose it again."
6. **Effective time at approval:** the entry takes `effective_at = max(proposed effective time, approval time)` (default
   b). It is never earlier than the latest existing entry's `effective_at` for that book and charge (otherwise the
   highest-version rule and the effective order would disagree); if it would be, approval is refused with the reason
   and the proposer proposes again.
7. **Reject** (Finance Officer or Director, not the proposer): a controlled reason code (wrong amount, wrong Panel, no agreement
   seen, other) and an optional short note (encrypted, never audited). **Withdraw**: the proposer, while open.
8. A first-ever Panel price for a medicine or service is also a proposal (default c). Until approved, billing falls
   back to the default Panel book, as it does today.
9. History is kept: proposals and their events are never deleted; the approved proposal points at its `PriceEntry`.

## 3. Data model (migrations in my range, from `2026_11_09_000400`)

`price_change_proposals`: `id`, `public_id` (uuid), `organisation_id`, `price_book_id`, `charge_definition_id`,
`proposed_unit_price_sen` (bigint, 0..999999999999), `agreement_reference` (varchar 120), `effective_at` (timestamp),
`base_version` (int >= 0), `status` (`proposed`, `approved`, `rejected`, `withdrawn`), `proposed_by_user_id`,
`decided_by_user_id` (null while open), `decided_at`, `reason_code` (null unless rejected), `decision_note` (text,
encrypted by the model cast, null), `price_entry_id` (null unless approved), `lock_version`, `idempotency_key`
(unique per organisation and proposer), `payload_hash`, timestamps.
`price_change_proposal_events` (append-only): proposal id, event (`proposed`, `approved`, `rejected`, `withdrawn`),
actor, occurred_at, ids only.

PostgreSQL checks and triggers (existing repo patterns for append-only evidence):
- the book must be `price_tier = 'panel'`, `branch_id` null, same organisation as the proposal and the charge;
- decided rows are frozen (status never leaves a terminal state; proposed values never change);
- `decided_by_user_id <> proposed_by_user_id` when set;
- `approved` requires `price_entry_id`, whose entry must be in the same book and charge with `unit_price_sen` equal to
  the proposal; the other terminal states forbid `price_entry_id`;
- a partial unique index: one `proposed` row per (price book, charge);
- no delete or truncate on either table.

## 4. Permissions (new; need the owner's approval)

- `panel.prices.propose.organisation`: Panel Officer. Also lets them read the Panel price books and their history
  (Panel books only; never Self-pay prices).
- `panel.prices.approve.organisation`: Finance Officer **and Director** (owner decision Q9 = B, 21 Nov; not the CA Supervisor). The approver is never the proposer, so a Director who proposed cannot approve their own.
- Additive grant migration (`firstOrCreate`, give only what is missing, never revoke), mirrored in `PermissionCatalogue`.
- No change to `prices.publish.organisation` or `pricing.references.manage.organisation`.
- Branch rule: Panel books are organisation-level, so these are organisation-scoped. As the other Pricing actions
  do, each action still resolves the actor's active effective branch and refuses without one (`TransactionalActorAuthority`).

## 5. Service: `App\Domain\Visit\Billing\Services\PanelPriceProposalService`

- `propose(actor, book, charge, amountSen, agreementReference, effectiveDate, expectedVersion, idempotencyKey)`.
- `approve(actor, proposal, expectedLockVersion)`; `reject(actor, proposal, reasonCode, note, expectedLockVersion)`;
  `withdraw(actor, proposal, expectedLockVersion)`.
- Each: fresh permission check under lock, `DB::transaction` with retries, row locks in one fixed order (charge, book,
  proposal), optimistic `lock_version`, idempotency key for propose, audit by ids and codes only
  (`panel_price.proposed|approved|rejected|withdrawn`; never the agreement reference, the note or the amounts of other books).
- `approve` calls a new `PricePublicationService::publishApproved(...)` that does the entry write and is the **only**
  way a Panel book gets an entry. `publish()` keeps the Self-pay path and refuses Panel books.
- Reads: a directory method for the Waiting and Decided lists (25 a page, newest first, names under the BIL-2
  staff-visibility rule) and the current price and history of one Panel book and charge.

## 6. Pages and routes (Classic only)

- One page `PanelPrices/Index.vue` at `/panel-price-changes`, a Clinic management row (Panel group), three tabs:
  **Propose** (Panel Officer: choose a Panel or the default Panel book, search a charge, shows the current price and
  last three versions, new price in RM, agreement reference, effective date with the branch time zone shown),
  **Waiting** (the list; Finance Officer and Director see Approve and Reject, the proposer sees Withdraw) and **Decided**.
- Approve and Reject use the shared confirm dialog; the page names the old and new price in RM and the effective time.
- Routes: `GET /panel-price-changes`, `POST` propose, approve, reject, withdraw; throttled like other Pricing writes;
  explicit redirects back to the page (no bare `back()`).
- Pricing, the Medicine and Clinical Service forms and the consultation tariff show Panel prices read-only with a
  link "Propose a change" (PR 3). English labels. 44 px targets below 1024 px; cards on phones (existing table
  pattern); no new shared components expected.

## 7. Tests

- Service: permission matrix (Panel Officer proposes only; Finance Officer decides only; others refused), proposer
  cannot decide, stale base version, one open proposal per (book, charge), earlier-than-latest effective time,
  past effective time at approval, idempotent propose with a changed payload, withdraw, reject with a code, foreign
  organisation and non-Panel book refused, `publish()` refuses a Panel book, approved entry is the only new row and
  billing picks it up only from its effective time (a bill made before keeps the old price).
- PostgreSQL: each check and trigger above refused on a direct write.
- Two-process races in the serial Contention suite: two approvers on one proposal; approve against withdraw; approve
  against a Self-pay publish of an unrelated book (no interference); two proposals for one book and charge.
- Frontend source tests for the page. A browser pass for the propose, approve and reject flows on a task-owned
  database, with shots at 1440 and 390 for Daniel.

## 8. Slices

1. Tables, service, permissions, `publishApproved`, tests. `publish()` still allows Panel books (no behaviour change yet).
2. `PanelPrices/Index.vue`, routes, Clinic management row.
3. Close direct publishing for Panel books; the Medicine, Clinical Service, tariff and Pricing screens show Panel prices
   read-only with the link. This is the only slice that changes existing behaviour.

## 9. Existing assertions that will change (to list in each PR; they are left failing at plan stage)

- PR 1: permission and role pins (`SupervisorPricingTest`, Panel Officer and Finance Officer permission lists, any
  catalogue-count pin), the migration-order checks if any.
- PR 2: `ClinicManagementTest` exact destination map gains `/panel-price-changes`.
- PR 3: tests that publish Panel prices through `publish()` or the forms (`UnifiedCatalogueSetupTest`,
  `ClinicalServiceCatalogueControllerTest`, the consultation tariff tests, `PriceHistoryTest`, `PriceListCheckTest`,
  `ApprovedPanelAllocations` fixtures) move to create entries through the approved path or fixtures; frontend pins for
  the Panel price inputs in the Medicine, Clinical Service, Pricing and unified-catalogue-setup tests. The v2-* pins are
  not touched (V2 is being deleted).
- `PostgresBillingRegressionTest` builds Panel books directly and is not expected to change.

## 10. Owner decisions (defaults assumed above)

Owner answers, 21 Nov (round 2), all as proposed except d):
a) (answer 6 = A) Self-pay keeps direct publishing (pilot procedure: Finance Officer publishes, CA Supervisor and Director check).
b) (answer 7 = A) A proposal whose effective time has passed at approval takes effect at approval time, and the page says so.
c) (answer 8 = A) The first Panel price for a new medicine or service is also a proposal; until then billing uses the default Panel price.
d) (answer Q9 = B) **The Director can also approve**, so the approve permission goes to the Finance Officer and the Director. Changed from the draft default.
Permissions approved (P1, P2): `panel.prices.propose.organisation` (Panel Officer) and `panel.prices.approve.organisation` (Finance Officer, Director).
Open for Syafiq: the agreement reference format and whether a proposal may cover several charges (default: one per proposal; bulk later).

## 11. Risks

- Slice 3 changes a daily screen: a Panel Officer who used to edit a Panel price on the medicine form must use the new
  page. The read-only link and the "Waiting" count make it visible; the pilot setup list records it.
- An approved price is future-dated by design; Price list check and Price history already label scheduled prices.
- No money or stock movement is written; invoices, receipts, allocations and claims are untouched (a before-and-after
  comparison test, as in other billing slices, is part of PR 1).
