# Yezza data import: design note

**Status: design note only. Nothing is built.** Data migration from Yezza stays unauthorised (`AGENTS.md`,
`docs/BASELINE.md` section 4) until the owner approves this note, and the import may run on real data only after the
owner later says KPOne is ready for go-live. Asked for by the owner on 25 Nov 2026, relayed by B.E.R.N.I.S.S: *"ini
nanti kita hanya bagi database besar je kan auto migrate, data ni kita dah ada cuma tunggu masa yg sesuai baru kita
letak"* ("later we just hand over the big database and it migrates automatically; we already have the data, we are
only waiting for the right time to put it in").

The owner's wish is "hand over the database and it moves itself". This note keeps that: one command reads the Yezza
export and loads it. But it loads through KPOne's own rules, checks everything first without writing, and ends with a
report a person signs. It is not a raw database copy.

## 1. What arrives, and what we need to learn about it

Yezza is a hosted product, so what arrives is whatever Yezza can export: most likely CSV or Excel files per screen,
possibly a database dump (MySQL or PostgreSQL) if Yezza will give one. We have not seen it. Before any code is written,
we need one **synthetic or de-identified sample** of each file (a few fictional rows with the real columns), and the
answers below.

Questions about the format (for the owner, or for Yezza through the owner):

- [ ] **Shape.** Is it CSV/Excel per screen, a full database dump, or an API? Which version of Yezza?
- [ ] **Encoding and dates.** Character set (UTF-8?), date format (dd/mm/yyyy?), time zone of timestamps, decimal
      separator, and how money is stored (RM with 2 decimals, or sen).
- [ ] **Identity.** Does each patient have a stable Yezza ID? Are IC and passport in separate columns, and is the
      passport's issuing country recorded? Are there patients with neither?
- [ ] **Branches.** Is each row tied to a branch (CHERAS, SUNGAI_BESI, PUCHONG), or is the patient list shared?
- [ ] **Panels and price tiers.** How are Panels exported, and how does a Yezza price tier map to Panels (Yezza groups
      several Panels on one price list; KPOne prices per Panel, `BASELINE` section 6, item 6)?
- [ ] **Catalogue.** Are medicines and services one list or two? Are units (tablet, box of 10) and pack sizes given?
- [ ] **Stock.** Can Yezza export stock on hand per branch with batch number and expiry? Or only totals?
- [ ] **History.** Does the export include visits, invoices, payments, MCs and clinical notes? How many years?
- [ ] **Size.** Roughly how many patients, visits and catalogue rows? (This decides batch sizes and run time.)
- [ ] **Deleted and merged records.** How does Yezza mark a deleted, inactive or merged patient or item?

## 2. Field maps

Each map says where the Yezza value goes and which KPOne service writes it. Column names on the left are placeholders
until we see the sample. "Refuse" means the row is listed in the dry-run report and not loaded.

### 2.1 Patients and identifiers (organisation-level)

Written by `PatientAdministrationService::create` (and `addIdentifier`), the same path as Patient create.

| Yezza (placeholder) | KPOne | Rule |
|---|---|---|
| patient ID | import map (see 3.4) | Kept to make reruns safe; never shown as the KP number |
| — | `patient_number` | KPOne gives a new KP number; Yezza's number is not reused |
| name | `full_name` | Trimmed; required |
| IC number | identifier `nric` | Normalised by `PatientIdentityService`; at most one current NRIC; a duplicate IC across patients is refused, not merged |
| passport number + country | identifier `passport`, `issuing_country_code` | Country required; unknown country refused |
| date of birth | `date_of_birth` | Strict `Y-m-d` after conversion; future or impossible dates refused |
| gender | `sex` | Male, female, unknown |
| nationality | `nationality_code` | ISO 2-letter; unmapped values refused |
| mobile phone | `mobile_phone`, `phone_country` | `PatientPhoneNormalizer`; KPOne needs a phone for an adult (see owner question 6.4) |
| email, address | `email`, `postcode`, `city`, `state`, `country_code` | Only if the export has them |
| guardian (minors) | guardian contact | Required for a known under-18 patient (GUARDIAN-CONTACT-1); see 6.5 |
| allergies | allergy profile | Recommended **not** imported automatically; see 6.8 |

A patient with no date of birth needs the staff adult confirmation (PR #181). The import cannot fake that attestation;
see question 6.4.

### 2.2 Panels

Written by `PanelSettingsService` (Checkout settings). Fields: panel type, SSM number, company name, panel name, code
(never changes after creation), person in charge, phone, email, address, verification link or remark, claim due rule,
TIN. A Panel with an open claim in Yezza is still created here; the claim itself is not imported (section 2.7).

### 2.3 Catalogue and medicines

- Medicines: `MedicineAdministrationService` through the Unified Catalogue Setup path, so each medicine gets its
  inventory item and one verified base-unit SKU (STK-1). Fields: code, display name, generic name, strength, dosage
  form, order unit, category, default dosage, frequency, duration, route, instruction, precaution, indication, usual
  quantity, controlled flag (DSP-3), drug groups and other names (DSP-1b, DSP-1c) if Yezza has them.
- Clinical services: `ClinicalServiceCatalogueAdministrationService` (code, name, category).
- Units: every SKU's stock unit must equal its dispensing unit, or dispensing is refused (STK-1). Rows where Yezza sells
  by box and dispenses by tablet need a person to choose the base unit; the dry run lists them.

### 2.4 Prices

- Self-pay prices: `PricePublicationService::publish` on the branch or default price book, with an effective time of the
  go-live day.
- Panel prices: through `PanelPriceProposalService`, which needs a proposer (Panel Officer) and a different approver
  (Finance Officer). The import proposes; a person approves in the app, or the owner names an approver run (see 6.6).
- A blank price is refused; `0.00` is loaded only if Yezza says so explicitly (CAT-1b).

### 2.5 Suppliers

`SupplierSettingsService` / `SupplierAdministrationService`: name, contact, phone, email, address, payment terms and
lead time if present (inventory programme). No supplier invoices or balances are imported.

### 2.6 Opening stock (per branch)

Through the existing STK-35 `OpeningImportService` (check first, then record all rows or none), which calls
`createBatch` and `InventoryMovementService::openingBalance` per row. Columns: location, SKU, batch number, expiry date,
quantity, unit cost (optional, labelled pending the accountant's cost policy). **Every opening still needs the STK-1
independent sign-off on Pilot readiness, by someone other than the person who ran the import.** A row without a real
expiry date is refused (STK-3); the dry run lists them so a person can check the shelf.

### 2.7 Visit history (optional)

Recommendation: **do not load Yezza visits, invoices, payments or clinical notes as KPOne records.** KPOne's visits,
invoices and documents are evidence with immutable rules (PostgreSQL triggers, numbering, audit), and fabricating them
from another system would break those guarantees and the reports. Instead, either keep Yezza read-only for history, or
(a later, separately approved slice) store a read-only "Yezza history" summary per patient that the doctor can open.
Open Panel claims and unpaid bills at go-live are handled by hand on both systems for the overlap weeks.

## 3. Rules

1. **Only through domain services.** Every row goes through the same service a person would use, with its permission,
   branch scope, transaction, row locks, `lock_version` and audit. No direct `INSERT` into application tables. The
   import runs as named people (for example the Director for organisation data and a CA Supervisor per branch), never as
   an invisible system user, so the audit says who loaded what.
2. **Dry run first, always.** `--dry-run` (the default) reads everything, validates every row against the same rules,
   and prints counts per entity: would create, already present, refused (with reason codes and row numbers). It writes
   nothing, not even an audit row. A real run refuses unless a dry run of the same file hash finished with no
   unresolved refusals, or a person passes an explicit `--accept-refusals` after reviewing them.
3. **Idempotent.** Each entity is keyed by its Yezza ID in an import map; a rerun skips rows already loaded and loads
   only what is new. Each service call carries an idempotency key derived from the batch and the Yezza ID.
4. **One audit row per batch**, with the file's SHA-256, the entity, counts and the operator, never row values or
   patient data. Refusal reports name row numbers and reason codes, never names or IC numbers.
5. **Never reads a real `.env`.** Settings come from the environment's secrets as usual; the command does not open or
   print `.env`.
6. **Real data only on the go-live server.** The command is locked like `kpone:trial-data`: an environment switch
   (`KPONE_YEZZA_IMPORT_ALLOWED`), the site's host name typed at the prompt, and an interactive terminal. It **refuses
   to run on a database holding trial data** (any `@kpjaya.test` account) and on `kpjaya.com`, so real patients never
   land on the trial server. The export file is read from a path the operator gives, never committed, never stored in
   KPOne, and deleted from the server after the signed reconciliation.
7. **Builders work with synthetic samples only.** Tests use fictional rows shaped like the export. No real Yezza file is
   opened in development or CI.
8. **Never automatic.** It runs only when a person starts it; it is not scheduled.

### 3.4 Import map

A small new table, `yezza_import_map` (entity, Yezza ID, KPOne id, batch, loaded at), lets reruns skip loaded rows and
lets the reconciliation trace a KPOne record back to its Yezza row. It holds ids only, no patient values. It needs its
own migration and the owner's approval with the build.

## 4. Validation and the reconciliation report

The dry run and the real run both end with a reconciliation report (on screen and as a CSV with counts only):

| Section | What it compares |
|---|---|
| Patients | Rows in Yezza; created; already present; refused by reason (no phone, bad IC, duplicate IC, minor without guardian, unknown country) |
| Identifiers | IC and passport counts in Yezza and in KPOne |
| Panels | Count, and the list of Panel codes created |
| Catalogue | Medicines and services created; SKUs needing a unit decision |
| Prices | Self-pay prices published; Panel prices proposed, waiting for approval |
| Suppliers | Count |
| Opening stock | Per branch and SKU: Yezza quantity, KPOne opening quantity, difference (must be zero), rows refused (no expiry) |
| Totals | A checksum per entity (count plus a hash of the sorted Yezza IDs) so a second run can prove nothing changed |

The owner (or the person the owner names) signs the report: a printed copy with the date, the file hash and the
signature, kept with the go-live records. Stock openings are not usable until the STK-1 sign-off is done in the app.

## 5. Order and rollback

**Order** (each step a separate batch, dry run then real run):

1. Reference data the import needs: branches, staff accounts, payment methods (already set up by hand).
2. Panels.
3. Suppliers.
4. Catalogue: clinical services, then medicines with their SKUs; settle unit decisions.
5. Prices: Self-pay published; Panel prices proposed and approved.
6. Patients and identifiers (largest step, in batches of about 500 rows, each its own transaction).
7. Opening stock per branch, on the evening before go-live, after the last Yezza dispensing; then the STK-1 sign-off.
8. Reconciliation report and signature. Go-live.

**Rollback.** KPOne keeps evidence append-only, so imported rows are not deleted one by one. Instead:

- **Before go-live**: take a full database backup immediately before step 2. If the reconciliation fails, restore that
  backup (a proved restore is already a go-live gate) and rerun after fixing the file. This is the only clean rollback.
- **After go-live**: no bulk undo. A wrong record is fixed through the normal screens (patient edit, patient merge,
  catalogue edit, governed stock count or write-off), each audited.
- Yezza stays in read-only use for the overlap period agreed by the owner, so nothing is lost if the cutover is paused.

## 6. Owner questions (tick one; recommendation first)

**6.1 What do we import?**
- [ ] (Recommended) Patients, identifiers, Panels, catalogue, prices, suppliers, opening stock. No visit history.
- [ ] Everything above plus a read-only Yezza history summary per patient (a later, separate slice).
- [ ] Everything, including visits and invoices as KPOne records (not recommended; breaks evidence rules).

**6.2 What arrives from Yezza?**
- [ ] (Recommended) CSV/Excel exports per screen, with one de-identified sample first.
- [ ] A full database dump (we would need its schema and Yezza's permission).

**6.3 Inactive and deleted Yezza patients**
- [ ] (Recommended) Import only patients seen in the last N years (owner chooses N); keep the rest in Yezza.
- [ ] Import every patient ever recorded.

**6.4 Patients with no phone or no date of birth**
- [ ] (Recommended) Refuse them in the dry run; staff complete them at the next visit by registering normally.
- [ ] Load them with a named staff member's bulk adult confirmation (a recorded attestation, owner-approved).

**6.5 Minors with no guardian contact**
- [ ] (Recommended) Refuse; staff add the guardian at the next visit.
- [ ] Load without a guardian and flag them for follow-up.

**6.6 Panel prices**
- [ ] (Recommended) The import proposes; a Finance Officer approves the list in the app (two people, as today).
- [ ] The owner names a Panel Officer and a Finance Officer whose accounts the import runs as, for both steps.

**6.7 Duplicate patients inside Yezza (same IC twice)**
- [ ] (Recommended) Load the first, refuse the rest, and list them; staff use the two-person patient merge if needed.
- [ ] Stop the whole import until Yezza's duplicates are cleaned.

**6.8 Allergies**
- [ ] (Recommended) Do not import automatically; the doctor confirms allergies at the first KPOne visit (allergy
      safety depends on a clinician-confirmed profile).
- [ ] Import them as "reported, not yet reviewed" (needs a clinical decision and a doctor's sign-off on the mapping).

**6.9 Opening stock cost**
- [ ] (Recommended) Load unit cost where Yezza has it, labelled pending the accountant's cost policy.
- [ ] Load quantities only.

**6.10 Who runs it and who signs**
- [ ] (Recommended) The technical person runs it; the owner signs the reconciliation; a CA Supervisor at each branch
      does the STK-1 stock sign-off.
- [ ] Other (owner names the people).

**6.11 Before any real data**
- [ ] The PDPA review is done and covers moving patient data from Yezza into KPOne (required; not optional).

## 7. What building it would take (later, after approval)

One Artisan command (`kpone:yezza-import --entity=… --file=… [--dry-run]`) with an importer class per entity under
`app/Domain/Shared/YezzaImport`, the import map migration, synthetic-sample tests (including a committed end-to-end
run, as `TrialDataEndToEndTest` does), and a short operator guide in `docs/DEVELOPMENT.md`. No new permission: it runs as
named people with their existing permissions. Estimated as one slice per entity group, each with its own ship-it.
