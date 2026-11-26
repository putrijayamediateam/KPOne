# Family link F1–F3 — concrete review draft, 26 November 2026

Status: implementation authorised on 26 November 2026. The owner approved the consolidated relationship defaults, merge/unmerge behavior and exact two-table migration. This scoped implementation follows the recovered F1–F3 decisions; no existing assertion changes are authorised.

## Recorded owner decisions (do not ask again)

The supplied full handoff, lines221–223, approves F1 reuse `patients.update.organisation` with no new permission; F2 no relatives suggested from shared phones; F3 Consultation Family card contains only relatives waiting today plus a link, never relatives' conditions. Source readiness: `/workspace/kpone-review-bundles/stage1-flow/READINESS-2026-11-26.md`.

The demo `project/Patient.dc.html` shows a daughter relationship and a Family bill link, still marked proposal. `project/Consultation.dc.html` says a daughter is waiting but also lists parents' conditions: **F3 supersedes those condition examples**. Family bill is Billing-owned and excluded from this slice. Guardian contacts and the newly approved emergency-contact feature remain separate and unchanged.

## Proposed smallest slice

A manually entered direct relationship between two existing same-organisation patient records. No household/family-group identifier, transitive relatives, shared-phone/address matching, inherited consent, legal guardian authority, appointment access, payment aggregation or clinical access. A–B and B–C never imply A–C.

On Patient details, show a Family links section to existing patient-view holders. The Family GET checks the current patient and each linked endpoint with the existing patient-view policy before exposing any row, name, number or link; omit unauthorised endpoints without counts. A merged-link generic notice and original-link removal affordance are shown only when both original endpoints pass view policy; they must not reveal otherwise hidden relationships. Offer Add/Edit relationship/Remove only to existing patient-update holders, with server `view` and `update` policy checks on **both** endpoint patients. Normal existing patient search/picker may be used manually, but no search is prefilled from this patient's phone, and no auto-suggestion runs. A command requires the explicitly selected patient number and a relationship choice; a text query alone cannot create a link. Use existing masking/duplicate-selection behaviour unchanged.

Proposed relationship vocabulary, stated from the current patient's perspective:

| Selected meaning | Inverse shown from other patient |
|---|---|
| Parent of | Child of |
| Child of | Parent of |
| Spouse of | Spouse of |
| Sibling of | Sibling of |
| Other relative of | Other relative of |

Use generic labels rather than inferring gender, biological parenthood or legal status. One active relation per unordered patient pair; correction changes that pair's code with version/audit. There is no one-spouse, age-gap, genealogy-tree or legal-parent validation in this administrative link feature. Multiple relationships or free-text relationship notes are not part of the smallest slice. These vocabulary/cardinality choices were owner-approved on 26 November 2026.

## Canonical pair, merge and unmerge behaviour

Store two ordered patient IDs: `patient_low_id < patient_high_id`. Code describes the low-ID patient's relation to the high-ID patient; inverse the parent/child code when normalising a command or rendering from the other side. The unique organisation/pair constraint prevents duplicate reciprocal rows and self-links.

Both records must exist in the same organisation and have `merged_into_patient_id = null` when adding, correcting or reactivating. The Patient model has **no `is_active` field**; do not invent one. Lock both patient rows in ID order, matching `PatientMergeService::lockPair()`.

Recommended merge behaviour: retain the original pair/evidence, but suppress it from normal active Family and Consultation projections whenever either endpoint is merged away. Do not repoint it automatically through `PatientMergeLinks::groupIds()` or create new links to the kept record. Patient details offers patient-update users a generic notice when its own surviving direct links involve a merged endpoint: “A linked patient was merged. Review the current patient record before linking again.” An authorised user explicitly selects the kept record and creates a new direct link. The old row remains evidence. When the exact original endpoint is later unmerged, its original active direct link becomes visible again; no new row or relationship is inferred. Inactive links remain inactive. This behaviour was owner-approved on 26 November 2026.

A removal of a now-suppressed original pair should remain possible for an authorised updater so it cannot unexpectedly reappear after unmerge. Removal uses the original two IDs and locks, checks same organisation and both update/view policies, but allows merged endpoints solely for unlink. It creates no new relation and never retargets a row. UI labels this as removal of the original record link and does not reveal extra identity data. The new-link path still accepts only current canonical records. No merge blocker, merge-service mutation or automatic family transfer is introduced.

## Writes, retries and audit

One transaction: lock current actor/permission and active branch-assignment state with existing `TransactionalActorAuthority`, then both patients ascending ID, then pair row. `expected_branch_id` protects the staff context; patient records remain organisation-owned, never branch-owned. Under locks recheck active actor, existing patient update/view policies for both IDs, organisation and merged-state rules. Normalise to ordered pair and fixed relationship code.

Commands: `link`, `correct`, `unlink`; UUID idempotency key and expected link version are mandatory. First create expects0; each state transition increments version. Unlink retains an inactive pair row (tombstone). Relinking an inactive pair reuses it with its latest version. Correct requires an active pair. Unlink requires an active pair but may target merged endpoints as above. Separate-key duplicate create is refused as already changed; it does not produce another link/audit.

The append-only command table records actor/key, keyed HMAC of canonical organisation/pair/action/code/expected-version payload, result version and resulting relation/active state. Check exact retry **before** stale-version refusal, but **after** current authority and endpoint checks. Exact retry performs no mutation and returns current authorised pair state/version, not the original response. A prior link/correct command retried after either endpoint is merged is refused by the current canonical-endpoint rule, even when its key matches; no mutation occurs. An unlink retry may still resolve after merge because unlink explicitly permits merged endpoints, provided both current view/update policies pass. Tests must distinguish these outcomes. Same key with changed target, action or relationship is refused. Reversing the UI endpoint order with the inverse label normalises to the same semantic command. Command/event records supply correction/unlink history; no extra event table is needed.

Audit subject is the family-link model, with `patient.family_link.changed`, pair IDs, action, version and changed field names only. No names, identifiers, phones, addresses, free text or clinical data in audit. Pair mutation, command evidence and audit commit together; audit failure rolls everything back. Existing Patient change-history UI/assertions are not expanded in this slice.

## Consultation projection: F3 exactly

Only add the Family card to a currently authorised live consultation (`ClinicalEncounterPolicy::view`, in-progress encounter and serving consultation queue entry, including a held consultation while it still satisfies current live-view rules). Do not inject a present-day card into historical/completed encounter views.

For each active direct link whose endpoints are canonical, query consultation `QueueEntry` rows for the linked patient, same organisation and active branch, `status = waiting`, `operational_date = today in the branch timezone`, visit type consultation and registered visit status. Exclude carry-over entries, serving/removed/completed entries and OTC. Match existing queue own/branch scope: `queue.view.branch` may view its branch; otherwise require `queue.view.own` and `assigned_doctor_user_id = actor.id`. Also apply `QueueEntryPolicy::view` and patient-view policy to each returned patient. Family membership never widens those checks.

Allowlist only relationship label, existing permitted patient display name/number, queue label and Patient details link. No conditions, allergies, diagnoses, medication, triage zone/readings, reason/chief complaint, phone, payment or treatment-room fields. The row link is Patient details, not Start/Call In or another doctor's encounter. A “Family record” link points to the current patient's Patient details Family links section if patient-view is allowed. Empty state says “No linked relatives waiting in your permitted queue today”; do not disclose that excluded relatives exist or show an unfiltered count. Sort by queue time and bound the visible list (proposed five rows, with a link rather than silently claiming completeness).

Do not call `PatientMergeLinks::groupIds()` for family inference. Existing merge rules already block merging a patient with unfinished visits; this slice adds no cross-merge clinical aggregation. Reads remain no-store and re-evaluate permissions/branch on every request. Queue ordering/calls/assignment and Treatment room remain unchanged.

## UI sketch for designer review

Patient details: compact Family links card after independent guardian/emergency sections. Empty state and Add relative (only permitted updater). Manual existing-patient picker, explicit relationship select with example “This patient is the parent of [selected patient]”, confirmation summary, one Save action. Existing rows show relationship/name/number and permitted Patient link; Edit/Remove are secondary actions. Removal uses the existing themed confirm dialog and explains that patient/guardian/emergency records remain. Preserve drafts and show inline errors on stale/race/permission failure; no silent retry or overwrite. 390px stacks controls with44px targets; desktop can align picker and relationship when readable.

Consultation: small read-only Family card near patient context; no management controls. Loading/empty/error copy must not show hidden counts or stale cross-branch data. Changing patient or branch clears in-flight results and ignores late responses. Family link data is never stored in localStorage/sessionStorage.

## Concrete file/API scope

- New `PatientFamilyLink` model and `PatientFamilyLinkService` write/read projection.
- New private GET `/patients/{patient}/family-links` and POST `/patients/{patient}/family-links/commands`; server scope/permissions above, throttled writes. Body includes selected other patient number, operation/code, expected version, key and expected branch. In unlink suppressed-link mode accept the already-known immutable link ID and verify current patient is an endpoint; never accept arbitrary foreign IDs.
- Patient details projection/card reuses existing patient picker; no new Patient create/registration mutation in this slice.
- A dedicated `FamilyWaitingProjection` consumed by current `ClinicalEncounterDirectoryService::detail`; independent allowlisted payload, no broad queue row serialization.
- Exact approved migration: `database/migrations/2026_11_26_020000_create_patient_family_links.php`.

## Tests before implementation is called ready

New backend cases: direction/inverse mapping; self/same-org/permission/direct-grant denial; reciprocal pair duplicate; update/unlink/reactivation stale versions; exact replay/current-state response; altered target/action/mode/key refusal; audit redaction/failure rollback; optional phone never used for suggestions; merge/unmerge visibility and unlink-original rules; source records unchanged. Actual PostgreSQL races: simultaneous first link, reciprocal creation, correction/unlink, merge/link, exact replay. Reuse existing READY/GO+observer harness and add suite registration without changing existing assertions.

Projection tests cover own doctor versus branch reader, other doctor/branch/organisation, today timezone boundary, carry-over, OTC/serving/completed exclusion, historical consultation absent, merged endpoints, phone/clinical-field absence and no unfiltered counts. Frontend/browser tests cover manual selection, empty/read-only controls, direction wording, discarded late response, errors/focus and390/1440 light/dark. List any existing assertions needing changed expectations before editing; none changed now.

## Consolidated owner batch — approved 26 November 2026

F1–F3 remain settled. The owner approved these newly specified defaults together:

1. Direct reciprocal pairs, one active relationship per pair, five generic relationship labels/inverses, existing patient-view for read and patient-update on both endpoints for writes; no transitive/legal authority.
2. Merge/unmerge rule: retain and suppress merged-endpoint links, no automatic transfer; explicit relink to kept patient, original active link can reappear on unmerge unless explicitly unlinked (unlink original permitted under both endpoint policies).
3. Exact two-table migration and append-only command trigger in the companion review document, with non-destructive down. No permission migration.

Coordinator obtained approval after independent schema/locking and designer review. No family-bill capability, new role, new legal authority or deployment is bundled into this approval.

## Implementation authorisation

Owner approved all three consolidated choices on 26 November 2026, relayed by coordinator: direct five-label single active pair; retained/suppressed merged links with no automatic transfer and original active relation returning on unmerge unless unlinked; exact two-table non-destructive migration. F1–F3 unchanged. No existing assertion changes authorised.
