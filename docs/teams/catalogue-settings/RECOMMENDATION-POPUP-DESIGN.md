# Recommendation popup on the consultation page: design note (SUG)

Status: **design note only, for the owner's approval. Nothing is built.** Owner request 25 Nov 2026, relayed by
B.E.R.N.I.S.S: while the doctor types a diagnosis or a medicine, a popup offers standard wording to pick.
The owner pointed at mims.com/malaysia as the example and chose **"Bina sendiri dulu"**: KPOne builds its own
lists first, and a licensed feed may come later on the same foundation. MIMS content is licensed and is never
copied or scraped.

Scope ticked by the owner: (1) diagnosis suggestions with ICD-10 codes and names, (2) medicine names from the
clinic's own catalogue with strength and form, (3) standard dosing and instruction phrases in English and
Bahasa Melayu, (4) common medicines for a diagnosis from the clinic's own prescribing history.

Synthetic data only. Nothing here is production-approved.

## 1. What exists today (checked in code)

| Piece | Where | What it gives us |
|---|---|---|
| Diagnosis presets (SET-05) | `diagnosis_presets` (`name`, `normalized_name`, `is_active`, `lock_version`); `DiagnosisPresetSettingsService`; Consultation settings page | A name-only list. No code. Doctors use "Choose preset" (`DiagnosisPresetPicker.vue`, `EncounterDiagnosisPresetDirectoryService`, route `encounters.diagnosis-presets`, 50 rows, `LIKE` search). |
| Diagnosis on the encounter | `encounter_diagnoses`: `diagnosis_text` (500), `diagnosis_code` (50), `code_system` (50), both code fields set or both empty | The place to store a chosen code. Nothing fills it from a list today. |
| Prescribing options (SET-02) | `catalogue_options` (`option_type`, one `label`), `CatalogueOptionService` | One label per option, so no Malay twin. |
| Medicine catalogue defaults | `default_dosage_amount/unit`, `default_frequency`, `default_instruction`, `default_precaution`, `default_indication` (300), `usual_quantity` | Per-medicine defaults. |
| Treatment Plan medicine search | `POST .../encounter/treatment-plan/catalogue/medicines/search` (`TreatmentPlanCatalogueController`) | Already searches the clinic catalogue. |
| Order fields | `treatment_plan_medicine_orders`: `dosage`, `frequency`, `duration`, `route` (255), `administration_instruction` | Where a chosen phrase lands. |
| Label budget (DSP-10) | 100 × 62 mm label: "dosage, frequency, instruction" must fit 3 rows of 48 cells (144 characters) or the label is blocked | A hard limit on phrase length. |
| Bounded server-side picker | `GET inventory/picker` (25 default, 100 max, `throttle:120,1`) | The pattern to copy for typeahead. |

## 2. Rules (assumed; section 9 lists what the owner may change)

1. **Advisory only.** A suggestion appears only when the doctor types or opens the field. Nothing is filled or saved
   until the doctor clicks it, the doctor can edit it afterwards, and free typing always works.
2. **No AI service call** (agent CONTRACT no-AI rule). Suggestions are database lookups on the clinic's own lists.
3. **No contraindication, interaction or allergy logic in the popup.** The doctor's existing Encounter allergy review
   before a medicine change and the Dispensary allergy warning are unchanged and are not bypassed.
4. **Every list is reviewed by the clinic's doctor before use.** A diagnosis or phrase is suggested only when it is
   Approved (section 4). Drafts never appear in the popup.
5. **No MIMS or other licensed text is copied, pasted or scraped** into KPOne by anyone. A later licensed feed uses
   the `source` column (section 3) and needs its own licence and owner decision.
6. **Privacy:** item 4 uses aggregate counts only, never patient ids, visit ids or a doctor's name (section 5).
7. **Server authority:** permission, branch and the "consultation is Serving, attending doctor, not held" checks
   are repeated on the server, as `EncounterDiagnosisPresetDirectoryService` does today. The popup is a usability aid.
8. Popup wording says: "Suggestion from the clinic's own list. Not medical advice." (English, staff screen).

## 3. Data model (Catalogue & settings migration range, from `2026_11_09_000600`)

**Diagnoses: extend `diagnosis_presets`, do not add a second list.** SET-05 and "Choose preset" keep working.
- Add nullable `icd10_code` (varchar 20) and `source` (`clinic` default, later `licensed_feed`).
- Add `status` (`draft`, `approved`), `approved_by_user_id`, `approved_at`. Existing rows become `approved`
  (they are already live; the owner can ask for them to be re-reviewed instead, question 3).
- Code and `code_system` are written to `encounter_diagnoses` as `ICD-10` only when a code is present, which fits the
  existing both-or-neither check. Free-typed diagnoses keep working with no code.
- A PostgreSQL check: an approved row has an approver, and the approver is not the person who created it.

**Phrases: one new table `prescribing_phrases`** (not `catalogue_options`, which has a single label).
- `kind`: `frequency`, `instruction`, `precaution`, `duration`.
- `label_en`, `label_ms` (each at most 60 characters so a frequency plus an instruction in one language stays inside the
  144-character label budget with room for the dosage), `is_active`, `sort_order`, `lock_version`,
  `source`, `status`, `created_by_user_id`, `approved_by_user_id`, `approved_at`.
- Unique per organisation, kind and normalized English label. Both languages are required before approval.
- Never deleted: archived, as the other settings lists are.

**Usage summary (item 4): one new table `diagnosis_medicine_usage`**, rebuilt nightly by a scheduled command:
`diagnosis_key` (code if present, else normalized text), `medicine_catalogue_item_id`, `visit_count`,
`patient_count`, `period_end`. It holds no patient, visit, doctor or branch id.

Every settings write follows the KPOne rules: permission under lock, `DB::transaction`, row locks, `lock_version`,
idempotency key (a `*_commands` table as SET-02 and SET-05 use), and an audit row with ids and codes only (the label
text is not clinical, but the audit keeps to ids and kind).

## 4. Who manages the lists, and the doctor review

- **Draft and edit:** the existing `catalogue.settings.manage.organisation` (Director, Administrator, Finance Officer,
  Panel Officer). The owner already decided on 14 Oct that preset diagnoses are reference data. No new permission for this.
- **Doctor review (approve, withdraw):** this is a clinical sign-off, so it should not ride on a finance or panel role.
  **Proposed new permission `clinical_suggestions.approve.organisation`** for the Resident Doctor and the Director.
  It needs the owner's approval (AGENTS.md: clinical-content permissions are an explicit role decision).
  The approver must be someone other than the person who entered the item (service and PostgreSQL).
- **Using the popup:** the doctor's existing `encounters.update.own`. No new permission for reading suggestions.
- Roles that gain nothing: CA, CA Supervisor, Technical Admin, the TV account.
- Any migration that grants a permission to `ca` must also grant it to `medical_assistant` (MA-1a rule). This note grants
  nothing to either.

## 5. The four suggestion kinds

**1. Diagnoses with ICD-10.** The doctor types two or more characters in the diagnosis field. The server searches
Approved presets by name or code, returns at most 25 rows ("Fever, unspecified (R50.9)"), and a click fills the
diagnosis text and code.
*ICD-10 licence:* the WHO owns ICD-10 and its terms limit redistributing the classification. I have **not verified the
current licence text in this session**, so this is a question for the owner and the clinic, not a finding.
**Recommendation:** KPOne ships no ICD-10 list. The clinic's own doctor enters the codes the clinic actually uses
(a short list, a few hundred at most), and the clinic confirms with the Ministry of Health or the WHO whether a bulk
load of the full code set is allowed before anyone imports one. A bulk import is not part of this note.

**2. Medicine names from the catalogue.** The Treatment Plan already searches the catalogue. The popup change is
display only: show strength and form beside the name and put medicines usually used for the chosen diagnosis first
(kind 4). No new data.

**3. Standard dosing and instruction phrases, in English and Bahasa Melayu.** Shown under the Frequency, Instruction,
Precaution and Duration fields of a medicine row. Example: "1 tablet three times daily after food" with
"1 biji 3 kali sehari selepas makan". A language switch in the popup chooses which text is inserted. A click copies the
text into the field; the saved order keeps only the text, so later list changes never rewrite an old order.

**4. Common medicines for a diagnosis, from the clinic's own history.** Computed nightly from finished encounters:
the diagnosis (code, else normalized text) joined to the medicines ordered. **Privacy rules:**
- Aggregate only; the table has no patient, visit, doctor or branch id.
- A medicine appears only if at least **5 different patients** received it for that diagnosis in the last **12 months**
  (minimum group size), so a rare diagnosis cannot point to one person.
- Organisation-wide, not per doctor, so the list never profiles one doctor's prescribing.
- Only active catalogue medicines, and nothing for a diagnosis typed with no match.
- The popup shows rank and "Used in N visits" only when N meets the minimum.

## 6. Pages, routes and performance

- Read-only, under the consultation's existing guard: `GET visits/{visit}/encounter/suggestions/diagnoses?q=`,
  `.../suggestions/phrases?kind=&q=&lang=` and `.../suggestions/medicines?diagnosis=`.
- Bounded server-side search: 25 rows, byte-ordered, minimum 2 characters, `throttle:120,1` (its own counter per route,
  THROTTLE-1). The page waits 250 ms after typing stops and cancels an older request. No per-keystroke audit row.
- Settings: new tabs on the existing Consultation settings page (Diagnoses with a code column, Phrases). No new
  Clinic management row.
- Popup: a keyboard listbox (arrows, Enter, Esc), screen-reader names, 44 px rows below 1024 px, theme tokens for dark
  mode, text in English with the Malay phrase shown beside it. The Lead UI/UX Designer's look is needed.

## 7. Coordination

- The consultation page and Treatment Plan panel belong to Queue & TV (Amirul). Catalogue & settings builds the
  data, settings screens and read services; the popup wiring in `Clinical/Show.vue` and the Treatment Plan panel is
  agreed with Amirul before it is touched.
- Daniel designs the popup. The Reviewer (Ravi) checks each slice.

## 8. Slices

1. **SUG-1: lists and review.** The two migrations, `icd10_code` on presets, phrases table, settings tabs, doctor review
   permission (only if approved), seeder starters marked Draft (local and testing only).
2. **SUG-2: popup for diagnoses and phrases.** Read endpoints and the popup, with Queue & TV.
3. **SUG-3: medicines.** Strength and form in the popup, the nightly usage command and its ranking.

Existing assertions that will change, to be listed in each PR and approved first: the preset list payload and its
tests gain `icd10Code`; `diagnosis-presets` frontend pins; the permission catalogue pin if the review permission is
approved. No hub destination changes.

## 9. Owner questions (tick one; recommendation first)

1. **ICD-10 source.**
   [ ] A. The clinic's doctor enters the codes it uses; KPOne ships no list (recommended)
   [ ] B. Bulk-load the full ICD-10 set after the clinic confirms the licence
2. **Diagnosis list.**
   [ ] A. Extend the existing presets with a code (recommended)
   [ ] B. A separate ICD-10 list beside the presets
3. **Existing presets.**
   [ ] A. Keep them live as Approved (recommended)
   [ ] B. Mark them Draft until a doctor re-reviews them
4. **Doctor review permission.**
   [ ] A. New `clinical_suggestions.approve.organisation` for the Resident Doctor and the Director, approver not the author (recommended)
   [ ] B. Reuse `catalogue.settings.manage.organisation`, no separate sign-off
5. **Who may draft the lists.**
   [ ] A. The existing settings holders (recommended)
   [ ] B. Director and Administrator only
6. **Phrase language.**
   [ ] A. Store both; a switch in the popup; default Bahasa Melayu because the drug label is patient-facing (recommended)
   [ ] B. Default English
7. **Phrase kinds.**
   [ ] A. Frequency, instruction, precaution, duration (recommended)
   [ ] B. Frequency and instruction only
8. **Usage history scope.**
   [ ] A. Organisation-wide, last 12 months, minimum 5 different patients, rebuilt nightly (recommended)
   [ ] B. Per branch
   [ ] C. A doctor's own history only
9. **What the doctor sees for usage.**
   [ ] A. Rank, plus "Used in N visits" when N is at least 5 (recommended)
   [ ] B. Rank only
10. **Starter data.**
   [ ] A. Ship none in production; Draft demo starters in local and testing only (recommended)
   [ ] B. Ship a starter list for the clinic to review
11. **When the popup opens.**
   [ ] A. After 2 typed characters; medicine rows also show the top 8 common ones on focus (recommended)
   [ ] B. After 2 typed characters only
12. **Wording.**
   [ ] A. "Suggestion from the clinic's own list. Not medical advice." (recommended)
   [ ] B. Other wording: ______

## 10. Risks

- A wrong or outdated phrase is only as safe as the doctor's review; the Approved gate and the second-person rule are the
  control, and the doctor always sees and edits the text.
- The usage ranking can repeat old habits; it is shown as a rank, never as a recommendation to prescribe.
- ICD-10 licensing is unconfirmed (section 5). Until it is, no bulk code set is loaded.
- A frequent popup adds read load on the consultation page; the bounded search, minimum length, throttle and debounce
  keep it small. Not load-tested.
