# Development

PostgreSQL-only regression and contention tests must run only against a disposable database recognisably named
for testing. A local SQLite skip is not PostgreSQL release evidence.

## Prerequisites

- PHP 8.4.1 or newer with `curl`, `fileinfo`, `intl`, `mbstring`, `openssl`, `pdo_pgsql`, and `sodium`
- Composer 2
- Node.js 22+ and npm
- PostgreSQL 18

## Local setup

1. Install backend and frontend dependencies:

   ```bash
   composer install
   npm ci
   ```

2. Create a local, untracked environment file from `.env.example`. Fill only local PostgreSQL credentials and generate a unique application key. Never commit or share the file.

   ```bash
   php artisan key:generate
   ```

3. Create a UTF-8 PostgreSQL 18 database and restricted application role, then run:

   ```bash
   php artisan migrate --seed
   ```

4. Start the application:

   ```bash
   composer run dev
   ```

The repository defaults to PostgreSQL. The default local PHPUnit configuration overrides the connection to an in-memory SQLite database to keep the routine test workflow fast and isolated; SQLite is not a supported deployed database. GitHub Actions deliberately supplies `DB_CONNECTION=pgsql` and an isolated PostgreSQL 18 service database, so the complete suite also covers production-database semantics.

## First Director bootstrap

On a freshly migrated database with reference data and no active Director, run:

```bash
php artisan kpone:bootstrap-director
```

This command requires an interactive terminal and creates the first Director through `DirectorBootstrapService`.
It asks for the account identity, staff number, primary branch, and sign-in method. Google-only sign-in requires
the Director to use a verified Google email matching the account email. Passwords, when selected, are entered
twice through hidden prompts, hashed immediately by the service, and never accepted as command arguments.
The command refuses to run non-interactively or when an active Director already exists; it does not replace
normal staff provisioning, role administration, or branch-assignment workflows.

Use this only as an explicitly controlled first-install operation. Never run destructive database preparation
against a shared, served, or production database.

## Fresh-database UI acceptance

Run this acceptance path only against a disposable local or test database containing synthetic data. Confirm the
database host, port, and name before starting the application; do not point this procedure at the served or a
production database.

1. Create the empty database, then run `php artisan migrate:fresh --seed`.
2. Run `php artisan kpone:bootstrap-director` in a terminal and choose an appropriate sign-in method.
3. Sign in as the Director and provision synthetic `.test` staff accounts needed for the walk: a `ca_supervisor`,
   a `ca`, and a `resident_doctor`. Confirm each has the intended primary branch.
4. Sign in as the `ca_supervisor` and create a synthetic Medicine and Clinical Service with Self-pay tariffs;
   set the Consultation tariff and add a synthetic inventory location, batch, and opening stock for the Medicine.
5. Walk one synthetic Self-pay Visit through registration, queue, consultation, treatment plan, dispensing,
   invoice, payment, and completion on the same local calendar day.
6. Review Today and all five range reports (Sales, In-clinic, Payments, Inventory, and Patients) with the
   resulting non-zero aggregate data. For range reports, select a range that includes the Visit date rather than
   relying on the default Yesterday range. Check the branch controls, supported filters, ranking search, and empty
   or unsupported-value disclosures as applicable. Reports must not reveal patient-identifiable or patient-level
   financial data.
7. Confirm the visit appears in the aggregate reports and the invoice uses the catalogue tariffs set during the
   walk. Record any discrepancies for correction; do not use real patient or staff data.

This walk is acceptance evidence for a synthetic local setup only. It does not grant production approval.

## QR intake coverage verification

Run this check only with a synthetic QR intake and Panel on a disposable local or test database. The public
check-in form lists active Panels from the organisation linked to the QR session. A patient's coverage choice and
optional member reference remain patient-supplied inside the encrypted Pending Intake; submitting the form must
not create a Patient, Visit, or Queue Entry.

In the authorised CA review page, confirm the interface is in English and that patient-reported coverage,
verified coverage, any verified member reference, and the coverage-confirmation checkbox are grouped under
**Patient information** only; **Visit and Queue** must not repeat those details. Inspect or correct the
patient-reported fields, then separately select the verified coverage and canonical Panel, enter a member
reference only if it has been verified, and tick the confirmation checkbox. Acceptance without that
confirmation must fail. The Visit must contain only the values selected in the CA verification form, not values
copied from the encrypted intake. Use synthetic Panel names and member references; this workflow is not
production approval.

## Payment Method setup

Fresh databases intentionally start without Payment Methods. A Director or Finance Officer with
`payment_methods.manage.organisation` can open **Payment Methods** from Main Menu → Finance, create an
organisation-level method, and explicitly publish it before staff can select it during checkout. Creating a
method does not activate it. Method codes are immutable and retained; deactivate an obsolete method instead of
deleting it. Use synthetic method names and codes in local/test environments; never enter credentials, account
numbers, or real payment details.

## Manual terminal close

The **Terminal Reconciliation** screen is a manual, branch-scoped closing record for Director, Finance Officer,
CA, and CA Supervisor. Staff continue to record each real customer payment against its invoice in KPOne and
take payment on the terminal separately. At closing, use the terminal/acquirer summary to enter approved sales
count and gross total, plus the report's refund and void summaries, for the branch, payment method, and
business-local date.

KPOne compares terminal approved sales with posted KPOne receipts for that same branch, payment method, and
business day. A count or amount difference requires an explanation. A recorded close is immutable; corrections
and receipts added after a close are captured by creating a new revision, retaining the earlier evidence. The
close record never creates, edits, reverses, or refunds a KPOne payment. Terminal refunds and voids are stored as
reported figures only, are not subtracted from approved sales, and do not establish acquirer/bank settlement.
The screen does not connect to the terminal, retrieve receipts, calculate fees, or confirm the bank payout.
Terminal integration and processor refund handling require separate provider, API, security, and owner approval.

Phase 1A adds no patient development seeder. Local and automated Patient Master records must remain obviously synthetic; never copy production patient data into local, test, screenshots, fixtures, backups, or debugging tools. The PostgreSQL Patient Master regression uses separate PHP processes to prove counter initialization/allocation and identifier unique-index contention.

Phase 1B likewise adds no Panel, Patient, or Visit seeder. `PanelFactory`, `PatientFactory`, and `VisitFactory` are test-only synthetic fixtures. The separate-process PostgreSQL Visit regression covers idempotency, Visit-counter allocation, repeat-attendance serialization, cross-branch attendance, and update/cancel contention. SQLite remains the routine workflow and explicitly skips PostgreSQL-only process tests.

Phase 1C adds no Queue seeder. `QueueEntryFactory` is test-only. Queue pages use an initial bounded Inertia projection followed by an authorised private POST snapshot approximately every three seconds while visible. The PostgreSQL Queue regression uses separate processes to prove Queue-entry uniqueness, branch/day counter allocation, Call In and Visit/doctor race serialization.

Phase 2A adds no Encounter, vitals, or diagnosis seeder. Its factories and feature fixtures use synthetic content only. The PostgreSQL Clinical regression uses separate processes to prove one Encounter per Visit, Start/Call/security-state serialization, stale aggregate rejection, diagnosis-list integrity, and rollback. Do not use clinical text copied from any real Patient in local tests, screenshots, logs, or debugging tools.

Phase 2B.0 adds no Allergy Profile, Allergy Record, Encounter review, Problem List, or catalogue seeder. Its factories are test-only and all fixtures use obvious synthetic clinical text. The PostgreSQL Clinical safety regression uses separate processes to prove Profile creation/version serialization, no-known versus Allergy races, competing Allergy edits, final-error consistency, review-versus-mutation staleness, authority revalidation, scope isolation, and full rollback. No Treatment Plan, medicine/service order, catalogue data, Dispensary, inventory, or billing fixture is introduced.

PostgreSQL-specific regression tests skip explicitly on SQLite. In PostgreSQL CI they exercise transactional rollback and genuine row-lock contention using separately bootstrapped PHP worker processes and database connections. Never point that workflow at a developer or production database: the tests require `APP_ENV=testing` and a database name clearly marked as a test database before they write fixtures.

## Dummy account

`DatabaseSeeder` always installs reference organisation/RBAC data. It creates the fictional development administrator only when `APP_ENV` is `local` or `testing`:

- `dev.admin@kpone.test`
- `KPOne-Dev-Only!`

Production environments must not use this identity. The seeder does not create staff from planning headcounts.

## Presentation data

For an isolated local demonstration, first migrate and seed the database, then run:

```bash
php artisan db:seed --class=KPOnePresentationSeeder
```

This local/testing-only seeder creates one synthetic account for each of the ten roles, including a Director,
plus ten fictional Patient Master records. Demo staff are distributed across the three branches, with the
Director assigned access to all three. Staff accounts use `demo.director@kpone.test` for the Director and
`demo.<role>@kpone.test` for each remaining role; all use the seeder's local-only presentation password. The
records are idempotently reused on rerun. The patient records use fictional identifiers, `.test` emails, and the
same synthetic test phone number. The seeder does not create visits, clinical content, appointments, catalogue
items, prices, stock, or billing transactions: those must be created through the authorised UI before walking
those workflows. Never run this seeder with real data or in production.

Seeding is controlled bootstrap, not a runtime administration path. Runtime branch-assignment mutations must use `BranchAssignmentService` so validation, locking, and audit guarantees apply. A synthetic local/testing seeder or test fixture may use explicit guarded writes to establish initial fictional state when routing it through runtime services would create misleading operational audit history.

## Google sign-in

Leave the following placeholders empty unless configuring a local Google OAuth client:

```dotenv
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI="${APP_URL}/auth/google/callback"
```

Google sign-in never provisions users. Create and activate the KPOne account first, and ensure its email matches the intended Google identity. Use only dummy accounts in local development.

## Phase 0B staff provisioning

Authenticated organisation administrators provision staff through the Staff module; public registration remains unavailable. Google-only provisioning stores no password or provider token. Password provisioning accepts a strong bootstrap value, hashes it immediately, and never returns or audits it. Forced first-login rotation is not available in Phase 0B, so use only synthetic credentials locally and follow a separately approved credential-transfer procedure before production use.

An active staff account requires exactly one currently effective primary branch. Additional assignments may be current or future-dated. All runtime assignment changes use `BranchAssignmentService`; do not replace its locking, validation, and audit boundary with direct model writes.

## Commands

Backend:

```bash
php artisan test
composer run lint:check
composer run types:check
```

Frontend:

```bash
npm run types:check
npm run lint:check
npm run format:check
npm run build
node --test tests/Frontend/*.mjs
```

Migration verification with dummy data:

```bash
php artisan migrate:fresh --seed
```

Run destructive migration commands only against a confirmed local/test database. Laravel prohibits destructive database commands in production.

## Insights

The current authorised Insights reports are read-only and aggregate-only. See [INSIGHTS.md](./INSIGHTS.md) for
the report list, permission and privacy boundary, filters, calculations, and release gates. Keep development
fixtures synthetic; do not populate local reports with real patient or staff data. Before release consideration,
run the full tests against disposable PostgreSQL 18 as well as the normal frontend checks, then complete
independent review and human UAT.

## Unified Catalogue Setup

The authorised catalogue setup workflow is implemented in the current unmerged worktree and is not
production-approved. Medicine setup orchestrates catalogue metadata, an existing or newly created linked
Inventory Item/SKU, self-pay and Panel tariffs, and optional batch/opening stock with its branch, location,
supplier, and purchase unit cost. Clinical Service setup supports its category and the same tariff tiers.
Persisted descriptive dropdowns use organisation-scoped catalogue options; inline Panel, supplier, and location
creation calls the existing permissioned administration services. Do not bypass those services or create
duplicate stock references when a matching catalogue-to-SKU mapping already exists.

Both catalogue forms are single-page workflows, not multi-step wizards. Keep their stacked, numbered sections,
descriptive guidance, default-pricing table, and separately listed Panel overrides consistent across Medicine
and Clinical Service. Do not add screenshot-only stock metrics or fields unless they have a governed source of
truth and an authorised backend workflow.

Medicine and Clinical Service editing load saved catalogue details and current tariffs into the same numbered
sections. Updates must use the governed catalogue, inventory, and price-publication services; existing stock
movements and published price history are append-only and must not be rewritten. Catalogue option pickers close
from their arrow button, Escape, or when focus leaves the picker, so an unused dropdown does not remain over
other fields. Both catalogue lists show the current Self-pay and default Panel tariffs only to staff with pricing
reference access; Panel-specific overrides remain in Edit. Medicine opening-stock rows can be removed before
saving, and the server rejects duplicate locations in one submission so an accidental extra row cannot add the
same opening balance twice. An opening balance can also be recorded only once for each location, SKU, and batch
across submissions; use a governed transfer or manual adjustment to move or correct stock afterward.

The Clinical Service Catalogue screen also contains a Consultation tariff section. It maintains the dedicated
`consultation` Charge Definition and versioned Self-pay, default Panel, and Panel-specific prices consumed by
Billing; it does not create a separately ordered Clinical Service. The Pricing link is hidden from workspace
navigation, but its permissioned route and backend records remain intact, so hiding the link does not change
Billing, existing prices, or other workflows.

Purchase-order lines may retain an estimated unit cost; goods receipt lines and resulting stock movements retain
the actual received unit cost, falling back to the estimate only when no actual cost is supplied. These costs
are audit-relevant historical facts, not catalogue prices. Existing records are not backfilled or recreated.
Complete owner-led synthetic UAT of the setup and receiving flows before treating this workflow as release-ready.

## Working conventions

- Use `app/Domain` for business rules and keep controllers thin.
- Add a policy or scope service before exposing a branch- or organisation-sensitive route.
- Add a test proving both the allowed and denied path.
- Use `.test` email domains and obviously fictional names.
- Do not add later-phase tables or placeholder personal/clinical fields speculatively.
- Treat Registration as canonical Visit creation. Do not add a parallel Registration table or Queue/clinical/billing columns to `visits`.
- Treat Queue Entry as the one-to-one operational child of a Consultation Visit. Do not duplicate Patient, doctor, reason, priority, clinical, or billing fields on it.
- Treat Clinical Encounter as the one-to-one in-progress clinical child of a Serving Consultation Visit. Keep clinical note, vitals, and diagnoses out of Patient, Visit, Queue polling, and audit metadata. Do not add signing, completion, handover, treatment, prescribing, or billing semantics in Phase 2A.
- Treat Allergy Profile and Problem List as organisation-level longitudinal clinical data available only through current Encounter care. Never infer no-known from zero rows, auto-review after mutation, copy clinical values into audits/operational projections, or bypass the Profile-version review gate intended for future medicine ordering.
