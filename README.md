# KPOne

KPOne is the Klinik Putrijaya Digital Operating Platform: an internal, staff-facing healthcare operations system designed to replace Yezza gradually through controlled releases.

This repository is a standalone Laravel application. It does not modify or depend on `MiniWeb_KlinikPutrijaya_Fullstack`.

> **Safety status:** KPOne is not authorized for production use or real Patient data. Current development, validation, and demonstrations use local or synthetic data only.

## Current status

Last updated: **10 September 2026**

Latest released main baseline:

- Milestone: **P0 — Pricing Reference Governance**
- Main SHA: `81ef2257906c41a8c1a38028b2115c888e888c3a`
- Post-merge CI: **Run #83 — PASS**

Current unreleased work:

- **I1-C synthetic end-to-end UAT:** PASS locally
- Payment Method governance and Visit-bound completion confirmation remain an uncommitted local candidate
- Independent remediation review, exact-candidate freeze, PostgreSQL validation, and explicit release authorization are still required

Successful local UAT does not constitute production or source-release approval.

## Product principles

KPOne follows these operating principles:

- **Operational Familiarity First**
- gradual replacement of Yezza rather than a risky single cutover
- server-side authorization as the source of truth
- organisation and branch isolation
- append-oriented audit evidence
- immutable historical Clinical, Dispensary, Billing, and Payment snapshots
- integer-sen money calculations
- explicit transaction, locking, and idempotency controls
- no automatic Patient, Clinical, Finance, or Inventory access for Technical Admin

Access decisions follow:

`WHO + ROLE + MODULE + SCOPE`

## Released core Patient journey

The released operational journey is:

```text
Registration
→ Queue
→ Consultation
→ Treatment Plan
→ Dispensary
→ Billing
→ Payment / Panel / Pay Later
→ Complete Visitation
→ Completed Patient
```

Key invariants:

- Doctor completion routes medicine cases to Dispensary and no-medicine cases to Billing.
- A Visit remains registered until **Complete Visitation**.
- Actual dispensed quantity is separate from prescribed quantity.
- Stock is deducted only by **Complete Dispensary**.
- Billing never deducts stock.
- Medicine billing uses actual dispensed quantity.
- Services are billed only when performed.
- Money is stored and calculated in integer sen.
- Completed Visits are read-only.
- Repeated Dispensary completion must not deduct stock twice.

## Released milestones

| Milestone | Status |
| --- | --- |
| Phase 0A — Foundation and Security | Complete |
| Phase 0A.1 — PostgreSQL Stabilization | Complete |
| Phase 0B — Staff Administration | Complete |
| Phase 1A — Patient Master | Complete |
| Phase 1B — Registration | Complete |
| Phase 1C — Queue / CA Console | Complete |
| Phase 2A — Clinical Encounter | Complete |
| Phase 2B.0 — Allergy / Problem Safety | Complete |
| Phase 2B — Treatment Plan | Complete |
| Phase 2C — Operational Shell | Complete |
| Phase 3A — Core Dispensary / Inventory Integration | Complete |
| Phase 3B — Completed Patient v1 | Complete |
| R1-A — Registration Validation | Released |
| R1-B — Visit Reason Catalogue | Released |
| R1-C1 to R1-C5 — UX Refinement | Complete |
| P1-A — Queue Performance | Released |
| Q1-A — Public Check-In Architecture / Security Design | Complete |
| Q1-B1 — Public Check-In Foundation | Released |
| M1-B1 — Medicine Catalogue Governance | Released |
| I1-A — Inventory Reference Governance | Released |
| I1-B — Inventory Demo Workspace | Released |
| P0 — Pricing Reference Governance | Released |

### Recent release history

| Release | Pull request | Main merge SHA | Post-merge CI |
| --- | ---: | --- | ---: |
| Q1-B1 | #18 | `cce8af5b8301841cc9d21243a9b26d8712aad7d7` | #75 PASS |
| M1-B1 | #19 | `44d0415d216d23ec0f3270c0f57b6c766e1a4ba3` | #77 PASS |
| I1-A | #20 | `dd0eb6e7943cfb3a7e4de427948a21007022f487` | #79 PASS |
| I1-B | #21 | `261fe404b3e466b7fa3e48d210cbed6cef182a24` | #81 PASS |
| P0 | #22 | `81ef2257906c41a8c1a38028b2115c888e888c3a` | #83 PASS |

## Public Check-In boundary

Q1-B1 provides a public branch check-in foundation using opaque, rotatable, revocable links.

The locked trust boundary is:

```text
Public QR form
→ Pending Patient Intake
→ CA review
→ Patient match or create
→ Visit registration
→ existing Queue workflow
```

A public submission must not directly create:

- Patient
- Visit
- Queue
- Clinical records

Q1-B2 Patient Intake remains paused while Inventory-ready workflow validation is prioritized.

## Medicine and Inventory boundaries

KPOne separates catalogue identity from stock operations.

```text
Medicine Catalogue
→ Inventory SKU mapping
→ Inventory SKU
→ Location stock
→ Batch and expiry
→ Stock ledger
```

Medicine source of truth:

- `medicine_catalogue_items`

Service source of truth:

- `clinical_service_catalogue_items`

Inventory sources:

- `inventory_items`
- `inventory_skus`
- `medicine_catalogue_inventory_skus`
- `inventory_locations`
- `inventory_batches`
- `inventory_stock_balances`
- `stock_movements`
- `dispensary_item_batch_allocations`

Released Inventory capabilities include:

- governed Item, SKU, Location, Batch, and Medicine-to-SKU references
- branch-scoped read-only Stock Overview
- Batch and Expiry view
- Movement view
- Opening Balance, Transfer, and Dispense movement representation
- functional Dispensary stock deduction
- negative-stock and double-deduction protection
- balance locking and PostgreSQL concurrency coverage
- expired-batch exclusion and partial FEFO ordering

Medicine Catalogue is not Inventory. A medicine may remain active even when it has zero stock or no Inventory SKU mapping.

## Pricing

The released pricing path is:

```text
ChargeDefinition
→ PriceBook
→ PricePublicationService
→ versioned PriceEntry
→ PriceResolutionService
→ Billing
```

Pricing supports:

- governed organisation or validated branch scope
- MYR
- branch-first resolution with organisation fallback
- immutable historical PriceEntry and invoice evidence
- consultation, medicine, and clinical-service charge identities
- server-derived charge source and unit

## Deferred scope

The following must not be represented as complete:

- Supplier
- Purchase Order
- Procurement approval
- Receiving / GRN
- Stocktake
- Adjustment
- returns
- runtime box-to-tablet conversion
- near-expiry dashboard
- full Inventory reference CRUD UI
- full Medicine & Services UI
- bulk Medicine/Service import
- Q1-B2 Patient Intake
- production rollout
- real Patient use

Opening Balance is not Receiving.

## Stack

- PHP 8.4.1+ and Laravel 13
- Vue 3, TypeScript, and Inertia 3
- Tailwind CSS and a shadcn-vue-style component system
- PostgreSQL 18 for deployed/local application validation
- SQLite for the default isolated local PHPUnit workflow
- PostgreSQL 18 in GitHub Actions
- Spatie Laravel Permission
- Laravel Socialite
- PHPUnit and Node-based frontend tests

## Local development

See [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md) for the complete setup and PostgreSQL workflow.

```bash
composer install
npm ci
php artisan key:generate
php artisan migrate --seed
npm run dev
```

Use a local environment file created from `.env.example` through the documented setup process.

Never place passwords, tokens, production credentials, real Patient information, or unencrypted environment files in source control or project documentation.

Development and test identities must remain fictional and restricted to local/testing environments. Credentials must be provisioned through an approved local mechanism and must not be documented in this repository.

## Security and deployment rules

Before any production or real-Patient pilot:

- complete an independent security and privacy review
- complete a secrets, repository-history, and deployment-exposure audit
- confirm only `.env.example` is intentionally versioned
- verify no credentials exist in current or historical Git content
- serve Laravel only through the `public` document root
- disable production debug output
- keep PostgreSQL on a restricted private network
- apply least-privilege database credentials
- validate authorization, tenancy, audit, backup, restoration, and rollback controls
- complete exact-candidate PostgreSQL CI
- obtain explicit legal, operational, and release authorization

## Verification

```bash
php artisan test
composer run lint:check
composer run types:check
npm run types:check
npm run lint:check
npm run format:check
npm run build
```

Release candidates also require focused PostgreSQL validation, access-control regression, migration safety checks, and `git diff --check`.

## Documentation

Architecture, security, phase boundaries, and contributor rules are maintained in [`docs/`](docs) and [AGENTS.md](AGENTS.md).

Existing phase documentation includes:

- [Phase 0B](docs/PHASE_0B.md)
- [Phase 1A](docs/PHASE_1A.md)
- [Phase 1B](docs/PHASE_1B.md)
- [Phase 1C](docs/PHASE_1C.md)
- [Phase 2A](docs/PHASE_2A.md)
- [Phase 2B.0](docs/PHASE_2B_0.md)
- [Phase 2B](docs/PHASE_2B.md)
- [Phase 3A](docs/PHASE_3A.md)

## Release discipline

- GitHub is the source of truth for released code.
- Use normal merge commits unless the release process explicitly changes.
- Freeze candidates by immutable SHA and tree.
- Validate the exact candidate before merge.
- Merge only when BLOCKER, HIGH, and MEDIUM findings are zero.
- Run post-merge CI on `main`.
- Do not edit or push the same branch simultaneously from multiple computers.
