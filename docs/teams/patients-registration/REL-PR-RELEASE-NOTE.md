# REL-PR: release the approved wording (MC, referral, attendance) and the approved QR privacy notice (release note, nothing built)

Status: NOTE ONLY. Build waits for the owner's approval of this note, and after AUTO-1 (PR #1099) has merged.

## 1. What the owner decided (25 Nov 2026, relayed by B.E.R.N.I.S.S., verbal, not in writing)

- The clinic's doctor approved the wording of the Medical Certificate, the Referral letter and the Attendance confirmation,
  English and Bahasa Melayu: "semua okay kalau yang ini dr dah setuju".
- The lawyer and the PDPA review approved the QR privacy notice: "dh approve".
- Approver, as it is to be recorded in the repo, with no name: "the clinic's doctor (owner-confirmed, verbal, 25 Nov 2026)"
  for the documents, and the same style for the privacy notice: "the lawyer and PDPA review (owner-confirmed, verbal,
  25 Nov 2026)".

This note authorises nothing by itself. It also does not change the other gates in BASELINE section 6 (signed-paper retention
years, real-data use, deployment): the approvals release wording only.

## 2. Why this needs a deliberate migration, not a flag

- `mc_template_revisions`, `referral_template_revisions` and `attendance_template_revisions` are constrained to
  unapproved rows: MC `CHECK (... AND NOT production_approved)`; referral and attendance `revision = 1`,
  `renderer_version = '...-a4-v1'` and `CHECK(NOT production_approved)`. MC rows also have an update-and-delete trigger
  (`mc_template_immutable`), so an existing row can never be flipped to approved.
- The snapshot code hard-codes the draft state: `MedicalCertificateSnapshot` writes `productionApproved => false`;
  `ReferralSnapshot` and `AttendanceSnapshot` read `revision = 1`; the three views print the "DRAFT WORDING" banner
  unconditionally (`resources/views/{medical-certificates/content.blade.php, content-v2.blade.php, referrals/content.blade.php,
  attendance/content.blade.php}`).
- Issued copies keep a frozen, encrypted snapshot of the HTML. They must not change: the DRAFT banner comes off NEW copies
  only; every copy issued before stays exactly as issued (it says DRAFT, and its template row stays).

## 3. Proposed design

**New approved revisions; no old row is touched.**
- MC: add revision 3 in each language, `renderer_version = 'mc-a4-v2'` (the same layout the clinic already reviewed),
  `production_approved = true`, wording identical to revision 2 unless the owner says otherwise.
- Referral and attendance: add revision 2 in each language, `renderer_version` unchanged (`...-a4-v1`), `production_approved = true`,
  wording identical to revision 1.
- Relax the checks in the same migration, narrowly: allow an approved row only when it carries an approval record. Add
  two nullable columns, `approved_by_label` (varchar 160) and `approved_on` (date), with a CHECK that an approved row has
  both and an unapproved row has neither. Seed them with "the clinic's doctor (owner-confirmed, verbal, 25 Nov 2026)" and
  2026-11-25. No person's name goes in the repo.
- Keep every other constraint (wording length, no `<` or `>`, language, one row per language and revision, immutability
  trigger on MC; the matching retention rules on referral and attendance).
- `down()` refuses once any document used an approved revision (as MC slice 1 did), so evidence cannot be orphaned.

**Code (small, behind the new revisions):**
- `MedicalCertificateSnapshot`: `productionApproved` and the banner come from the template row, not a constant.
- `ReferralSnapshot` and `AttendanceSnapshot`: use the newest revision per language instead of `revision = 1`.
- The three blade views: print the DRAFT banner only when the snapshot says `productionApproved` is false. Old snapshots
  already hold their own HTML, so they are unaffected.
- The history and preview screens that show "EN revision N · renderer" follow the new revision automatically.

**QR privacy notice:**
- `config/public-intake.php` `privacy_notice_version` changes from `uat-draft-set04b-email-address-v2` to an approved
  string (proposal: `approved-2026-11-25-v1`; Q3). `LEGACY_PRIVACY_NOTICE_VERSION` (`uat-draft-2026-09-19-v1`, for sessions
  that never recorded one) stays, because stored sessions and intakes refer to it.
- The public form heading "Notis privasi — draf UAT" (`resources/js/pages/PublicCheckIn/Show.vue`, two places) loses the
  "draf UAT" wording. The notice text itself changes only if the lawyer's approved text differs from today's (Q2).
- A QR session opened before the deploy keeps the version it was shown (existing behaviour: a correction under a changed
  notice version is handled by the existing check). Nothing is backfilled.
- Not in this change: turning public QR on in production (`PUBLIC_PATIENT_INTAKE_ENABLED`), trusted proxies, the scheduler
  and rotating QR links remain the deployment gate (BASELINE section 6 item 2).

## 4. Existing assertions this would change (for the owner's approval)

Each currently encodes "still DRAFT". I would change only these, and only to state the new rule:

Backend:
- `tests/Feature/Clinical/MedicalCertificateTest.php`: line 89 and line 496 (`assertFalse($snapshot['productionApproved'])` for a
  new certificate becomes true); line 130 and line 498 (`assertSee('DRAFT WORDING')` on a new copy becomes `assertDontSee`;
  the test at line 484 "a new certificate uses the DRAFT paper layout revision 2" becomes revision 3); line 585 (the list of
  renderer versions gains a third row); line 586 (the "database still refuses approved or mismatched rows" check keeps
  refusing an approved row WITHOUT an approval record and a changed revision 1 or 2).
- `tests/Feature/Clinical/ReferralTest.php`: lines 251, 282, 386, 407 (DRAFT banner and `productionApproved` on new copies).
- `tests/Feature/Clinical/AttendanceTest.php`: lines 414 and 419 (the same).
- `tests/Feature/Clinical/ClinicianCredentialsTest.php`: the MC template preview expects revision 2, renderer `mc-a4-v2` and 4
  template rows; it becomes revision 3 and 6 rows.
- `tests/Feature/Patient/RegistrationContactSettingsTest.php` line 210 sets its own test-only version and is unaffected.

Frontend:
- `tests/Frontend/medical-certificates.test.mjs` line 61, `tests/Frontend/referrals.test.mjs` line 42,
  `tests/Frontend/attendance.test.mjs` line 33 (the "DRAFT WORDING" patterns): the banner becomes conditional.
- `tests/Frontend/classic-only-co7-kept-attendance-confirmation.test.mjs` and `classic-only-co8-kept-referrals.test.mjs`
  (they keep those pages' text): the same pattern, if they pin it.
- `tests/Frontend/registration-contact-settings.test.mjs` line 61 (`uat-draft-set04b-email-address-v2` in the config).

New tests (not changes): an approved revision is used for new documents; an old frozen snapshot still prints DRAFT; the
database refuses an approved row with no approval record and any update or delete of a template row; `down()` refuses once
used; a new QR session records the new version while a session opened earlier keeps its own.

## 5. Questions for the owner (tick one each; * = my recommendation)

Q1 Is the approved wording exactly what is in KPOne today (so the new revisions copy it unchanged)?
 [*] Yes, identical; the doctor approved what is on screen
 [ ] No, the doctor changed words: send the final text and I use it (the note then lists the new wording)
Q2 Is the QR privacy notice text exactly what is in KPOne today?
 [*] Yes, only the "draft" label and version string change
 [ ] No, the lawyer approved different text: send it
Q3 The approved QR version string:
 [*] approved-2026-11-25-v1
 [ ] Another string: ____ (the lawyer's own reference if there is one)
Q4 The approvals were verbal. Should the repo also keep a one-line evidence reference next to the approver label?
 [*] Yes: "owner-confirmed in the coordinator session, 25 Nov 2026" in the migration comment and the release note
 [ ] No, the approver label alone is enough
Q5 Wet signature on paper stays as today (the documents are pre-signature, signed by hand): confirm nothing about signing or
 retention changes with this release?
 [*] Confirmed, unchanged (signed paper kept 1 year, to confirm with the accountant, BASELINE section 6 item 17)
 [ ] Something changes: ____
Q6 When does it ship?
 [*] After AUTO-1 (PR #1099) merges, as its own PR, with the Lead UI/UX Designer's look at the three printed copies
 [ ] Together with something else: ____

## 6. Risks

- The approvals are verbal. The migration records them as stated, labelled "owner-confirmed, verbal"; it does not turn them
  into written evidence, and a later written approval should replace the label.
- A document printed from an approved revision cannot be re-labelled DRAFT, and wrong wording would need a new revision.
  Hence Q1: confirm the text before the migration is written.
- The public QR is out of the pilot. Releasing the notice version changes the string recorded on new intakes only.
