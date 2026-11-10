# 08 Gaps and decisions (read this first)

A short list of what only the owner can decide, or that needs a code or setup change. Items are sorted by what blocks real data.

## A. Decisions for the owner

| # | Decision | Why it matters |
|---|---|---|
| 1 | **Who is the data protection officer / contact?** | The amended Act expects one **[CONFIRM]**; every notice and procedure names them |
| 2 | **Registered company name and branch addresses** for the notices | Placeholders in 02 |
| 3 | **Hosting provider and country** | Drives the cross-border answer and the notice |
| 4 | **Retention periods** (table in 04), especially patient records and signed paper MCs (1 year is probably too short) | Cannot go live without them |
| 5 | **Waiting-room TV: number only, short name or full name** | Names are visible to the whole room. The notice and signage must say so |
| 6 | **Teenager attending alone, and guardian identity checks** | Procedure 03 |
| 7 | **Who answers requests, and how a doctor gives a patient a copy of clinical notes** | Procedure 06 |
| 8 | **How to flag "do not contact by WhatsApp"** | KPOne has no contact-preference field |
| 9 | **Do you ever want patient messaging?** | It is not authorised; it would need its own review |
| 10 | **Will the owner use "Ask KPOne" with real data?** | Masked results still reach Anthropic through Claude. Keep it synthetic until reviewed |
| 11 | **Public job application form: turn on or leave off** | Off by default; needs the applicant notice approved first |
| 12 | **Email, SMS and WhatsApp providers for staff messages** | None chosen |

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
