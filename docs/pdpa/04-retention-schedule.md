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
| Leave evidence files (MC photos/PDF) | Deleted after the **leave type's retention** (clinic sets 1 to 3,650 days), or **30 days** after a rejected or cancelled request | KPOne ships no number for the clinic's setting |
| Staff documents | Deleted after the **document type's retention**, counted from the end of the person's latest employment. A first or shorter retention waits 7 days before it can act | "Not set" keeps everything |
| Job applications (closed) | CV and personal details deleted after the **clinic-entered retention** | "Not set" keeps everything |
| Sessions | Expire after 120 minutes of inactivity (default) | |

## What KPOne does **not** delete (you must decide)

| Data | Today | Decide |
|---|---|---|
| Patient master and clinical records | Kept indefinitely | Period from MOH/MMC rules **[CONFIRM]**; then who deletes or anonymises, and how |
| Invoices, receipts, payments, refunds | Kept (append-only) | Tax/accounting period, commonly several years **[CONFIRM with the accountant or LHDN]** |
| Issued MC / referral / attendance copies (encrypted snapshots) | Kept | Same as patient records |
| Signed paper MC copies | Owner set **1 year** | Probably too short against medical-record rules **[CONFIRM and update]** |
| Staff employment records | Kept | Employment and tax law periods **[CONFIRM]** |
| Payroll, payslips | Kept | Statutory period **[CONFIRM with the accountant]** |
| Audit trail | Append-only, no purge defined | Period; consider archiving rather than deleting **[DECIDE]** |
| Backups | **Not defined** | Retention and deletion of old backups **[DECIDE]** |
| Application logs | Not defined | Keep short, and make sure they hold no personal data **[DECIDE]** |
| Messages sent (staff email/SMS) | Sent list kept | **[DECIDE]** |

## Table to complete and approve

| Data | Retention | Source of the rule | Decided by | Date |
|---|---|---|---|---|
| Adult patient record | [ ] years after last visit | [MOH / MMC] | | |
| Minor patient record | [ ] years after age of majority or last visit | | | |
| Invoices and receipts | [ ] years | [accountant / tax] | | |
| Signed paper MCs | [ ] years | | | |
| Staff file | [ ] years after leaving | | | |
| Payroll records | [ ] years | | | |
| Job applications (not hired) | [ ] months | clinic choice | | |
| Leave evidence | [ ] days | clinic choice | | |
| Audit trail | [ ] years | | | |
| Backups | [ ] days | | | |

## Operating the schedule in KPOne

- Enter the document, leave-evidence and application retention periods in the HR screens once approved.
- Make sure the **scheduler** is running in production, or the daily purges never happen.
- Patient-record deletion or anonymisation after the period has no automatic path yet. If you need it, ask for a
  small, separately approved change. Do not delete rows by hand in the database.
