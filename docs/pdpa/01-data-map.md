# 01 Data map (DRAFT)

Built from the KPOne code and docs at `main` on 2026-11-10. It describes what the system is designed to hold.
No real data is held today. **[CONFIRM]** items need a person to check them.

## A. Patients

| Data | Why it is collected | Who can see it in KPOne | Protection in KPOne |
|---|---|---|---|
| Name, sex, date of birth (optional), nationality | Identify the patient, register the visit | Director, Resident Doctor, CA, CA Supervisor (`patients.view.organisation`). Search results are masked | Role and branch checks on the server; change history for CA Supervisor and Director |
| IC or passport number | Identify the patient, avoid duplicates | Full value only with the view permission; never in lists, search or audit | Encrypted; a keyed hash is used to detect duplicates |
| Phone, email, address (optional per branch setting) | Contact, registration record | Holders of patient view | Encrypted where stored in a pending QR intake |
| Guardian contact for a minor (name, relationship, phone, optional email) | Reach the guardian of a patient under 18 | Same as patient view | Encrypted; never used for WhatsApp, search or matching |
| Visit reason, queue number, priority, doctor | Run the visit and queue | CA, CA Supervisor, own doctor | Branch-scoped |
| Allergies, problem list | Clinical safety | Doctor; CA sees allergy list at Dispensary | Versioned ledger; no technical-admin access |
| Clinical notes, vitals, diagnoses | Care | **Only the attending Resident Doctor** (own encounter), plus bounded history for a current care relationship | Highest restriction; Directors, CAs and technical admins have none |
| Medicines ordered and dispensed, labels | Dispensing and safety | Doctor, CA, CA Supervisor | Immutable stock movements; label print log (no label content stored) |
| Invoices, payments, refunds, Panel responsibility, pay-later | Billing | CA, CA Supervisor, Finance Officer, Director, Panel Officer (invoice lines) | Append-only records; second-person approvals; audit |
| Panel member reference | Panel claims | Panel Officer, CA at registration | Never listed; encrypted in QR intake |
| Medical certificate, referral letter, attendance confirmation | Documents the patient asked for | Issuing doctor; CA copies for MC and attendance | Encrypted immutable snapshots. Wording is DRAFT until a doctor approves it |
| Panel claim documents (photos), Panel statement files | Claims and payment matching | Panel Officer, Finance Officer | Private disk; statement files auto-deleted after 30 days |
| Public QR check-in (before acceptance) | Patient self-registers | CA reviewing at that branch | Encrypted; **deleted after 30 days if not accepted** |
| QR location (optional) | Sort nearby branches | **Nobody.** It stays in the patient's browser and is never sent to KPOne | By design |

## B. Staff and locums (KPOne HR)

| Data | Why | Who sees it | Protection |
|---|---|---|---|
| Name, work email, role, branch, status | Run the system, access control | Director, HR Manager, Technical Admin (administration); directory per role | Role and branch checks; audit of access changes |
| IC or passport, date of birth, nationality, address, phone, emergency contact | Employment file | HR Manager, Director; staff see their own | IC encrypted with keyed hash; sensitive values masked until an audited "Show" |
| Bank account | Pay staff | HR Manager, Director after an audited Show | Needs confirmation by a person other than the enterer and the staff member |
| Pay profile, salary, allowances, payslips | Payroll | HR Manager, Director; staff see their own | Encrypted; never in audit; the Director's and HR Manager's own pay is kept outside KPOne in v1 |
| Leave (type, reasons, MC files), attendance times, overtime | HR administration | Director, HR Manager; staff see their own | Reasons encrypted; MC files re-encoded, encrypted, opened only with an audited event |
| Credentials, licences, health items | Compliance | Director, HR Manager | Health items hold only Done or Not done and a date |
| Staff documents | Employment file | Director, HR Manager; own documents to the staff member | Encrypted; every open recorded |
| Staff claims (receipts) | Reimbursement | Approvers and payers | Receipts on the private disk |

## C. Job applicants (public form, off by default)

Name, contact details, CV file, answers to screening questions and hiring-step notes. Seen only by HR users.
The public form is **off** until the privacy notice is approved (`config/hr-applications.php`).

## D. Other

| Data | Notes |
|---|---|
| Audit trail | Structural facts (who, what, when, ids and codes). **By rule it excludes credentials, tokens, cookies and secret fields, and sensitive text.** |
| Waiting-room TV | Shows queue numbers by default. Per branch it can show a short name or full name, which the whole waiting room can see. **A privacy decision is needed before go-live (see 08).** |
| WhatsApp click-to-chat | A staff member opens `wa.me` with the patient's saved number. KPOne sends nothing and stores no message, but WhatsApp/Meta then handles the conversation |
| Staff messages (email, SMS, WhatsApp, OTP) | Authorised for staff and HR only; email is off by default. No provider is chosen yet |
| "Ask KPOne" | The owner can ask Claude about masked data (initials, last 4 ID characters). Results reach Anthropic through the owner's Claude use. **Synthetic data only until reviewed [CONFIRM before any real data]** |
| Website drafts | Doctor profile content with consent flags. Nothing is public yet |
| Sign-in | Staff sign in with a password or Google. Unknown Google accounts never create a user |

## E. Where data physically lives (to complete)

| Question | Answer today |
|---|---|
| Hosting provider and country | **Not chosen. [DECIDE]** |
| Database | PostgreSQL 18, one database |
| Files (photos, documents, statements) | Private disk. `FILESYSTEM_DISK=local` in the example settings; production storage not decided |
| Backups: where, how often, how long, encrypted | **Not defined. [DECIDE]** |
| Application logs | The example setting is `LOG_LEVEL=debug`; production must use a higher level and logs must never hold personal data **[CONFIRM in production]** |
| Who can reach the server and the database | **[DECIDE and list names]** |
