# FAM-1: family link (design note)

Status: built on `feature/fam-1-family-link` from the owner's tick-box answers of 27 Nov 2026, relayed by the
coordinator. Synthetic data only. Not production-approved.

## Owner answers (27 Nov 2026)

- **F1:** linking uses the existing `patients.update.organisation` permission. No new permission or role grant.
- **F2:** no automatic suggestion by shared phone number (or address). Staff link relatives by hand.
- **F3:** option B, in two parts.
  - **Part 1 (this slice):** the doctor's Consultation "Family" card lists relatives waiting today, each with a link to
    their Patient details.
  - **Part 2 (not built yet):** relatives' conditions, behind a setting that is OFF by default, with a "needs doctor and
    PDPA sign-off" note. No clinical data is exposed by default, and nothing in part 1 reads clinical data.

## Model (agreed with Billing, 25 Nov; `docs/teams/billing/BIL-39-DESIGN.md` section 1)

- `patient_families`: one row per family, no head and no payer, `lock_version`. Never deleted.
- `patient_family_members`: one row per membership with a generic role (Parent, Child, Grandparent, Other relative).
  A patient is in at most one family at a time (partial unique index on `patient_id` where `left_at` is null). Leaving
  stamps `left_at` and `removed_by_user_id`; the row is then final (PostgreSQL trigger). No delete.
- `patient_family_commands`: append-only retry evidence (actor, UUID key, keyed HMAC of the canonical command, result
  version).
- `visits.family_group_id` (nullable uuid, partial index on `(branch_id, family_group_id)`): the key Billing reads for
  the family bill. Patients & Registration writes it; Billing never does.

This replaces the direct-pair model in draft PR #1148 (`patient_family_links`, 26 Nov): the family bill needs a group
that more than two members share, which pairs cannot give without inferring relatives transitively.

## The family group key

- Family members registered at the same branch on the same branch-local day share one key: a UUIDv5 of the
  organisation, family, branch and local date (`FamilyVisitGroups`). It is computed, so registering one member never
  reads or locks another member's visit.
- Stamped at registration (Counter and QR acceptance both go through `VisitRegistrationService`) under the patient row
  lock, and when a patient joins a family: their still-registered visits of today **at the actor's active branch** that
  have no key (visits are branch-owned, so a visit at another branch is left alone). A stamped visit gets a new
  `lock_version` and `updated_by_user_id`, like every other visit writer.
- Never rewritten once set. Leaving a family does not clear it: "Remove from family bill" is Billing's own exclusion
  (BIL-39 section 4), and an old bill never changes.
- Visits registered before FAM-1 keep NULL.

## Commands and rules (`App\Domain\Patient\Family\PatientFamilyService`)

- `add`: link a relative into this patient's family. Neither in a family: a new family with both (both roles asked).
  One already in a family: the other joins it (their role asked). Both in families: refused ("Remove them from that
  family first"). Self-link refused. Merged-away records refused (use the kept record).
- `set_role`: change a member's place. `remove`: a member leaves; when one member would be left, the family closes for
  both (rows kept).
- Every command: the existing patient `view` and `update` policies on each patient it touches, `expected_branch_id`,
  `TransactionalActorAuthority` (active actor, current grants, assignment at the active branch), one
  `DB::transaction`, row locks in the order actor, patients (ascending id), family row, member rows, visit rows (the
  same patient-before-visit order as registration), the family `lock_version` the screen saw (0 when none), a UUID
  retry key (exact retry changes nothing; same key with a different command is refused), and one values-free audit
  `patient.family.changed` (action, patient ids, version, changed field names, number of visits grouped; never names,
  numbers, phones or roles' text). Audit failure rolls everything back.
- Reading the family needs `patients.view.organisation` and the view policy on each member shown.

## Screens

- **Registration (Register visit):** a Family panel under the chosen patient lists the family and, for staff with
  `patients.update.organisation`, offers "Link a relative" (normal name or Patient No. search, explicit choice and
  role), a role change and Remove (themed confirm). No phone search is offered in this panel.
- **Consultation:** a read-only Family card for the live consultation of a patient who is in a family (no card at all
  otherwise): relatives waiting today at this branch for a
  consultation, in the doctor's permitted queue (own patients, or the branch with `queue.view.branch`), up to five,
  with role, name, Patient No., queue number and a Patient details link. Carry-over, OTC, serving, other branches and
  relatives the viewer may not see are excluded and never counted.

## Open questions for the owner and Billing (from Hafiz's review)

1. **A visit keeps the key of a family the patient left the same day.** If P is linked to the wrong family, its visit
   today carries that family's key; removing P and linking P elsewhere does not move it, so the family bill would group
   P with the old family until "Remove from family bill" is used. Options: (a) Billing's exclusion covers it (current
   build, simplest); (b) on join, re-stamp a visit whose key belongs to a family the patient has left, while it has no
   finalised invoice (needs a read of Billing tables). A family closed and rebuilt the same day also gets a new key.
2. **A merged-away record stays a family member.** Options: (a) the merge ends the merged-away record's membership in
   the same transaction (touches `PatientMergeService`); (b) the merge is refused while the merged-away record is in a
   family; (c) leave it (current build): the record can be removed from another member's panel, and it never gets new
   visits.

## Not in this slice

Part 2 (relatives' conditions), the Billing family bill (BIL-39, Billing team), Patient details management, any
suggestion from shared contact details, legal guardian authority, transitive inference across families.
