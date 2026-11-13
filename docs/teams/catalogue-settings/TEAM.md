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

All three are Claude builder roles working for the owner through the coordinator session, B.E.R.N.I.S.S (formerly LUCY; renamed by the owner on 2026-11-05). Names chosen by
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

**Shared with other teams (change only through a small agreed change, told to B.E.R.N.I.S.S first):**
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
| **CAT-1 (this team)** | DD-04 and DD-13 fixed: every Pricing field has a visible connected label (per-row fields named after the charge); Pricing becomes charge cards below 768 px; Medicine Catalogue rows show one Edit button plus a named "..." menu (Deactivate or Activate, Drug groups, Mark or Unmark controlled); Medicine and Clinical Service become cards below 1024 px; connected filter labels; Consultation tariff prices labelled on a phone; neutral Controlled badge. Frontend only: no backend, payload, route or permission change; one owner-approved assertion changed (decision 7). Pinned by `tests/Frontend/cat-1-catalogue-screens.test.mjs` | #263 (`705b0f5`) |
| **CAT-1b (this team)** | Publishing a price with an empty amount is refused with "Enter an amount. Type 0.00 if this is free." under the field: the Pricing page no longer reads an empty box as RM 0.00, `PricePublishRequest` gives the message, and `PricePublicationService` refuses an empty amount on every path. A typed 0.00 is still published. No permission, migration or route change; no existing assertion changed. Also carries Daniel's CAT-1 follow-ups: sentence-case dialog titles on Medicine and Clinical Service, a ghost Deactivate on the Pricing phone cards, before shots renamed `*-before-*` and every after shot re-taken. Tests: `tests/Feature/Billing/PricePublishEmptyAmountTest.php`, `tests/Frontend/cat-1b-refuse-empty-price.test.mjs` | #266 (`681c8a9`) |
| **CAT-2 (this team)** | Price list check at `/pricing/price-check` (linked from the Pricing header): every active medicine, clinical service and the consultation with the Self-pay, default Panel and per-Panel prices billing would use at the active branch, and checks for no charge, charge inactive, no Self-pay price, unit differs, no default Panel price and RM 0.00. Read-only, existing `pricing.references.manage.organisation` permission, no migration or export. The price choice is billing's own: `PriceResolutionService::select()` was extracted from `price()` with no behaviour change and is shared by both, with a test that the page and a real invoice line agree. Tests: `tests/Feature/Billing/PriceListCheckTest.php`, `tests/Frontend/cat-2-price-list-check.test.mjs` | #278 (`3be1d4a`) |
| **CAT-3 (this team)** | Price history at `/pricing/charges/{charge}/history`: every published version of one charge in every price book, newest first, with the amount, when it took effect, who published it (staff-directory visibility, otherwise "Staff member") and a status: "In use" (the version billing picks now), "Scheduled", "Replaced" or "Not in use (book inactive)"; Free (RM 0.00) flagged only when in use or scheduled; a price-book filter. Opened from "Price history" on each Pricing row and card and from the item name on the price list check, with a back link from a fixed list. Pricing rows and cards show one visible action with Deactivate/Activate in a "..." menu (decision 11). CAT-2 follow-ups: fixed column widths and "—" for an inactive charge's prices. Read-only, existing pricing permission, no migration. Tests: `tests/Feature/Billing/PriceHistoryTest.php`, `tests/Frontend/cat-3-price-history.test.mjs` | #292 (`13f5c1f`) |
| **CAT-4 (this team)** | Document readiness card at the top of Business details: every active branch (active branch first) with "Ready", "Needs footer" (warning: invoices and receipts print without one, D02) or "Letters blocked: <letters>". The checks repeat what the document code enforces at issue: referral and attendance letters need the clinic name, a complete address and a phone or email and refuse a retired or broken configured logo (`DocumentLetterheadCapture`); the MC checks only the logo file, so only a missing or changed logo file blocks it (`MedicalCertificateSnapshot`). Missing items listed (two, then "+N more"), "Fix below" for editors on the active branch, otherwise "Ask an editor" or "Switch to <branch>". Read-only, existing Business details view permission, no migration. Tests: `tests/Feature/DocumentReadinessTest.php`, `tests/Frontend/cat-4-document-readiness.test.mjs` | #297 (`274ce49`) |
| **CAT-5 (this team)** | Settings screens tidy-up, layout, labels and accessibility only. Business details: PageHeader with "← Clinic management" as a small ghost button and a "View only" word badge in its actions, form width, shared Input, Label, InputError, OperationalSelect and Textarea, "*" with aria-required and one "* Required" line. Medicine details and Preset diagnoses: OperationalTable (sentence-case headers, fixed widths) from 768 px and cards on a phone, a themed status switch with an "Active"/"Archived" word, a named "..." icon menu (36 px desktop, 44 px phone), 44 px tabs and a visible search label on a phone. Clinical Service: the publication branch select is connected to its visible label. One approved existing assertion change (decision 12). Tests: `tests/Frontend/cat-5-settings-tidy.test.mjs` | #303 (`b5c7a5b`) |
| **CAT-6 (this team)** | Pricing small fixes. "Current price" is the price that took effect most recently and is in effect now, in active books only (as billing); a price that starts later shows as a muted second line "From <date and time in the branch time zone>: RM x.xx", and a charge whose only prices start later shows "—". The publish form still sends the newest version of that book as its expected version, so publishing is unchanged. The price book select wraps long names to two lines (36 px minimum on desktop, 44 px on a phone), the list opens at the box width, and table cells are top-aligned. No existing assertion changed. Tests: `tests/Feature/Billing/PricingCurrentPriceTest.php`, `tests/Frontend/cat-6-pricing-fixes.test.mjs` | #318 (`a07bc58`) |
| **CAT-7 (this team)** | The greyed, non-clickable "Price tier" item is removed from the Inventory and services settings menu on Medicine details and Suppliers, because SET-01 stays skipped (decision 4). The menu is a two-item link list with the current page marked. No backend, route or permission change. One owner-approved existing assertion change (decision 13). Tests: `tests/Frontend/cat-7-hide-price-tier.test.mjs` | #334 (`efed808`) |
| **CAT-8 (this team)** | Catalogue wording and a label hint. The activate and deactivate confirm titles are sentence case on both catalogues ("Deactivate medicine", "Activate medicine", "Deactivate clinical service", "Activate clinical service"; Daniel's nit). The Medicine Catalogue Indication field says "Prints on drug labels: up to 72 characters." (the DSP-10 label budget, 2 rows of 36 at 9 pt), through a new optional `hint` prop on `CatalogueOptionPicker` connected with `aria-describedby`; the field still accepts 300 characters. Both picker help lines are `text-xs` (12 px), the help-text minimum (Daniel). No existing assertion changed. Tests: `tests/Frontend/cat-8-catalogue-wording.test.mjs` | #355 (`0cb1cd6`) |
| **CAT-9 (this team)** | An optional second clinic phone per branch in Business details: `branch_business_profiles.phone_secondary` (migration `2026_11_09_000100`), normalised to E.164 like the first phone, refused without a first phone or when the same as it (checked inside the transaction against the value that will be saved), kept when a request leaves the field out, audited by field name only. The letterhead carries `details.phone_secondary`; new referral and attendance copies print "Tel: A / B" (a copy without it renders byte for byte as before). Labels "Phone (optional)" and "Second phone (optional)". No existing assertion changed. Tests: `tests/Feature/BusinessDetailsSecondPhoneTest.php`, `tests/Feature/Clinical/DocumentSecondPhoneTest.php`, `tests/Frontend/cat-9-second-phone.test.mjs` | #362 (`6766129`) |
| **CAT-10 (this team)** | Leftovers tidy. Business details: the logo's native file input (cut to "No …sen" at 390 px) becomes a reusable `catalogue/FileChooseButton` (a hidden native input opened by an outline "Choose logo" or "Replace logo" button, 44 px below 640 px, with a state line that the button describes and that screen readers hear: "<file> · not saved yet", "Logo will be removed when you save.", "Current logo saved." or "No file chosen"; the error slot keeps `id="logo-error"`); a file that fails the check or cannot be shown is never previewed (Daniel); "Remove logo" is 44 px on phones; the page fits 320 px. Medicine Catalogue: "Price publication branch" now reads "Branch used for price publication", with its select connected to the label, as on Clinical Service. Frontend only; no existing assertion changed. Tests: `tests/Frontend/cat-10-leftovers-tidy.test.mjs` | #366 (`c30bd6e`) |
| **CAT-12 (this team)** | A "Check price list" card in Clinic management, in "Inventory & services" straight after Pricing ("See the price billing uses for every active item, with gaps flagged."; wording, group and placement from Staff Experience), for holders of `pricing.references.manage.organisation` with an active branch (the page shows results only for the active branch). One approved existing assertion change: the exact destination map in `ClinicManagementTest` gains `/pricing/price-check`. Tests: `tests/Feature/Catalogue/PriceCheckCardTest.php` | #371 (`8794506`) |
| **CAT-13 (this team)** | One phone style on the referral and attendance letterheads: new copies print both clinic phones through `App\Domain\Shared\PrintedPhone` ("03-2000 0001"), through a small helper `Clinical/Documents/LetterheadContactLine`; a second phone with no first prints "Tel: B"; without a second phone the line keeps the "phone / email" shape. Issued letters keep the HTML stored at issue. `PrintLetterhead` (`resources/js/types/letterhead.ts`) gains an optional `phone_secondary`, and Business details reuses `PrintLetterhead['details']`. Three approved existing assertion values in `DocumentSecondPhoneTest` (lines 46, 59, 62) follow PrintedPhone. Tests: `tests/Unit/LetterheadContactLineTest.php`, `tests/Frontend/cat-13-letterhead-type.test.mjs` | #372 (`90a7e39`) |
| **CAT-11 (this team)** | Fixed clinical service categories: "Procedure(s)", "Investigation(s)", "Consultation-related", "Other service", or none (`ClinicalServiceCategories::NAMES`). The Clinical Service add and edit dialog uses a fixed select, "Category (optional)", instead of the free-text picker; a category saved before CAT-11 stays as it is and shows as its own option, "(saved before the fixed list)". `ClinicalServiceCatalogueAdministrationService` refuses an unlisted value on create, and on update only when the category changes ("Choose a category from the list."). A PostgreSQL trigger (migration `2026_11_09_000200`, `BEFORE INSERT OR UPDATE OF category`) repeats the rule for new rows and changed categories only; no data is rewritten. The demo seeder uses "Procedure(s)" and "Investigation(s)". One approved existing assertion change: line 63 of `unified-catalogue-setup.test.mjs` checks `serviceCategories` instead of `type="service_category"`. Tests: `tests/Feature/Clinical/ClinicalServiceCategoriesTest.php`, `tests/Frontend/cat-11-service-categories.test.mjs` | #381 (`d5dec88`) |
| **HR-11a and HR-11b (this team, owner-assigned 2026-11-10; design note agreed by the HR team)** | Clinic compliance at `/clinic-compliance` (domain code in `app/Domain/Organisation/Compliance`; clinic data, so not under `app/Domain/HR`). **HR-11a, clinic licences:** the clinic enters its own licence types (name, expires or not, warning days); a licence is one series per type and branch (or the whole clinic) with append-only versions, reference and note encrypted, up to 3 scans or photos (PDF, JPG, PNG) encrypted under a random name through the HR-owned `LeaveEvidenceFiles::prepare()` (read-only reuse, agreed by HR), every open audited first, a wrong entry marked entered in error and never deleted. Statuses are worked out when the page opens in the licence's branch-local day. Permission `compliance.licences.manage.organisation` (Director, Administrator, HR Manager). **HR-11b, vaccine fridge log:** fridges with a branch, allowed range in tenths of a degree, readings a day and an optional expect-by time; readings with server time, a kept range snapshot, an alert until one action is recorded (the reader may also record it), day by day cards and table, entered in error. Permissions `compliance.fridge.manage.organisation` (Director, Administrator), `compliance.fridge.record.branch` (CA, CA Supervisor, Director, Administrator), `compliance.fridge.review.branch` (CA Supervisor, Director, Administrator). Nothing ships with a list, range or number; nothing is sent. Migrations `2026_11_13_300100` to `300400`. Existing assertion changes (owner-approved): `ClinicManagementTest` map line for `/clinic-compliance`; `LicencesTest` lists CA and CA Supervisor as reaching the page for the fridge tab. HR-11c (monthly audits and supervisor tasks) is not built | #426 (`b07a64b`), #432 (`ee47cd6f`) |

## 6. Owner decisions already made (never ask again)

1. The team exists because Pricing, the Medicine Catalogue and the settings screens had no owner (owner, 2026-11-05).
2. Each builder team has a UI/UX designer under Daniel; Nadia is this team's (owner, 2026-11-05).
3. A "ship it" or decision relayed by the coordinator (B.E.R.N.I.S.S, formerly LUCY) counts as the owner's own (owner rule 2026-11-05).
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
   formerly `dark:bg-amber-950/70`) now asserts that badge instead (owner, 2026-11-05, relayed by LUCY). Aisyah (Dispensary & OTC) confirmed the
   neutral badge is fine as long as it reads "Controlled" clearly; amber had no Dispensary meaning.
8. Publishing a price with an empty amount is refused on the server with a message under the field; a typed 0.00
   stays allowed. Built as its own small piece, CAT-1b, after CAT-1 (owner, 2026-11-05, relayed by LUCY).
9. CAT-2 is authorised: "go ahead with CAT-2" (owner, 2026-11-05, in this team's session). Read-only, existing
   permissions only, no new permission, migration or export.
10. CAT-3 and CAT-4 are authorised: "go ahead with CAT-3 and CAT-4" (owner, 2026-11-05, in this team's session).
   One at a time after CAT-2, each its own piece and PR, existing permissions only, no new permission, migration or
   export; Nadia sketches each for Daniel first. The go-ahead counts as the ship-it for both: each opens as a PR
   and merges on green unless Daniel says "Not OK".
11. Pricing rows and phone cards show one visible action ("Price history") with Deactivate/Activate in a "..."
   menu, as Daniel asked in CAT-3. The one existing assertion in `tests/Frontend/cat-1b-refuse-empty-price.test.mjs`
   (the card's ghost/secondary Deactivate button) now asserts the menu item instead (owner, 2026-11-05, in this team's
   session: "yes, change the test and move the button").
12. CAT-5 (settings screens tidy-up) and CAT-6 (Pricing small fixes) approved, and CAT-7 (the "Price tier" tab) sent
   to the owner: B.E.R.N.I.S.S for the owner, 2026-11-06, while the owner was away; the owner can change it. The one
   existing assertion change CAT-5 needs, in `tests/Frontend/medicine-details.test.mjs` ('NAME', 'STATUS' and
   'ACTIONS' become 'Name', 'Status' and 'Actions' for sentence-case headers), was approved the same way; the owner
   confirmed it in this team's session on 2026-11-06 ("keep sentence case, carry on with CAT-5").
13. CAT-7: hide the "Price tier" tab (owner, 2026-11-06, relayed word for word: "go with CAT-7, hide the price tier
   tab"). The owner approved the one existing assertion change in this team's session: "yes, drop Price tier from the
   supplier test" (`tests/Frontend/supplier-settings.test.mjs` line 9 drops 'Price tier'). The Inventory Lead agreed
   to removing only that item in `Suppliers.vue`.
14. CAT-8: sentence-case activate and deactivate titles on the Medicine and Clinical Service catalogues (Daniel's
   nit) and the hint "Prints on drug labels: up to 72 characters." under the Medicine Catalogue Indication field
   (DSP-10 budget, no limit change): B.E.R.N.I.S.S for the owner, 2026-11-06, while the owner was away; the owner gave
   the ship-it in their own words, relayed by B.E.R.N.I.S.S: "ship it for CAT-8 for adlina".
15. CAT-9: an optional second clinic phone, per branch (owner, 2026-11-06, in this team's session: "go with CAT-9",
   then "ship it for CAT-9"; the owner chose it to print everywhere the clinic phone prints rather than "invoices and
   receipts only"). Decided by B.E.R.N.I.S.S for the owner during the owner's "BE ME": the drug label does not print
   it (Dispensary & OTC, for the label's row budget); referral and attendance copies print it in CAT-9; the MC
   template is left to its owner (MC slice 1). Daniel: "Phone (optional)" and "Second phone (optional)".
16. CAT-10: the leftovers tidy (the styled logo file button and the Medicine Catalogue publication branch label):
   B.E.R.N.I.S.S for the owner, 2026-11-06, during the owner's "BE ME"; Daniel OK (state line wording "Current logo
   saved."), Ravi OK.
17. CAT-12: a "Check price list" card in Clinic management and the one `ClinicManagementTest` map line: B.E.R.N.I.S.S
   for the owner, 2026-11-06; wording, group and placement agreed by Staff Experience (Kavitha); Ravi OK.
18. CAT-13: PrintedPhone on referral and attendance letterheads, "Tel: B", the shared `PrintLetterhead` type and the
   three `DocumentSecondPhoneTest` values: B.E.R.N.I.S.S for the owner, 2026-11-06 (from the PNL-19 reviews by
   Arjun and Priya, and Daniel's "one phone style on every print"); Daniel OK, Ravi OK. The MC template is Nurul's
   and is left to her MC follow-up.
19. From CAT-10 on, a build PR leaves TEAM.md out; its lines go in a later Catalogue docs PR (coordinator rule,
   2026-11-06), so stacked PRs do not conflict.
20. CAT-11: the four fixed clinical service categories, nothing rewritten, a trigger that checks only new and changed
   rows, the line-63 assertion change and the demo seeder change: B.E.R.N.I.S.S for the owner, 2026-11-06; the names
   "Procedure(s)" and "Investigation(s)" confirmed by Billing (Farah) for BIL-9; Daniel OK (he finds the "(s)" names
   a little awkward, kept to match the clinic's invoice categories); Ravi OK.

21. **Version 2 pages (owner, 2026-11-12/13).** The restyle of Classic is closed: Classic stays exactly as it was.
   Every department builds Version 2 pages behind the per-user "Try the new layout" switch, registered in
   `config/v2-pages.php`, each with the same props, routes, permissions and lock versions, a Classic-compare test and
   shots in the real V2 shell on a `validation/*` branch. B.E.R.N.I.S.S relays the owner on this: it asks Daniel for the
   look and then ships for the owner ("approved by B.E.R.N.I.S.S for the owner"). Merged or in a PR as of 2026-11-13:
   Payment Methods (#660), Pricing (#668), Business details (#690, batch 15), Medicine Catalogue (#692), Suppliers
   (#697), Clinical Service (#701), Medicine details (#707), Registration settings and Consultation settings (#713) and
   Price list check and Price history (#715). Daniel's department look: Business details is two tabs (Company, Branches)
   with a Document readiness disclosure bar; each Branches save names what it covers; Remove logo asks first.
22. **Changed paths in V2 (Daniel, 2026-11-13).** Archive or Deactivate asks first on every V2 settings page (Classic
   toggled with no question); the wording comes from what the backend really does (for example a supplier archive:
   no new purchase orders or requests, drafts cannot be submitted, submitted orders unchanged). The discard question
   puts "Keep editing" on top and focused on a phone, and focus returns to the field that had it. A refused price save
   keeps what was typed and keeps the old expected versions (consultation tariff).

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
  owner chose to refuse it (decision 8); fixed in CAT-1b.
- Seen in CAT-1, not built (for the owner): Medicine details showed a "Price tier" tab although SET-01 is skipped
  (removed in CAT-7); Business details inputs use their own colours or styles instead of the shared
  components; Business details puts its back link before the page title and uses a 1440 px width instead of the
  form width; Medicine details and Preset diagnoses use raw tables, not `OperationalTable`; the Pricing price book
  dropdown cuts long names at 1440 px.
- CAT-2 known limits: checks run in memory over every active item on each request (fine for a clinic catalogue;
  revisit if the catalogue grows to thousands of items); prices are shown for the active branch only; a Panel's
  price is listed only when its own book has one; no Clinic management card at first (added in CAT-12).
- CAT-3 known limits: the history reads all versions of one charge per request; the pre-existing Pricing "Current
  price" column showed the highest version even when it was scheduled for later (fixed in CAT-6).
- CAT-4 known limits: the configured logo file is read and hashed on each Business details view (at most 2 MB);
  the MC still does not check the address block (BASELINE; changing that is a separate owner decision); branches
  other than the active one are fixed by switching branch in the header.
- Seen in CAT-5, not built: Suppliers (Inventory team) has the same raw table and switch; the Panel tariff editor's
  "Choose Panel to add" select has an aria-label but no visible label (Staff Experience UX-6 is editing that file);
  the Medicine Catalogue said "Price publication branch" where Clinical Service says "Branch used for price
  publication" (fixed in CAT-10).
- CAT-6 known limits: a Pricing row shows one book's price (the one that took effect most recently), so a charge
  with Self-pay and Panel prices shows only one of them there; Price check and Price history show every book.
- CAT-9 known limits: the second phone prints only where each document's owner adds it (invoices and receipts by
  Billing, the Panel claim pack by Panel, the MC by its owner; never on the drug label); the next print of an
  existing invoice after it is set will be REVISED once Billing adds it, because the letterhead changed; a referral
  or attendance preview open across the deploy asks for one reload. Seen (Daniel): at 390 px the logo's native file input
  cut "No file chosen" to "No …sen" (fixed in CAT-10).
- CAT-10 known limits: `FileChooseButton` is used only for the Business details logo; other pages with a native file
  input (Panel, Queue Display, HR and Website pages) keep it until their teams adopt the component (offered to
  Staff Experience in the CAT-10 PR).
- CAT-13 known limits: a new referral or attendance letter's phone line looks different from paper printed before
  CAT-13 (issued copies keep their stored HTML); the MC still prints the stored phone until its owner changes it;
  the Panel claim pack and Billing's invoice and receipt switch to PrintedPhone in PNL-19 and BIL-9.
- CAT-11 known limits: the `service_category` option type in `CatalogueOptionService` is now unused (its rows stay as
  unused data); once a listed name is picked, an older value's option disappears until the dialog is cancelled; BIL-9
  must put any category outside the four names (older free text) on the Service row; the four names live in both
  `ClinicalServiceCategories::NAMES` and the trigger's SQL (a test inserts every listed name to catch drift).

- **V2 follow-ups (2026-11-13):** the shared selects still look Classic in places and are logged as DD-83 (the Order
  unit field and the Panel prices editor on the Clinical Service form; the registration type and branch selects on
  Business details); swap Business details to V2StickyBar `always` and the V2Picker; the page-level discard prompt
  (`components/catalogue-v2/DiscardPrompt.vue`) is kept for same-path, Back and tab cases because V2LeaveGuard holds only
  different-path visits (waiting for Daniel's call: use V2LeaveGuard for the different-path case only); the Price history
  time note says "the branch's time zone" because the page has no zone name, so a small server prop with the branch zone
  is a separate item; Back on Medicine and Clinical Service shows a short flash of the previous page because Inertia swaps
  the page before our handlers can stop it.

## 8. Rules (every task)

- Read `CLAUDE.md`, `AGENTS.md`, `docs/BASELINE.md`, `docs/design/GUIDE.md` and this file first.
- Never open, print or edit `.env`. Synthetic `.test` data only.
- Every state-changing operation: backend permission and scope, `DB::transaction`, row locks, `lock_version`,
  idempotency where retries are possible, and an audit record (field names, never secrets).
- Prices are append-only and versioned: never overwrite a published price; follow `PricePublicationService`.
- Do not change any existing test assertion without the owner's approval; list each one first.
- Migrations for this team use timestamps from `2026_11_09_000100` onward (other teams own other ranges).
- Design: Nadia sketches before building any visible change and runs the checklist; a new page or changed layout
  also goes to Daniel through B.E.R.N.I.S.S (`docs/design/REVIEW.md`). A "Not OK" from Daniel blocks the merge unless the
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
   entries on main or ask B.E.R.N.I.S.S for them), then check Medicine, Clinical Service, Pricing, Business details, Medicine
   details and Preset diagnoses at 320, 390, 768 and 1440 px in light and dark: labels connected to inputs, one `h1`,
   named icon buttons, no sideways scroll, readable dark mode. Layout, labels and accessibility only: no backend,
   payload, route or permission change.
2. **CAT-1b Refuse an empty price amount (owner decision 8).** Server-side refusal of an empty amount on publish,
   message under the field, explicit 0.00 still allowed; backend and frontend tests.
3. **CAT-2 Price list check (authorised, decision 9).** A read-only page listing every active medicine and
   service with its Self-pay, default Panel and per-Panel prices, flagging missing or zero prices, to help the
   pilot catalogue review. Existing pricing permissions only.
4. **CAT-3 Price history (authorised, decision 10).** Read-only history of published prices per charge: who
   published which amount and when, from the existing versioned price entries.
5. **CAT-4 Document readiness (authorised, decision 10).** On Business details, show per branch whether the
   name, address, phone or email, logo and footer are complete, because prints and referral letters depend on them.
