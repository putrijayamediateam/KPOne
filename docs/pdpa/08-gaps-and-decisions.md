# 08 Gaps and decisions (read this first)

A short list of what only the owner can decide, or that needs a code or setup change. Items are sorted by what blocks real data.

## A. Decisions for the owner

| # | Decision | Why it matters |
|---|---|---|
| 1 | **Who is the data protection officer / contact?** | **Decided (owner, 2026-11-10): Data Protection Officer: HR Manager** (role; the owner keeps the name and contact outside the repository). Every notice and procedure names the role. Check whether the DPO must be registered with the Commissioner **[CONFIRM]** |
| 2 | **Registered company name and branch addresses** for the notices | Placeholders in 02 |
| 3 | **Hosting provider and country** | Drives the cross-border answer and the notice |
| 4 | **Retention periods** (table in 04), especially patient records and signed paper MCs (1 year is probably too short) | Cannot go live without them |
| 5 | **Waiting-room TV: number only, short name or full name** | Names are visible to the whole room. The notice and signage must say so |
| 6 | **Teenager attending alone, and guardian identity checks** | Procedure 03 |
| 7 | **Who answers requests, and how a doctor gives a patient a copy of clinical notes** | Procedure 06 |
| 8 | **How to flag "do not contact by WhatsApp"** | KPOne has no contact-preference field |
| 9 | **Do you ever want patient messaging?** | It is not authorised; it would need its own review |
| 10 | **Will the owner use "Ask KPOne" with real data?** | Masked results still reach Anthropic through Claude. Keep it synthetic until reviewed |
| 11 | **Public job application form: turn on or leave off** | Off by default; needs the applicant notice approved first, including the optional talent-pool tick wording (each tick stores its wording version) and whether the notice needs a pool paragraph |
| 12 | **Email, SMS and WhatsApp providers for staff messages** | None chosen |
| 13 | **MTD disability flags on pay profiles: encrypt, or hold outside KPOne?** | They are health data and are stored unencrypted today (see 01, B) |
| 14 | **A handling rule for HR files downloaded from KPOne** (payroll bank and accountant CSVs, outside HR export) | **Decided (owner, 2026-11-11, option b):** only the HR Manager and the Director download the payroll bank file and the accountant file; every download is audited (who, when, which run and file; never the contents); the files come password-protected (the downloader types the password, KPOne never stores it; being built). Handling rule: keep downloaded files only in **one locked company folder**; after sending to the bank or the accountant, **delete the copies on the laptop and in email**; **never forward a file unprotected**. Send the password by a different channel from the file. See also 05 |
| 15 | **Retention for HR records KPOne never deletes** (leave, attendance, claims and receipts, credentials, memos, interview documents) | **Decided (owner, 2026-11-11):** attendance, leave, claims with receipts, and credentials **7 years** (the accountant confirms the 7 years before real use); memos **kept**; interview records of unsuccessful candidates **6 months**. Daily purges are being built; see 04. Payroll retention, still to decide, must not be shorter than claims retention |
| 16 | **Location check at clock-in (HR-16a)**: approve the consent text (EN and BM), name the first branch and the radius, and decide how long results are kept | **Decided (owner, 2026-11-11):** results are kept **90 days**, and the check **stays off**. The consent text, the first branch and the radius are decided later, when the clinic wants to use it |

## B. Setup and security items (IT owner)

| # | Item |
|---|---|
| 1 | Production setting checklist: secure cookies on, `APP_DEBUG` off, a production log level (not `debug`), trusted proxies set, scheduler running, HTTPS only. The example file has development defaults |
| 2 | Encrypted, tested backups with a defined retention and a witnessed restore |
| 3 | Server and database access list, with named people and a way to revoke |
| 4 | Provider keys only in the server's secret settings, never in code or a `.env` kept in the repo |
| 5 | An outside security test before real data (separate from this pack) |
| 6 | Dependency advisories still open: phpseclib (runtime), symfony/yaml (dev) and npm items |
| 7 | At least a second Director or a named deputy, so approvals do not depend on one account |

## C. Possible code changes (ask separately; each is its own small, reviewed change)

| # | Change | Why |
|---|---|---|
| 1 | Replace the draft QR notice text and version with the approved text | Notice approval |
| 2 | A patient contact-preference flag | Withdrawal of WhatsApp contact |
| 3 | A reviewed "export one person's data" tool | Faster access requests |
| 4 | A reviewed anonymise or delete path for patient records past retention | Retention duty |
| 5 | Audit-log archiving rule | Retention |
| 6 | Optional alert on unusual access | Breach detection |
| 7 | Encrypt the MTD details on pay profiles, if decision 13 says so | Health data |
| 8 | A purge for staff claim receipts and memo files once their periods are decided | Retention |

## D. Sign-off record

| Document | Version | Approved by | Date |
|---|---|---|---|
| Patient notice | | | |
| Staff notice | | | |
| Applicant notice | | | |
| Consent procedure | | | |
| Retention schedule | | | |
| Breach plan | | | |
| Requests procedure | | | |
| Provider register | | | |

Re-check every **[CONFIRM]** against the official source on the date of approval and write the date here: ________
