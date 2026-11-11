# 04 Retention schedule (DRAFT)

The PDPA's retention principle: do not keep personal data longer than needed for the purpose, and delete or
anonymise it when the purpose is over **[CONFIRM wording]**. Medical, tax and employment law can require longer.
**Do not guess a number. Take each period from the official source, write the source beside it, and record who decided.**

## What KPOne already does by itself

| Data | Rule built into KPOne | Notes |
|---|---|---|
| Pending QR check-in (not yet accepted) | **Deleted 30 days** after submission (`public-intakes:cleanup`, daily) | Fixed in code |
| After a CA accepts a QR check-in | Copied into the patient record, which is **not** auto-deleted | Say so in the notice (it does) |
| Uploaded Panel statement files | **Deleted 30 days** after upload (`panel-statements:purge`, daily) | Row and counts kept |
| Leave evidence files (MC photos/PDF) | Deleted when the leave type's evidence retention has passed, counted from the **end date of the leave** (clinic sets 1 to 3,650 days per type), or **30 days** after a rejected or cancelled request (`hr-leave-evidence:purge`, daily) | With no retention set for the type, files on pending or approved requests are **kept indefinitely**. The request rows and the file history are kept |
| Staff documents | Deleted after the **document type's retention**, counted from the end of the person's latest employment. A first or shorter retention waits 7 days before it can act (`hr-documents:purge`, daily) | "Not set" keeps everything. Credential evidence is a staff document (category Certificate) and follows this rule. Someone still employed (an open employment period) is never reached. Rows and the open history are kept; only the bytes are deleted |
| Job applications | Cleared when the clinic-entered retention has passed (`hr-applications:purge`, daily), counted from closing for a closed application, from **submission for one left open**, and for a talent-pool candidate (HR-17e, not merged yet) from the later of the retention and the pool period. The purge clears name, phone, email, message, form answers and the CV file, the private link hash, the screening notes, and the name and email on a staff-account request (an open request is cancelled first) | Kept: the row, its stages and dates, the event history, the list of messages sent (no text), the interview time choices and the interview-document download records. The public form refuses applications until a retention period is saved, so "not set" never holds applications |
| Location-check results at clock-in (inside, outside or not available; accuracy band; HR-16a) | **Deleted 90 days** after the press (`hr-location-checks:purge`, daily; owner decision 2026-11-11). PostgreSQL refuses any other delete | The clock press, the consent acknowledgements and the settings versions are kept with the attendance records |
| Sessions | Expire after 120 minutes of inactivity (default) | |

## What KPOne does **not** delete (you must decide)

| Data | Today | Decide |
|---|---|---|
| Patient master and clinical records | Kept indefinitely | Period from MOH/MMC rules **[CONFIRM]**; then who deletes or anonymises, and how |
| Invoices, receipts, payments, refunds | Kept (append-only) | Tax/accounting period, commonly several years **[CONFIRM with the accountant or LHDN]** |
| Issued MC / referral / attendance copies (encrypted snapshots) | Kept | Same as patient records |
| Signed paper MC copies | Owner set **1 year** | Probably too short against medical-record rules **[CONFIRM and update]** |
| Staff employment records | Kept | Employment and tax law periods **[CONFIRM]** |
| Payroll, payslips | Kept, append-only: pay profiles (including the unencrypted MTD details), runs, lines, totals and encrypted payslip snapshots. There is no purge | Statutory period **[CONFIRM with the accountant]** |
| Leave requests and the leave ledger | Kept, append-only | Employment-law period **[CONFIRM]** |
| Attendance: clock sessions and their events, corrections, overtime requests, and location-check consent acknowledgements (HR-3a, HR-3b, HR-16a) | Owner decision 2026-11-11: **7 years**, then deleted by a daily `hr-attendance:purge` (being built; until it merges nothing is deleted). The settings versions are kept | The 7 years is **to confirm with the accountant before real use** |
| Staff claims and their **receipt files** | Kept; no purge exists | Tax and accounting period **[CONFIRM with the accountant]** |
| Credential records | Kept | **[DECIDE]** |
| Bank detail versions and record corrections (old and new values, encrypted) | Kept | With the staff file |
| Memos (body, PDF) and each person's opens and reads (HR-8) | Kept; no purge | **[DECIDE]** |
| Talent-pool settings and pool choices; staff-account requests (values cleared by the application purge) | Kept | With applications |
| Interview documents (clinic files) and candidates' download records | Files kept until HR removes them; records kept | **[DECIDE]** |
| Payroll export log | Kept (counts only; files never stored) | With payroll |
| Audit trail | Append-only, no purge defined | Period; consider archiving rather than deleting **[DECIDE]** |
| Backups | **Not defined** | Retention and deletion of old backups **[DECIDE]** |
| Application logs | Not defined | Keep short, and make sure they hold no personal data **[DECIDE]** |
| Staff and candidate message logs | Kept, holding template, status and ids only, never text. The emails themselves stay in the mail provider's logs and the recipients' inboxes | **[DECIDE, and check the provider's retention in 07]** |

## Table to complete and approve

| Data | Retention | Source of the rule | Decided by | Date |
|---|---|---|---|---|
| Adult patient record | [ ] years after last visit | [MOH / MMC] | | |
| Minor patient record | [ ] years after age of majority or last visit | | | |
| Invoices and receipts | [ ] years | [accountant / tax] | | |
| Signed paper MCs | [ ] years | | | |
| Staff file | [ ] years after leaving | | | |
| Payroll records | [ ] years | | | |
| Location-check results | 90 days | owner | Owner | 2026-11-11 |
| Attendance records | 7 years | owner; accountant to confirm | Owner | 2026-11-11 |
| Job applications (not hired) | [ ] months | clinic choice | | |
| Leave evidence | [ ] days | clinic choice | | |
| Staff claims and receipts | [ ] years | accountant / tax | | |
| Talent pool (after closing) | [ ] days | clinic choice | | |
| Memos and read records | [ ] years | | | |
| Audit trail | [ ] years | | | |
| Backups | [ ] days | | | |

## Operating the schedule in KPOne

- Enter the staff document, leave evidence, job application and talent-pool periods in the HR screens once approved.
  Until a leave type has an evidence retention, its files are kept.
- Make sure the **scheduler** is running in production, or the daily purges never happen.
- Patient-record deletion or anonymisation after the period has no automatic path yet. If you need it, ask for a
  small, separately approved change. Do not delete rows by hand in the database.
