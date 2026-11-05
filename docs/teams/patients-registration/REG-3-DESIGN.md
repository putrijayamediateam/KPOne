# REG-3 Merge duplicate patients: design note (draft for owner approval)

Status: **design approved by the owner on 2026-11-05 (A yes, B yes, C block, D to G as recommended); being built.** Written 2026-11-05 by the Patients & Registration team (Nurul) from a
survey of the database on `main` at `bf5b1b2`. Owner decisions already made: TEAM.md section 6, items 9 and 10
(two people, unmerge with the same approval, the merged-away record kept hidden and never deleted, every step
audited).

## 1. The problem, in plain words

Sometimes the same person is registered twice (for example once with an IC and once with a passport, or with a
mistyped IC). Staff then see two patient records, two visit histories and two allergy lists for one person.
REG-3 lets two staff members join them: one record is **kept**, the other is **merged away** (hidden, never deleted).

## 2. The main finding: we should link the records, not rewrite them

The backlog wording said "all visits, allergies, documents and invoices pointing to the kept record". The survey
shows that **KPOne's database deliberately refuses to change the patient on its evidence records**:

| Record | Protection found | Can its patient be changed? |
|---|---|---|
| Invoices | `kpone_invoice_immutable`: "Invoice identity is immutable" (patient included) | **No** |
| Receipts (payments) | `kpone_payment_immutable`: "Receipt facts are immutable" | **No** |
| Panel claims | `kpone_panel_claim_identity_immutable` (patient included) | **No** |
| Completed visits | `kpone_completed_visit_evidence` + checkouts and invoices link to the visit **with** its patient | **No** in practice |
| Medical certificates, referral letters, attendance confirmations | encrypted immutable snapshots that already contain the patient's name and identifier | **No** (and they should keep showing what was printed) |
| Adult confirmations, guardian commands | append-only, refuse any update | **No** |
| Allergy profile, guardian contact | **one per patient** (unique) | Two cannot both move to the kept record |
| Identifiers (IC / passport) | reserved organisation-wide, one current IC per patient | Moving can clash |

Rewriting would mean switching off these protections, which exist for financial and clinical safety. **I
recommend we do not.**

**Recommended design: "link, don't move".** Nothing is rewritten. The merged-away record gets a pointer to the
kept record. Every staff screen that shows a patient's history reads **both** records, and every screen that
looks up a patient lands on the kept one. Old invoices, receipts, claims and documents keep the KP number and
name they were issued with, which is what an auditor expects. **Unmerge is exact and safe**: it removes the
pointer, and nothing has to be moved back.

## 3. What the staff see after a merge

| Area | After the merge |
|---|---|
| Patient search, Registration picker, duplicate check, QR acceptance match | Show only the kept record. Searching the old KP number, IC, passport or phone finds the **kept** record, with a note "merged from KP-xxxx". |
| Opening the merged-away patient page | Redirects to the kept record with a banner. |
| Registering a visit | Always on the kept record; registering on the merged-away record is refused by the server. |
| Patient page: visit history, Invoices page, outstanding bills, pay-later debts | List visits and invoices of **both** records, each labelled with its original KP number. |
| Old invoices, receipts, Panel claims, MC, referral, attendance prints | Unchanged: they still show the details they were issued with. |
| Reports and Insights | Old rows keep the old KP number (historical). Distinct-patient counts for past periods may still count the person twice; recorded as a known limit. |
| WhatsApp click-to-chat | Uses the kept record's phone only. |
| Audit | Merge, approve, reject, withdraw and unmerge are recorded with record ids, who and when only; **no names, IC, phone, address or clinical text**. |

## 4. Safety rules (refusals the server enforces)

A merge is **refused** when:

1. The two records are the same, belong to different organisations, or either is already merged (no chains).
2. **Either record has a visit that is not finished** (registered, waiting, being served, at dispensary or
   billing) or a pending QR intake. Finish or cancel it first.
3. **The merged-away record has active allergies** that the kept record does not already show. This is the most
   important safety rule: today every allergy check (doctor's review, Dispensary allergy warning DSP-1) reads the
   kept record only, so an allergy left on the hidden record could be missed. A doctor first records the allergy on
   the kept record; then the merge can go ahead. (See decision C for the alternative.)
4. The approver is the proposer, or lacks the approve permission at the time of approval.
5. Anything changed between proposal and approval (the records' versions are checked again under row locks).

Problem-list entries and the guardian contact of the merged-away record stay readable on the kept record's page
(marked "from merged record"); nothing is copied automatically. Name, date of birth, phone, email and address on
the kept record are **not** changed by a merge; staff edit the kept record normally if needed.

## 5. The flow

1. **Propose** (CA or CA Supervisor): from the kept patient's page, choose the duplicate, pick a reason from a short
   list (for example "Same person, registered twice", "Wrong IC typed") and see the side-by-side comparison
   (masked identifiers, as on the search screen). Nothing changes yet.
2. **Approve or reject** (a different CA Supervisor or Director): sees the same comparison and the safety checks,
   then approves or rejects. Approval links the records in one transaction. The proposer can withdraw before
   approval.
3. **Unmerge** (same two-person rule): propose unmerge, a different approver approves. Both records are visible
   again exactly as before. Visits registered on the kept record after the merge stay with the kept record.

## 6. What would be built (for sizing; not started)

- **New tables:** `patient_merge_requests` (proposal, approval or rejection, kind merge or unmerge, reason code,
  idempotency key, `lock_version`) and `patient_merges` (append-only merged and unmerged events). Patients gain a
  nullable `merged_into_patient_id` that only the merge service sets. PostgreSQL guards: no self-merge, no chains,
  one active merge per record, approver different from proposer, events never updated or deleted.
- **New narrow permissions** (one owner-approved additive migration): `patients.merge.propose.organisation` for
  CA and CA Supervisor, `patients.merge.approve.organisation` for CA Supervisor and Director. Neither is staff or
  access authority.
- **One domain service** (`PatientMergeService`) with permission checks, `DB::transaction`, row locks on both
  patients, `lock_version`, idempotency and audit.
- **Read changes in other teams' areas** (small, read-only, each needs that team's agreement):
  Billing (Invoices page, outstanding bills, pay-later collection list), Clinical (previous-visit history on the
  consultation page), QR check-in (acceptance match), Panel (claim identity view shows the claim's own snapshot,
  unchanged). No change to any write path, invoice, receipt, claim or document.
- **Screens:** a "Merge duplicate" panel on the patient page, a "Merge requests" list for approvers, banners.
- **Tests:** two-person rule, every refusal above, unmerge restores exactly, search and redirects, both-record
  history lists, no identity values in audit, PostgreSQL guards and a contention test for two simultaneous
  approvals.

Rough size: one large PR for the Patient side, plus one small read-only PR per affected area, or one PR if the other
teams agree.

## 7. Decisions needed from the owner

| # | Question | Recommendation |
|---|---|---|
| A | **Link, don't move** (section 2) instead of rewriting invoices, receipts, claims and documents to the kept record? | **Yes, link.** Rewriting needs the financial and clinical protections switched off. |
| B | Refuse a merge while either record has an unfinished visit or pending QR intake? | **Yes.** |
| C | Allergies on the merged-away record: **block** until a doctor records them on the kept record (simple, safe), **or** make every allergy check read both records (touches the Clinical and Dispensary teams; larger and riskier)? | **Block.** |
| D | Who can propose and approve: as in decision 9 (propose: CA, CA Supervisor; approve: a different CA Supervisor or Director)? Should the Director also be able to propose? | Keep decision 9; Director may also propose. **Approved.** |
| E | Reason list: "Same person, registered twice", "Wrong IC or passport typed", "Other (explain to the approver in person)" with no free text? | Yes, no free text (keeps identity and clinical text out of the record). |
| F | Distinct-patient counts in past Insights may still count a merged person twice. Acceptable? | Yes, record as a known limit. |
| G | The two new permissions and the additive migration (section 6). | Approve. |

Once A to G are answered I will record them in TEAM.md section 6 and start building, Patient side first.

## 8. What was built (2026-11-05)

Built as designed, with these parts **not built yet** (each a candidate follow-up, recorded in TEAM.md section 7):
the "merged from KP-xxxx" note on search rows; the Invoices page, pay-later list and patient-page visit history
listing both records (outstanding bills and the doctor's previous consultations do follow the merge); the
duplicate's problem list and guardian contact shown on the kept record; a two-process PostgreSQL contention test.
WhatsApp on the duplicate's old registration rows still uses the duplicate's own phone. Rule 2's "pending QR
intake" is matched by the IC or passport the intake carries, because an intake has no patient until it is
accepted; accepting an intake onto a merged-away record is also refused.
