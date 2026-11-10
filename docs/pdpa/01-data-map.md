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
| Legal name, IC or passport, date of birth, nationality, address, phone, emergency contact | Employment file | Director, HR Manager (`hr.records.view.organisation`); each staff member sees their own values unmasked. Nobody manages their own file; a Director's file only by a Director | Legal name, IC or passport, date of birth, address, personal phone and emergency contact are encrypted; the IC or passport also has a keyed hash for the duplicate check. Nationality and preferred name are stored in plain text. Values are masked until an audited "Show", and if the audit cannot be written, nothing is shown |
| Bank account | Pay staff | HR Manager, Director after an audited Show | Bank name, holder and account number are encrypted. A new or changed account counts only after someone other than the enterer and the staff member confirms it. Earlier versions are kept, encrypted |
| Pay profile, salary, allowances, payslips | Payroll | Preparers Director and HR Manager (`hr.payroll.prepare.organisation`) see salaries; the Director alone approves runs and exports; every staff member sees only their own payslips. The Finance Officer has no payroll access | Salaries and allowances encrypted, never in audit; payslips are encrypted frozen snapshots and each open is recorded. The Director's and HR Manager's own pay is kept outside KPOne in v1; locums are paid outside KPOne |
| MTD details on the pay profile: tax category (single, spouse not working, spouse working), number of children, whether the staff member or spouse is disabled | Monthly tax deduction (PCB) | Payroll preparers (Director, HR Manager) | **Stored unencrypted** (`hr_pay_profiles.mtd_category`, `mtd_children`, `mtd_disabled_self`, `mtd_disabled_spouse`). Disability is health data: see 08 |
| Leave requests (type, dates, reason, evidence reference) | HR administration | Approvers Director and HR Manager; staff see their own | Reason and evidence reference encrypted. The team calendar and the roster show only "On leave" or "half day", never the type or reason |
| Leave evidence files (MC photos and PDFs: health data) | Support sick or other leave | The applicant (own files); Director and HR Manager (`hr.leave.evidence.view.organisation`) | Re-encoded with EXIF removed, encrypted under a random name, original file name never kept. Every add, open, removal and purge is recorded |
| Attendance and overtime | Clock-in and clock-out, overtime claims | Director, HR Manager; staff see their own | Times for the active branch only. **No location, IP address, photo or device data is stored** (the optional selfie or location check, HR-16, is not built). Correction and overtime notes are encrypted |
| Credentials, licences, health items | Compliance | Director, HR Manager (`hr.credentials.manage.organisation`); each staff member sees their own | The reference number is encrypted (only the last 4 characters shown; Show is audited). Health items hold only Done or Not done and a date. Optional evidence is stored as an encrypted staff document (category Certificate) and follows the staff document retention |
| Staff documents | Employment file | Director, HR Manager; own documents to the staff member | Encrypted; every open recorded |
| Staff claims: amount, date, purpose, up to 5 receipt files | Reimbursement | The claimant (own); approvers Director and HR Manager see the purpose and receipts; payers Finance Officer and Director see the amount and record payment with a masked reference; the Monthly view for Finance Officer, Director, HR Manager | Purpose encrypted; receipts encrypted on the private disk. KPOne pays nothing; it records a payment made outside KPOne |
| Locums | Staff file without a KPOne sign-in | Director, HR Manager | Display name and an encrypted phone (`hr_workers.display_name`, `locum_phone_encrypted`) |
| Record corrections (legal name, IC, date of birth, nationality, bank details) | A staff member asks for a correction | Director, HR Manager; the staff member sees their own | New value encrypted (`hr_record_corrections.new_value_encrypted`); kept after the decision |
| Memos (HR-8, PR #433, not merged yet) | Staff announcements | Published to all staff or chosen branches and roles; Director and HR Manager see who has not read | Title in plain text (with a hint not to put a name, health or pay in it); body encrypted; optional PDF encrypted under a generated name. Each person's opens and "I have read this" are kept |
| Outside HR export (HR-12) | Data for the outside HR product | Downloaded by Director and HR Manager (`hr.records.manage.organisation`) | CSV of staff (name, position, employment type and dates, primary branch, status), approved leave (dates, days, paid or not; no reason) or approved overtime (date, day type, minutes). Each download audited (kind, month, row count); nothing stored. **The file leaves KPOne** |
| HR dashboard staff cost (HR-9b, PR #436, not merged yet) | Monthly cost overview | Director only | One clinic-wide figure; no person, branch or pay group |

## C. Job applicants (public form, off by default)

**What is held.** From the form: name, phone, email, an optional message and the form answers (preferred branch,
start date, education, years of experience, languages; for interns the institution, programme and dates; for doctors
the MMC number and APC year and expiry), plus a CV file. From HR's handling: phone-screening notes, interview time
choices and the confirmed interview time and branch, which clinic interview documents the candidate downloaded and
when, the optional talent-pool tick (with the version of its wording), and leaving the pool. All personal values, the
answers, the screening notes and the CV are encrypted. The candidate's private link is kept only as a keyed hash.

**Who sees it.** Director and HR Manager (`hr.applications.manage.organisation`).

**Staff-account requests.** When HR asks for a staff account for a passed candidate, the candidate's **name and
email** (copied, encrypted) are shown on the Staff page to everyone who may create staff accounts
(`staff.manage.organisation` plus `access.manage.organisation`), that is **Director and Technical Admin**.

**Leaves KPOne.** The candidate's fixed emails, including the private link, go through the mail provider when hiring
email is on. Reminders to the hiring mailbox carry only a reference. The message text is never stored, only which
template was sent and its status.

**When the form is off.** The public form refuses applications until `HR_APPLICATIONS_ENABLED` is true **and** HR has
saved a retention period (`config/hr-applications.php`; `JobApplicationIntake::state()`). Approving the privacy notice
is a process condition, not something the code checks.

## D. Other

| Data | Notes |
|---|---|
| Audit trail | Structural facts (who, what, when, ids and codes). **By rule it excludes credentials, tokens, cookies and secret fields, and sensitive text.** |
| Waiting-room TV | Shows queue numbers by default. Per branch it can show a short name or full name, which the whole waiting room can see. **A privacy decision is needed before go-live (see 08).** |
| WhatsApp click-to-chat | A staff member opens `wa.me` with the patient's saved number. KPOne sends nothing and stores no message, but WhatsApp/Meta then handles the conversation |
| Staff messages (email, SMS, WhatsApp, OTP) | Authorised for staff and HR only. **Built so far: email only**, to the staff member's work email through the mail provider when `HR_MESSAGES_EMAIL_ENABLED` is on (off by default). SMS and WhatsApp are not connected and OTP is not built; no provider is chosen for them. The delivery log (`hr_message_deliveries`) keeps the template, record ids, status and the provider's message id, **no text, names, dates of illness or amounts**. Each person's channel choices and their changes, including STOP replies, are kept |
| "Ask KPOne" | The owner can ask Claude about masked data (initials, last 4 ID characters). Results reach Anthropic through the owner's Claude use. HR tables are not in the masked views; the only staff data is the display name and roles (`ai_read.staff`). **Synthetic data only until reviewed [CONFIRM before any real data]** |
| Website drafts | Doctor profile content with consent flags. Nothing is public yet |
| Sign-in | Staff sign in with a password or Google. Unknown Google accounts never create a user |

Data Protection Officer: HR Manager (role; the owner keeps the name and contact outside the repository).

## F. Where HR data leaves KPOne

| Path | What leaves | Who | Records kept |
|---|---|---|---|
| Payroll bank CSV (locked run) | Name, account holder, bank, **account number**, net amount, reference | Director only | `hr_payroll_exports` log (kind and row count); the file is never stored; account numbers never in audit |
| Payroll accountant CSV | Name and each pay item amount | Director only | Same |
| Outside HR export | Staff list, approved leave, approved overtime (see B) | Director, HR Manager | Audit row (kind, month, row count) |
| Staff email | Work email address and the message | Sent by the system | Delivery log, no text |
| Candidate email | Candidate email, the message and the private link | Sent by the system | Message log, no text |

Where these files go after download (email, a USB drive, the bank portal) is outside KPOne. **[DECIDE a handling rule.]**

## E. Where data physically lives (to complete)

| Question | Answer today |
|---|---|
| Hosting provider and country | **Not chosen. [DECIDE]** |
| Database | PostgreSQL 18, one database |
| Files (photos, documents, statements) | Private disk. `FILESYSTEM_DISK=local` in the example settings; production storage not decided |
| Backups: where, how often, how long, encrypted | **Not defined. [DECIDE]** |
| Application logs | The example setting is `LOG_LEVEL=debug`; production must use a higher level and logs must never hold personal data **[CONFIRM in production]** |
| Who can reach the server and the database | **[DECIDE and list names]** |
