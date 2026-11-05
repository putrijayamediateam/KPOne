# Catalogue & settings builder team

Status: **set up with the owner on 2026-11-05.** This folder is the team's memory: what is built, what
the owner decided, the rules, and what to build next. The team reads it before every task and updates it in
the same PR as the code.

## 1. Team

| Role | Name | Job |
|---|---|---|
| Lead | **Adlina** | Plans each piece, builds it, keeps this file current |
| Reviewer | **Ravi** | Checks every piece against the rules below, the tests and the owner's decisions before it reaches the owner |
| Team designer | **Nadia** | Sketches each screen change from `docs/design/GUIDE.md` before Adlina builds it, then runs `docs/design/CHECKLIST.md` on the screenshots before hand-off |

All three are Claude builder roles working for the owner through the coordinator session (LUCY). Names chosen by
the coordinator (2026-11-05). Nadia works under Daniel, the Lead UI/UX Designer.

## 2. What this area covers

The reference data every other team depends on: what the clinic sells and at what price, and the clinic's
own details printed on documents.

**In scope (the team builds and maintains):**
- Medicine Catalogue (`pages/Medicine/Index.vue`, `MedicineAdministrationService`) and Unified Catalogue Setup
  (`UnifiedCatalogueSetupService`, `CatalogueSetupReferenceController`).
- Clinical Service Catalogue (`pages/ClinicalService/Index.vue`, `ClinicalServiceCatalogueAdministrationService`).
- Pricing: charge definitions, price books (Self-pay, default Panel and per-Panel), publishing
  (`pages/Pricing/Index.vue`, `PricingController`, `PricingReferenceAdministrationService`, `PricePublicationService`).
- Business details, logo, branch letterhead and document footer (`pages/BusinessDetails/Index.vue`,
  `app/Domain/Organisation/BusinessDetails/*`).
- Settings pages with no other owner: Medicine details options (`pages/InventoryServicesSettings/MedicineDetails.vue`,
  `CatalogueOptionService`) and Preset diagnoses (`pages/ConsultationSettings/Index.vue`,
  `DiagnosisPresetSettingsService`).

**Shared with other teams (change only through a small agreed change, told to LUCY first):**
- Medicine flags used at Dispensary (controlled medicine DSP-3, drug groups DSP-1b): Dispensary & OTC owns their
  meaning; this team owns the catalogue screen they appear on.
- Inventory items, SKUs, stock units and Suppliers: Inventory team.
- Billing's use of prices (invoice lines, adjustments): Billing team. Panel settings and claims: Panel team.
- Registration settings: Patients & Registration. Queue display settings: Queue & TV. Payment methods: Billing.
- Prints (invoice, receipt, drug label): the layouts are owner and pharmacist decisions; this team only supplies
  the letterhead and footer data.

## 3. Where the code lives

| Kind | Files |
|---|---|
| Pages | `resources/js/pages/Medicine/*`, `ClinicalService/*`, `Pricing/*`, `BusinessDetails/*`, `InventoryServicesSettings/MedicineDetails.vue`, `ConsultationSettings/*` |
| Domain | `app/Domain/Clinical/Services/{MedicineAdministration,ClinicalServiceCatalogueAdministration,UnifiedCatalogueSetup,CatalogueOption,DiagnosisPresetSettings}Service.php`, `app/Domain/Visit/Billing/Services/Pricing*`, `PricePublicationService`, `app/Domain/Organisation/BusinessDetails/*` |
| Tests | `tests/Feature/Billing/Pricing*`, `SupervisorPricingTest`, `UnifiedCatalogueSetup*`, `BusinessDetails*`, `ClinicalServiceCatalogue*`; add one test file per slice |

## 4. Permissions

This team adds and changes no permission without the owner. Existing ones it works with:
`medicines.manage.organisation`, `clinical_services.manage.organisation`, `pricing.references.manage.organisation`,
`prices.publish.organisation`, `catalogue.settings.manage.organisation`, `business_details.manage.organisation`.
Server checks stay the authority; hiding a control is a usability aid only.

## 5. What is built (history, before this team)

| Slice | What it did | PR |
|---|---|---|
| UI-1 | Medicine and Clinical Service Catalogue screens, Pricing screen, stock-setup forms | #36 |
| PX-01 | CA Supervisor manages and publishes prices | #38 |
| PRICE-01 | Charge definitions can be created through the UI | #39 |
| Unified Catalogue Setup | One medicine setup flow with tariffs and optional opening stock | #43 |
| SET-02, SET-05, SET-05b | Medicine details options, preset diagnoses and the picker | #107, #108, #109 |
| BIZ-01, DOC-01, D02 | Business details, logo, letterhead, branch footer on prints | #119, #128, #189 |
| **CAT-1 (this team)** | DD-04 and DD-13 fixed: every Pricing field has a visible connected label (per-row fields named after the charge); Pricing becomes charge cards below 768 px; Medicine Catalogue rows show one Edit button plus a named "..." menu (Deactivate or Activate, Drug groups, Mark or Unmark controlled); Medicine and Clinical Service become cards below 1024 px; connected filter labels; Consultation tariff prices labelled on a phone; neutral Controlled badge. Frontend only: no backend, payload, route or permission change; one owner-approved assertion changed (decision 7). Pinned by `tests/Frontend/cat-1-catalogue-screens.test.mjs` | PR to follow |

## 6. Owner decisions already made (never ask again)

1. The team exists because Pricing, the Medicine Catalogue and the settings screens had no owner (owner, 2026-11-05).
2. Each builder team has a UI/UX designer under Daniel; Nadia is this team's (owner, 2026-11-05).
3. A "ship it" or decision relayed by LUCY counts as the owner's own (owner rule 2026-11-05).
4. Price tiers (SET-01) stay skipped: KPOne prices by visit coverage (owner, 2026-10-18).
5. The invoice and receipt footer wording is the owner's ("This is a computer-generated document. No signature is
   required."); a clinic user types it into Business details for each pilot branch (owner, 2026-10-30).
6. Backup push (owner, 2026-11-05, for all teams; confirmed by the owner in this team's session): whenever the team
   stops to wait for the owner and has work not yet on GitHub, it commits to the piece's own branch and pushes it
   (`git push -u origin <branch>`). It is a backup only: no PR, never `main` or another team's branch, never merge,
   never force-push (add new commits). The PR still opens only after "ship it", from the same branch, with latest
   `origin/main` merged first. After a restart on a fresh machine, fetch the branch before continuing.
7. The Controlled badge on the Medicine Catalogue is a neutral word badge (outline, theme tokens, no amber), as Daniel
   asked; the one existing assertion in `tests/Frontend/dsp-3-controlled-medicine.test.mjs` ("readable in dark mode",
   formerly `dark:bg-amber-950/70`) now asserts that badge instead (owner, 2026-11-05, relayed by LUCY).
8. Publishing a price with an empty amount is refused on the server with a message under the field; a typed 0.00
   stays allowed. Built as its own small piece, CAT-1b, after CAT-1 (owner, 2026-11-05, relayed by LUCY).

## 7. Open items and known limits

- Design debt from Daniel's `docs/design/DEBT.md`: DD-04 (Pricing labels) and DD-13 (Medicine Catalogue), owner
  "Catalogue & settings" with Staff Experience as the interim owner until this team took them on.
- Pilot readiness (BASELINE section 6 items 16 and 17) needs a reviewed catalogue, prices and units, and the footer
  typed per pilot branch. These are setup work by the clinic; this team can make them easier to check.
- Bulk import of catalogue or prices is not authorised (no import of any kind without the owner).
- CAT-1 layout choice: Medicine and Clinical Service switch to cards below `lg` (1024 px), not `md` as in GUIDE
  section 6, because at 768 px the catalogue tables still pushed Edit and the row menu out of view (measured: 12
  buttons outside the table frame). Pricing keeps `md`, where its table fits.
- Found in CAT-1: on Pricing, publishing with an empty "Amount (RM)" box published RM 0.00 without a warning. The
  owner chose to refuse it (decision 8); fixed in CAT-1b, not in CAT-1.
- Seen in CAT-1, not built (for the owner): Medicine details shows a "Price tier" tab although SET-01 is skipped;
  Business details inputs use their own colours or styles instead of the shared
  components; Business details puts its back link before the page title and uses a 1440 px width instead of the
  form width; Medicine details and Preset diagnoses use raw tables, not `OperationalTable`; the Pricing price book
  dropdown cuts long names at 1440 px.

## 8. Rules (every task)

- Read `CLAUDE.md`, `AGENTS.md`, `docs/BASELINE.md`, `docs/design/GUIDE.md` and this file first.
- Never open, print or edit `.env`. Synthetic `.test` data only.
- Every state-changing operation: backend permission and scope, `DB::transaction`, row locks, `lock_version`,
  idempotency where retries are possible, and an audit record (field names, never secrets).
- Prices are append-only and versioned: never overwrite a published price; follow `PricePublicationService`.
- Do not change any existing test assertion without the owner's approval; list each one first.
- Migrations for this team use timestamps from `2026_11_09_000100` onward (other teams own other ranges).
- Design: Nadia sketches before building any visible change and runs the checklist; a new page or changed layout
  also goes to Daniel through LUCY (`docs/design/REVIEW.md`). A "Not OK" from Daniel blocks the merge unless the
  owner accepts it as debt.
- Full gate before hand-off, on a task-owned PostgreSQL 18 database (verify host, port and name first): backend
  tests, Pint, PHPStan, frontend types, lint, format, build, frontend tests, `migrate:fresh --seed`,
  `git diff --check`; screenshots at 390 and 1440 px, light and dark, for every screen touched.
- PHPStan stopgap until the session hook installs it: `git clone -q --depth 1 --branch 2.2.8
  https://github.com/phpstan/phpstan.git /tmp/phpstan-src && mkdir -p vendor/phpstan/phpstan && cp -r
  /tmp/phpstan-src/{phpstan,phpstan.phar,bootstrap.php,conf} vendor/phpstan/phpstan/`, then
  `kphp -d memory_limit=2G vendor/phpstan/phpstan/phpstan analyse --memory-limit=2G --no-progress <changed files>`.
- Independent review by the Reviewer (a subagent given the diff, this file and the rules) before hand-off.
- Commit, push and PR only after the owner says "ship it"; never merge. Never edit `docs/BASELINE.md`; the
  coordinator records BASELINE. Record every new owner decision in section 6 and every finished piece in section 5,
  in the same PR.

## 9. Backlog (next pieces, in order)

1. **CAT-1 Catalogue screens tidy-up (start now).** Fix DD-04 and DD-13 from `docs/design/DEBT.md` (read the
   entries on main or ask LUCY for them), then check Medicine, Clinical Service, Pricing, Business details, Medicine
   details and Preset diagnoses at 320, 390, 768 and 1440 px in light and dark: labels connected to inputs, one `h1`,
   named icon buttons, no sideways scroll, readable dark mode. Layout, labels and accessibility only: no backend,
   payload, route or permission change.
2. **CAT-1b Refuse an empty price amount (owner decision 8).** Server-side refusal of an empty amount on publish,
   message under the field, explicit 0.00 still allowed; backend and frontend tests.
3. **CAT-2 Price list check (proposed, owner to confirm).** A read-only page listing every active medicine and
   service with its Self-pay, default Panel and per-Panel prices, flagging missing or zero prices, to help the
   pilot catalogue review. Existing pricing permissions only.
4. **CAT-3 Price history (proposed, owner to confirm).** Read-only history of published prices per charge: who
   published which amount and when, from the existing versioned price entries.
5. **CAT-4 Document readiness (proposed, owner to confirm).** On Business details, show per branch whether the
   name, address, phone or email, logo and footer are complete, because prints and referral letters depend on them.
