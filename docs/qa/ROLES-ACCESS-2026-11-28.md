# KPOne roles and access (main at d85c34129, 28 Nov 2026)

Source: PermissionCatalogue::roles() with every merged grant (Billing, Report, Triage, Treatment room, MC/Referral/Attendance, Website, Agents, HR, Compliance), WorkspaceLandingService (landing page) and ShellMenu (menus are filtered by permission). 17 roles.

## Branch scope
- All branches: Director, Panel Officer, Finance Officer, Administrator, Account Assistant, Store Admin, HR Manager, Technical Admin, Business Development, Marketing.
- Own assigned branches only: Resident Doctor, CA, Medical Assistant, CA Supervisor, Branch Manager, Locum, Queue display (TV).
- Patients are organisation-wide; visits, queue, billing and stock follow the active branch. Insights are own-branch for branch roles (INS-BM-1).

## Per role (landing page — main work — can — cannot)
- **Director** (My day): runs the clinic. All branches; full patient record; read-only consultation/dispensary view (audited); all billing approvals, prices, limits; Panel (except recording remittances); stock cost, valuation (only role), PO/supplier invoice/return approvals, holds, recalls; all reports; every HR permission incl. payroll approval; staff, branches, access, settings, compliance; website (only approver); AI. Cannot take payments, build bills, write clinical notes or dispense.
- **Branch Manager** (My day): one branch's day. Views visits, queue, bills, outstanding; approves daily cash close; views stock, reports a difference; Daily/Panel/Stock movement reports and own-branch Sales/Payments downloads; Insights. Cannot open patient records, clinical, dispensary, Panel, take payments, approve refunds/voids (not built yet: BIL-33/34), switch branches.
- **Resident Doctor** (Queue): own queue and consultation (notes, allergies, problems, diagnoses, treatment plan, send to dispensary, complete/hold), doctor's medicine check, MC/referral/attendance letters, read-only bills, own sales target. Cannot register, take payments, dispense, see stock, Panel, reports. Locum doctor = doctor + locum (no leave/claims).
- **CA** (Registration): front desk + dispensary. Patients, visits, queue, not-ready flag, QR review, triage; dispense, labels, OTC; build/finalise bills, discounts within limit, take payments, coverage, pay-later request; print issued MC/attendance; stock view, transfers, requests, usage/waste; fridge temps. Cannot call patients in for a doctor, clinical notes, approve pay-later/refunds/cash close, receive deliveries or count stock, download reports.
- **Medical Assistant** (Registration): everything a CA does plus treatment-room recording and treatment-room calls.
- **CA Supervisor** (Registration): all CA work plus calling in, patient ID/merges, QR/TV for branch; doctor-check override with reason; note retract, over-limit/reversed adjustments, approve pay-later and cash close, record refunds; full branch stock control (opening, receiving, stocktake, adjustments, PO create, supplier invoices, holds, controlled register, reviews); publish prices; compliance; AI summary. Cannot approve POs/supplier returns, release holds, approve refunds, see stock cost, download reports, approve own earlier step.
- **Store Admin** (My day): central store. Stock with cost; purchase requests and POs (create); receiving; stocktake; reviews; approve stock requests; transfers incl. cross-branch; warehouse. Cannot patients, clinical, billing, Panel, reports, approve POs.
- **Panel Officer** (My day): Panel claims end to end, patient phone/ID on claim page (audited), diagnosis/treatment filters (no clinical text), remittances, Panel invoice CSV, propose Panel prices, Panel list; billing lines/summary/Bill to; coverage; Panel summary report; audit log (read). Cannot patients directory, clinical, take payments, approve Panel prices.
- **Finance Officer** (My day): take/reverse payments, void invoices, record/approve refunds, adjustments, approve pay-later and cash close, payment methods, prices, targets; Panel incl. approving prices; stock cost, medicine sales, create+approve POs (never own), supplier invoices (never as recorder), returns, differences, holds, recalls, opening sign-off; all reports + Insights overview; pay staff claims, locum pay, labour cost. Cannot patients/clinical, build bills, stock valuation, HR approvals, staff accounts.
- **Account Assistant** (My day): records supplier invoices; views stock with cost; views (not downloads) Daily/Panel/Stock movement; Insights; audit log. Approves nothing.
- **HR Manager** (My day): KPOne HR (records, roster manage, approve leave/overtime/claims, attendance, credentials, SOPs/memos/letters, appraisals, discipline, incidents, locum pay, payroll prepare/export, job applications, messages); staff accounts; access view; audit log; licences; triage guideline; website doctor profiles/careers. Cannot approve payroll or rosters, pay claims, see patients/clinical/billing/stock.
- **Administrator** (My day): settings account. Catalogue, medicines, services, prices, Panel list, payment methods, business details, QR/TV for all branches; stock view, suppliers, reorder levels; compliance; Daily/Stock movement and Sales/Payments downloads; website drafts; AI view. Cannot patients, clinical, money approvals, Panel claims, staff/access.
- **Technical Admin** (My day): staff accounts, branches, roles and access, audit log, system events, AI view. No patient, clinical, billing, stock, report-download or HR work; cannot govern the Director.
- **Business Development** (My day): Insights and staff directory only (plus self-service).
- **Marketing** (My day): website content and images, prepare releases (Director approves); Insights.
- **Locum** (own roster): own roster, request open shifts, availability, own locum pay. No patients, Insights, leave, claims, clock-in or payslips.
- **Queue display / TV** (TV screen): own branch's called numbers and rooms only.

## Everyone (except TV and Locum)
My day, own profile and staff record, Insights (aggregate), clock in/out, leave, staff claims, own roster, payslips, HR record, credentials, appraisal, memos, SOP acknowledge, incident report, HR messages.

## Cross-role rules
Approvers never approve their own step (POs, supplier invoices, differences, hold release, recall lift, pack conversions). Staff changes refuse self-escalation; the Director's authority is protected.

## Gaps to check
Branch Manager can open visit boards without patient permission (what detail shows is unconfirmed). Own-leave/claim self-approval rule in HR not verified. Some menu links may need two permissions together.
