# Clean Look demo coverage — audit baseline

Status: **unverified until comparison evidence exists**. This is a census and mapping index, not a completion certificate. No application code, grants or assertions changed. No publication approval requested by this file.

## Authority and method

Source: `/workspace/attachments/e9bc43ff-8ddf-4760-a2e6-8ad93df3e127/KPOne-Clean-Look-Demo.html`. SHA256 `1633ed31900f6ce49097332ad668d77fe8a7601215695b3d01419e29305753f9`. All 178 extracted HTML asset hashes match the earlier ZIP; this does not make the old static-asset interpretation equivalent to the uploaded runtime.

Read the full wrapper runtime alongside embedded assets. Its ACCOUNTS, ACCESS mutations, TAB_OF, TAB_GUARD, NOTES, action guards, role-specific content patches and animation scheduler change what is actually shown. Demo permissions/comments/proposal flags describe this reference only: they do not authorize backend grants, clinical behavior or new features. Actual domain rules require existing owner decisions and application contracts.

Application route inventory captured from the approved filter candidate at main bd9a42331d72cf1d2b54ad72b5671e8913d0a483 plus class-only filter change. `application-routes.json` records exact actual GET routes; `application-route-bindings.json` records controller-level render candidates (not method-level proof). Integrated unpublished features require separate CA mapping. Route mappings below are discovery references, not parity verification. Unresolved means investigate, never assume missing/authorized.

## Runtime rules required in comparison

- 17 fictional accounts; Director opens Modern, TV opens WaitingRoomTv, other accounts open Me. Account controls cannot be switched by remembering a different role.
- Redirected assets select matching tab/view; HR aliases choose team/own tabs by role. Notes are hidden, not product pages.
- Runtime adds four own-HR tabs, invoice void description, Admin connector tools, role-specific overviews and the Ask KPOne drawer. Sidebar links are rewritten to the matching sections.
- Page entrance animations play once per arrival, survive rerender without restarting, and restart after leaving/returning. Later-opened dialogs have their own entrance. Reduced-motion handling must remain respected.
- Keep focus/selection/scroll through updates; exercise tab, row disclosure, dialogs, confirm/cancel, menu/branch changes and keyboard behavior. Demo unconnected-button messages are not product behavior to copy blindly.
- Demo persistence is shared per asset and inventory mutations are fictional runtime operations; these are not application architecture or transaction authorization.

## Comparison gate

For each actual role/route/tab record: content hierarchy and real data; layout at390/768/1440 light/dark; actions and disclosure/confirmation; loading/empty/error/partial/readonly states; keyboard/focus/touch targets; arrival/dialog/reduced-motion animation. Link matched demo/application screenshots and source SHA. CSS-only, build-passing, or prior screenshot evidence without demo comparison does not count as complete. Retain explicit approved accessibility minima (36px desktop, 44px below1024) even where the demo draws smaller controls; do not silently revert approved safety/privacy or data contracts to match fictional examples.

## Priority: Queue discrepancy

Canonical demo is **MyQueue runtime**. QueueBoard redirects to MyQueue; WfQueueBoard is also a hidden design note. Current integrated Queue has a flat tab/filter/table composition, while the reference uses views/groups, metrics, top grouped pills/tabs, central list and right supporting disclosure with contextual Call next. Exact role-specific screenshot comparison belongs in Queue team evidence; do not substitute the legacy QueueBoard static asset. Existing sizing/filter fixes are foundation work, not full parity. Independent screenshot findings are in `QUEUE-FIRST-COMPARISON.md` (1440 light doctor context, pre-makeover app versus canonical runtime).

## Team comparison evidence

Current implementation milestones: `IMPLEMENTATION-PROGRESS.md`. All whole-page parity remains unverified.

- Queue: actual runtime captures `../queue-demo-makeover/demo-{doctor,supervisor}-{1440,390}.png` and `canonical-runtime.json`; top grouped pills/tabs confirmed (earlier left-rail description corrected). `../queue-demo-makeover/GAP-MATRIX.md` and independent first-candidate review provide detailed current gaps.
- Registration/Patient/Emergency/Family: `REGISTRATION-PATIENT-GAP-MATRIX.md` (source audit, not browser parity).
- Dispensary/Pharmacy/OTC: `../dispensary-demo-audit/GAP-MATRIX.md` (27 scoped gaps, five decision conflicts;15 runtime captures, actual-app evidence limits explicit).
- Billing/Panel: `BILLING-PANEL-GAP-MATRIX.md` (team audit; read its own statuses).
- Inventory/Stock/reports: `inventory-audit/INVENTORY-GAP-MATRIX.md` (17 runtime navigations, three roles; read its own statuses).

## Complete embedded asset inventory

| Asset | Title | Runtime classification / target | Actual route discovery | Comparison |
|---|---|---|---|---|
| AccessControl | Access control | Dialog/sheet asset | `/access-control` | Unverified |
| Admin | Admin | Page / embedded states | `/approvals`, `/audit-logs`, `/branches`, `/staff` | Unverified |
| AgentDailySummary | Daily summary | Page / embedded states | `/agents/daily-summary` | Unverified |
| AgentOffice | Agent office | Page / embedded states | `/agents/office` | Unverified |
| AllAgents | AI agents | Alias → Admin {"tab": "agents"} | `/agents` | Unverified |
| Appraisals | Appraisals | Alias → HR "role-dependent function (see HR_TAB or Compliance)" | `/hr/appraisals` | Unverified |
| ApprovalLimits | Approval limits | Page / embedded states | `/billing-approval-limits` | Unverified |
| Approvals | Approvals | Alias → Admin {"tab": "inbox"} | `/approvals`, `/approvals/count` | Unverified |
| Attendance | Attendance | Alias → HR "role-dependent function (see HR_TAB or Compliance)" | `/hr/attendance` | Unverified |
| AttendanceLetter | Attendance confirmation | Dialog/sheet asset | Unresolved — team mapping required | Unverified |
| AuditLog | Audit log | Alias → Admin {"tab": "audit"} | `/audit-logs` | Unverified |
| BatchHolds | Batch safety holds | Alias → Stock {"tab": "holds", "view": "branch"} | `/inventory/batch-holds` | Unverified |
| Billing | Billing | Page / embedded states | `/cash-closings`, `/cash-closings/month`, `/pay-later-collections`, `/sales-targets`, `/visits/{visit}/billing`, `/visits/{visit}/billing/issued/prints/{print}`, `/visits/{visit}/billing/outstanding/{group}`, `/visits/{visit}/billing/payments`, `/visits/{visit}/billing/{invoice}/letterhead-logo/{asset}`, `/visits/{visit}/billing/{invoice}/print`, `/visits/{visit}/billing/{invoice}/receipts/{payment}/print`, `/visits/{visit}/history`, `/visits/{visit}/invoice` | Unverified |
| BillingDetail | Billing detail | Alias → Reports {"view": "branch", "tab": "bd"} | `/reports/billing-detail` | Unverified |
| BillingMap | Billing function map | Design note — NOT a page; hidden | Not applicable — design/support asset | Not a page |
| BillsToCollect | Bills to collect | Alias → Billing {"tab": "collect"} | `/pay-later-collections` | Unverified |
| Branches | Branches | Alias → Admin {"tab": "branches"} | `/branches`, `/branches/{branch}` | Unverified |
| CareersPublic | Careers | Page / embedded states | `/careers/apply` | Unverified |
| CashClosing | Cash closing | Alias → Billing {"tab": "close"} | `/cash-closings` | Unverified |
| CheckoutSettings | Checkout settings | Page / embedded states | `/checkout-settings` | Unverified |
| ClaimMessageTemplate | Claim message template | Dialog/sheet asset | Unresolved — team mapping required | Unverified |
| ClaimPack | Claim pack | Dialog/sheet asset | `/panel-claims/pack` | Unverified |
| ClaimsFollowUp | Claims follow-up | Alias → Panel {"tab": "follow"} | `/panel-claims/follow-up` | Unverified |
| ClinicianCredentials | Clinician credentials | Page / embedded states | `/clinician-credentials`, `/clinician-credentials/me`, `/medical-certificate-templates` | Unverified |
| Compliance | Clinic compliance | Alias → Admin "role-dependent function (see HR_TAB or Compliance)" | `/clinic-compliance` | Unverified |
| Consultation | Consultation | Page / embedded states | `/visits/{visit}/encounter` | Unverified |
| ConsultationSettings | Consultation settings | Page / embedded states | `/consultation-settings` | Unverified |
| ControlledRegister | Controlled register | Alias → Stock {"tab": "cdreg", "view": "branch"} | `/inventory/controlled-register`, `/inventory/controlled-register/{sku}`, `/inventory/controlled-register/{sku}/print` | Unverified |
| CorrectPanelPayment | Correct a Panel payment | Dialog/sheet asset | `/panel-payment-corrections` | Unverified |
| CreateClaim | Create panel claim | Dialog/sheet asset | `/panel-claims/allocations/{allocation}/create` | Unverified |
| Credentials | Credentials | Alias → HR "role-dependent function (see HR_TAB or Compliance)" | `/hr/credentials` | Unverified |
| DailySummaryOld | Reports | Design note — NOT a page; hidden | Not applicable — design/support asset | Not a page |
| DirectorHome | Director home | Alias → HOME | `/dashboard`, `/my-day` | Unverified |
| DirectorOverview | Director overview | Alias → HOME | `/dashboard`, `/my-day` | Unverified |
| Discipline | Discipline | Alias → HR "role-dependent function (see HR_TAB or Compliance)" | `/hr/discipline` | Unverified |
| DispensarySettings | Dispensary settings | Page / embedded states | `/dispensary-settings` | Unverified |
| DoctorChecks | Waiting for doctor's check | Alias → Pharmacy {"tab": "docchk"} | `/dispensary/doctor-checks` | Unverified |
| DrugLabel | Drug labels | Dialog/sheet asset | `/dispensary/{dispensaryCase}/labels` | Unverified |
| EmergencyAlert | Emergency lane | Dialog/sheet asset | `/queue` | Unverified |
| FamilyBill | Family bill | Dialog/sheet asset | Unresolved — team mapping required | Unverified |
| FinanceHome | Finance home | Alias → HOME | `/dashboard`, `/my-day` | Unverified |
| FollowUp | Follow-up tickets | Alias → Admin {"tab": "inbox"} | `/panel-claims`, `/panel-claims/allocations/{allocation}/create`, `/panel-claims/follow-up`, `/panel-claims/pack`, `/panel-claims/pack/logo/{asset}`, `/panel-claims/{claim}`, `/panel-claims/{claim}/documents/{document}` | Unverified |
| HR | HR | Page / embedded states | `/hr/attendance`, `/hr/dashboard`, `/hr/leave` | Unverified |
| HomeAA | Account assistant home | Alias → HOME | `/dashboard`, `/my-day` | Unverified |
| HomeAdm | Administrator home | Alias → HOME | `/dashboard`, `/my-day` | Unverified |
| HomeBM | Branch manager home | Alias → HOME | `/dashboard`, `/my-day` | Unverified |
| HomeCA | Clinic assistant home | Alias → HOME | `/dashboard`, `/my-day` | Unverified |
| HomeDoctor | Resident doctor home | Alias → HOME | `/dashboard`, `/my-day` | Unverified |
| HomeFin | Finance officer home | Alias → HOME | `/dashboard`, `/my-day` | Unverified |
| HomeHR | HR manager home | Alias → HOME | `/dashboard`, `/my-day` | Unverified |
| HomeMA | Medical assistant home | Alias → HOME | `/dashboard`, `/my-day` | Unverified |
| HomeOffice | Office staff home | Alias → HOME | `/dashboard`, `/my-day` | Unverified |
| HomePanel | Panel officer home | Alias → HOME | `/dashboard`, `/my-day` | Unverified |
| HomeStore | Store admin home | Alias → HOME | `/dashboard`, `/my-day` | Unverified |
| HomeSup | CA supervisor home | Alias → HOME | `/dashboard`, `/my-day` | Unverified |
| HqView | All branches | Alias → Reports {"view": "all", "tab": "alive"} | `/reports/billing-detail`, `/reports/daily-summary`, `/reports/transactions` | Unverified |
| HrDashboard | HR dashboard | Alias → HR "role-dependent function (see HR_TAB or Compliance)" | `/hr/dashboard` | Unverified |
| HrMessages | HR messages | Alias → HR "role-dependent function (see HR_TAB or Compliance)" | `/hr/messages` | Unverified |
| Incidents | Incidents | Alias → HR "role-dependent function (see HR_TAB or Compliance)" | `/hr/incidents` | Unverified |
| Insights | Insights | Alias → HOME | `/insights/daily-receipts/download`, `/insights/daily-sales/download`, `/insights/overview`, `/insights/sales-summary/download`, `/insights/today`, `/insights/{section}` | Unverified |
| InvBranchRequest | Request stock from the central store | Dialog/sheet asset | Unresolved — team mapping required | Unverified |
| InvCreatePO | Create purchase order | Dialog/sheet asset | Unresolved — team mapping required | Unverified |
| InvDispatch | Dispatch transfer | Dialog/sheet asset | Unresolved — team mapping required | Unverified |
| InvLanes | Inventory: who does what | Design note — NOT a page; hidden | Not applicable — design/support asset | Not a page |
| InvMyWork | My inventory work | Design note — NOT a page; hidden | Not applicable — design/support asset | Not a page |
| InvPoApprove | Purchase order PO/CHR/2026/0118 | Dialog/sheet asset | Unresolved — team mapping required | Unverified |
| InvPurchaseRequest | New purchase request | Dialog/sheet asset | `/inventory/purchase-requests` | Unverified |
| InvReceive | Receive delivery | Dialog/sheet asset | Unresolved — team mapping required | Unverified |
| InvReceiveTransfer | Receive transfer TR-0087 | Dialog/sheet asset | Unresolved — team mapping required | Unverified |
| InvReportDiff | Report a difference | Dialog/sheet asset | Unresolved — team mapping required | Unverified |
| InvRfq | Ask suppliers for quotes (RFQ) | Dialog/sheet asset | Unresolved — team mapping required | Unverified |
| InvSupplierBill | Supplier bill SF-INV-55087 | Dialog/sheet asset | Unresolved — team mapping required | Unverified |
| InvTimeline | Track an inventory document | Design note — NOT a page; hidden | Not applicable — design/support asset | Not a page |
| InventoryFlow | Inventory flow | Design note — NOT a page; hidden | Not applicable — design/support asset | Not a page |
| InvoiceView | Invoice | Dialog/sheet asset | `/visits/{visit}/billing/{invoice}/print` | Unverified |
| JobApplications | Job applications | Alias → HR "role-dependent function (see HR_TAB or Compliance)" | `/hr/applications` | Unverified |
| JourneyMap | Patient journey | Design note — NOT a page; hidden | Not applicable — design/support asset | Not a page |
| LabourCost | Labour cost | Page / embedded states | `/hr/labour-cost` | Unverified |
| Leave | Leave | Alias → HR "role-dependent function (see HR_TAB or Compliance)" | `/hr/leave` | Unverified |
| Letters | Letters | Alias → HR "role-dependent function (see HR_TAB or Compliance)" | `/hr/letters` | Unverified |
| LocumPay | Locum pay | Alias → HR "role-dependent function (see HR_TAB or Compliance)" | `/hr/locum-pay` | Unverified |
| MC | Medical certificate | Dialog/sheet asset | `/visits/{visit}/medical-certificates` | Unverified |
| ManagePayments | Manage payments | Alias → Billing {"tab": "receipts"} | `/cash-closings`, `/cash-closings/month`, `/pay-later-collections`, `/sales-targets`, `/visits/{visit}/billing`, `/visits/{visit}/billing/issued/prints/{print}`, `/visits/{visit}/billing/outstanding/{group}`, `/visits/{visit}/billing/payments`, `/visits/{visit}/billing/{invoice}/letterhead-logo/{asset}`, `/visits/{visit}/billing/{invoice}/print`, `/visits/{visit}/billing/{invoice}/receipts/{payment}/print`, `/visits/{visit}/history`, `/visits/{visit}/invoice` | Unverified |
| McTemplates | MC templates | Dialog/sheet asset | `/medical-certificate-templates` | Unverified |
| Me | Me | Page / embedded states | `/my-day` | Unverified |
| MedicineCatalogue | Medicine catalogue | Page / embedded states | `/medicines` | Unverified |
| MedicineDetails | Medicine details | Page / embedded states | `/inventory-services-settings/medicine-details` | Unverified |
| Memos | Memos | Alias → HR "role-dependent function (see HR_TAB or Compliance)" | `/hr/memos` | Unverified |
| Modern | Today: modern | Page / embedded states | `/dashboard`, `/insights/today` | Unverified |
| MyCredentials | My clinician credentials | Dialog/sheet asset | `/clinician-credentials/me` | Unverified |
| MyQueue | My patients | Page / embedded states | `/queue` | Unverified |
| OpenShifts | Open shifts | Alias → HR "role-dependent function (see HR_TAB or Compliance)" | `/hr/open-shifts` | Unverified |
| OpeningImport | Import opening stock | Page / embedded states | `/inventory/opening-import` | Unverified |
| Panel | Panel | Page / embedded states | `/panel-claims` | Unverified |
| PanelClaims | Panel claims | Alias → Panel {"tab": "sub"} | `/panel-claims` | Unverified |
| PanelInvoices | Panel invoices | Alias → Reports {"view": "branch", "tab": "pi"} | `/panel-invoices` | Unverified |
| PanelPayments | Panel payments | Alias → Panel {"tab": "pay"} | `/panel-remittances` | Unverified |
| PanelPriceChanges | Panel price changes | Dialog/sheet asset | `/panel-price-changes` | Unverified |
| PanelResponsibility | Panel responsibility | Dialog/sheet asset | `/panel-responsibility` | Unverified |
| PanelSummary | Panel summary | Alias → Reports {"view": "branch", "tab": "ps"} | `/reports/panel-summary` | Unverified |
| PasswordPages | Password pages | Page / embedded states | `/forgot-password`, `/reset-password/{token}` | Unverified |
| Patient | Patient | Page / embedded states | `/patients/{patient}` | Unverified |
| PatientBilling | Patient billing | Page / embedded states | `/visits/{visit}/billing` | Unverified |
| PatientCreate | Create patient | Dialog/sheet asset | `/patients/create` | Unverified |
| PatientDirectory | Patient directory | Alias → Registration {"tab": "patients"} | `/patients` | Unverified |
| PatientEdit | Edit patient | Dialog/sheet asset | `/patients/{patient}/edit` | Unverified |
| PatientMerge | Patient merge | Alias → Admin {"tab": "merge"} | `/patient-merges` | Unverified |
| PaymentMethods | Payment methods | Page / embedded states | `/payment-methods` | Unverified |
| Payroll | Payroll | Alias → HR "role-dependent function (see HR_TAB or Compliance)" | `/hr/payroll` | Unverified |
| Payslip | Payslip | Dialog/sheet asset | `/hr/payslips/{payslip}` | Unverified |
| Pharmacy | Dispensary | Page / embedded states | `/dispensary` | Unverified |
| PharmacyCase | Dispensary case | Page / embedded states | `/dispensary/{dispensaryCase}` | Unverified |
| PharmacyCompleted | Dispensary completed | Dialog/sheet asset | `/dispensary` | Unverified |
| PilotReadiness | Pilot readiness | Page / embedded states | `/inventory/readiness` | Unverified |
| PreviousConsultation | Previous consultation | Dialog/sheet asset | `/visits/{historicalVisit}/encounter/history` | Unverified |
| PriceCheck | Check price list | Page / embedded states | `/pricing`, `/pricing/charges/{charge}/history`, `/pricing/price-check` | Unverified |
| Pricing | Pricing | Page / embedded states | `/pricing`, `/pricing/charges/{charge}/history`, `/pricing/price-check` | Unverified |
| ProfileSettings | Your settings | Page / embedded states | `/settings/profile` | Unverified |
| PublicQrForm | Public QR check-in form | Page / embedded states | `/check-in` | Unverified |
| PublicQrStatus | Public QR status | Page / embedded states | `/check-in/status` | Unverified |
| PurchaseOrders | Purchase orders | Alias → Stock {"tab": "po", "view": "branch"} | `/inventory` | Unverified |
| QrCheckins | QR check-ins | Alias → Registration {"tab": "qr"} | `/registration-review` | Unverified |
| QrLinks | QR check-in links | Page / embedded states | `/public-checkin-links` | Unverified |
| QueueBoard | Consultation queue | Alias → MyQueue | `/queue` | Unverified |
| QueueDisplaySettings | Queue display settings | Page / embedded states | `/queue-display-settings` | Unverified |
| QuickAnswers | Quick answers | Dialog/sheet asset | Unresolved — team mapping required | Unverified |
| Recalls | Recalls | Alias → Stock {"tab": "recalls", "view": "branch"} | `/inventory/recalls`, `/inventory/recalls/batches` | Unverified |
| Receiving | Receiving | Alias → Stock {"tab": "recv", "view": "branch"} | `/inventory` | Unverified |
| ReferralLetter | Referral letter | Dialog/sheet asset | `/visits/{visit}/referrals` | Unverified |
| RegisterVisit | Register visit | Dialog/sheet asset | `/registration/create` | Unverified |
| Registration | Registration | Page / embedded states | `/registration` | Unverified |
| RegistrationSettings | Registration settings | Page / embedded states | `/registration-settings` | Unverified |
| ReorderSuggestions | Reorder suggestions | Alias → Stock {"tab": "low", "view": "branch"} | `/inventory/reorder-suggestions` | Unverified |
| Reports | Reports | Page / embedded states | `/reports/billing-detail`, `/reports/daily-summary`, `/reports/transactions` | Unverified |
| ReturnsToCheck | Returns to check | Alias → Pharmacy {"tab": "returns"} | `/dispensary/returns-to-check` | Unverified |
| ServiceCatalogue | Service catalogue | Page / embedded states | `/clinical-services` | Unverified |
| Settings | Settings | Page / embedded states | `/dispensary-settings`, `/queue-display-settings`, `/settings/profile`, `/settings/security` | Unverified |
| Sidebar | KPOne sidebar | Shared shell component, not standalone page | `/dashboard`, `/my-day` | Not a page |
| SignIn | Staff sign in | Page / embedded states | `/login` | Unverified |
| SkuEdit | SKUs | Dialog/sheet asset | `/inventory/skus` | Unverified |
| Sops | SOPs | Alias → HR "role-dependent function (see HR_TAB or Compliance)" | `/hr/sops` | Unverified |
| Staff | Staff and access | Alias → Admin {"tab": "staff"} | `/staff`, `/staff/create`, `/staff/{staff}`, `/staff/{staff}/edit` | Unverified |
| StaffClaims | Staff claims | Alias → HR "role-dependent function (see HR_TAB or Compliance)" | `/hr/claims` | Unverified |
| StaffFiles | Staff files | Alias → HR "role-dependent function (see HR_TAB or Compliance)" | `/hr/records` | Unverified |
| StatusGuide | Status guide | Dialog/sheet asset | Unresolved — team mapping required | Unverified |
| Stock | Inventory | Page / embedded states | `/inventory` | Unverified |
| StockCount | Stock count | Alias → Stock {"tab": "counts", "view": "branch"} | `/inventory/counts` | Unverified |
| StockHealth | Stock health | Alias → Stock {"tab": "health", "view": "branch"} | `/inventory/stock-health` | Unverified |
| StockMovement | Stock movement | Alias → Reports {"view": "branch", "tab": "sm"} | `/reports/stock-movement` | Unverified |
| StockOtherBranches | Stock other branches need | Alias → Stock {"tab": "spare", "view": "branch"} | `/inventory` | Unverified |
| StockValue | Stock value and cost | Alias → Reports {"view": "branch", "tab": "sv"} | `/inventory/stock-value`, `/inventory/stock-value/journal` | Unverified |
| SupplierInvoices | Supplier invoices | Alias → Stock {"tab": "sinv", "view": "branch"} | `/inventory/supplier-invoices` | Unverified |
| SupplierPriceList | Supplier price list | Alias → Stock {"tab": "sprice", "view": "branch"} | `/inventory/supplier-terms` | Unverified |
| SupplierReturns | Supplier returns | Alias → Stock {"tab": "sret", "view": "branch"} | `/inventory/supplier-returns` | Unverified |
| Suppliers | Suppliers | Page / embedded states | `/inventory-services-settings/suppliers` | Unverified |
| TerminalCheck | Terminal check | Alias → Billing {"tab": "terminal"} | `/payment-reconciliations` | Unverified |
| TransactionDetail | Transaction detail | Alias → Reports {"view": "branch", "tab": "tx"} | `/reports/transactions` | Unverified |
| Transfers | Transfers | Alias → Stock {"tab": "transfer", "view": "branch"} | `/inventory` | Unverified |
| TreatmentRoom | Treatment room | Alias → Triage | `/visits/{visit}/triage` | Unverified |
| TreatmentUsage | Treatment room usage | Alias → Stock {"tab": "tusage", "view": "branch"} | `/inventory/treatment-usage` | Unverified |
| Triage | Triage and treatment room | Page / embedded states | `/visits/{visit}/triage` | Unverified |
| TriageGuideline | Triage guideline | Page / embedded states | `/triage-guideline` | Unverified |
| VisitDetails | Visit details | Page / embedded states | `/visits/{visit}` | Unverified |
| VisitEdit | Edit visit | Dialog/sheet asset | `/visits/{visit}/edit` | Unverified |
| VisitHistory | Invoices history | Alias → Billing {"tab": "history"} | `/visits/{visit}/history` | Unverified |
| WaitingRoomTv | Waiting-room TV | Page / embedded states | `/queue-display` | Unverified |
| WebsiteBranches | Website branch profiles | Page / embedded states | `/website/branch-profiles` | Unverified |
| WebsiteCareers | Website careers | Page / embedded states | `/website/careers` | Unverified |
| WebsiteDoctors | Website doctor profiles | Page / embedded states | `/website/doctor-profiles` | Unverified |
| WebsiteFaqs | Website FAQs | Page / embedded states | `/website/faqs` | Unverified |
| WebsiteLittleShield | Little Shield | Page / embedded states | `/website/little-shield` | Unverified |
| WebsiteMedia | Website media library | Page / embedded states | `/website/media` | Unverified |
| WebsitePromotions | Website promotions | Page / embedded states | `/website/promotions` | Unverified |
| WebsiteReleases | Website releases | Page / embedded states | `/website/releases` | Unverified |
| WebsiteServices | Website services | Page / embedded states | `/website/services` | Unverified |
| WfQueueBoard | Queue board, new workflow | Design note — NOT a page; hidden | Not applicable — design/support asset | Not a page |
| WfRegisterVisit | Register visit, new workflow | Design note — NOT a page; hidden | Not applicable — design/support asset | Not a page |
| WriteOff | Write-off | Alias → Stock {"tab": "wo", "view": "branch"} | `/inventory/writeoffs` | Unverified |

## Tabs, views, groups and modal state census

Every asset is indexed below when it contains keyed labels, tab/view/group declarations or dialog markup. `surface-census.json` retains declaration source, state transitions, headings, imports and motion names; dynamic expressions are explicitly retained for runtime follow-up. A detected label is not proof of access or an exhaustive list of dynamically generated state combinations. Wrapper additions/aliases below are part of this census even if absent from assets.

### AccessControl

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: `groups`
- Dialog markup occurrences: 3; headings: Access control; Configured roles; {{xp.title}}; Role permission matrix; {{kit.cfTitle}}; {{kit.shTitle}}.
- Comparison: **unverified**.

### Admin

- Keyed labels: `inbox` Approvals & tasks; `approve` Waiting for me; `decided` Decided this week; `staff` Staff; `assign` Branch assignments; `branches` Branches; `rooms` Rooms; `audit` Audit log; `merge` Merge requests; `mergedone` Recent merge decisions; `licences` Licences; `fridge` Fridge log; `compother` Overview and checklist; `agents` AI agents; `runs` Agent runs; `runstoday` Runs today; `apps` Connected apps
- Tab/view/group declarations: `visTabs`
- Dialog markup occurrences: 0; headings: {{title}}.
- Comparison: **unverified**.

### AgentDailySummary

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 2; headings: Daily summary; {{s.title}}; {{xp.title}}; Today's runs; Waiting in your inbox; {{kit.cfTitle}}; {{kit.shTitle}}.
- Comparison: **unverified**.

### AgentOffice

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 2; headings: {{xp.title}}; {{cur.name}}; Last steps; Suggestions waiting · Cheras; {{kit.cfTitle}}; {{kit.shTitle}}.
- Comparison: **unverified**.

### AllAgents

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 2; headings: Registered agents; {{xp.title}}; Recent activity · {{cur.name}}; {{cur.title}}; What it may do; {{kit.cfTitle}}; {{kit.shTitle}}.
- Comparison: **unverified**.

### Appraisals

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 2; headings: Cycles; {{xTitle}}; People; {{ap.name}}; Period summary; Staff member's comment; {{kit.cfTitle}}; {{kit.shTitle}}.
- Comparison: **unverified**.

### ApprovalLimits

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 2; headings: Invoice adjustment threshold; Approver limits · {{branchName}}; {{cur.name}}; {{kit.cfTitle}}; {{kit.shTitle}}.
- Comparison: **unverified**.

### Approvals

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 2; headings: {{xp.title}}; {{listTitle}}; {{det.title}}; {{kit.cfTitle}}; {{kit.shTitle}}.
- Comparison: **unverified**.

### Attendance

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: `TABS`, `tabs`
- Dialog markup occurrences: 2; headings: Week of 16 Nov; {{xTitle}}; {{listTitle}}; {{dTitle}}; Rules on this page; {{kit.cfTitle}}; {{kit.shTitle}}.
- Comparison: **unverified**.

### AttendanceLetter

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 3; headings: Attendance confirmation; Issued for this visit; New attendance confirmation; Preview; {{kit.cfTitle}}; {{kit.shTitle}}.
- Comparison: **unverified**.

### AuditLog

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 2; headings: {{cur.ev}}; How the log works; {{kit.cfTitle}}; {{kit.shTitle}}.
- Comparison: **unverified**.

### BatchHolds

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: Batch safety holds; Selected batch; Who can do what; {{cfTitle}}.
- Comparison: **unverified**.

### Billing

- Keyed labels: `pay` To pay; `panel` Panel and pay later; `collect` Bills to collect; `paid` Paid today; `close` Cash closing; `closemonth` Cash closing month; `terminal` Terminal check; `receipts` Receipts and refunds; `history` Invoices history; `branch` My branch; `all` All branches; `atoday` Takings today; `aclose` Cash closing; `acollect` Bills to collect; `apanel` Panel and pay later waiting
- Tab/view/group declarations: `visTabs`
- Dialog markup occurrences: 0; headings: {{title}}.
- Comparison: **unverified**.

### BillingDetail

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: `groups`
- Dialog markup occurrences: 2; headings: {{xpTitle}}; Billing detail; How this report counts; Privacy and limits; Your recent runs; {{kit.cfTitle}}; {{kit.shTitle}}.
- Comparison: **unverified**.

### BillsToCollect

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 2; headings: About this list; {{xpTitle}}; {{listTitle}}; {{sel.name}}; {{kit.cfTitle}}; {{kit.shTitle}}.
- Comparison: **unverified**.

### Branches

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 2; headings: Branches; Klinik Putrijaya {{cur.name}}; Rooms; Staff assigned today; Where each thing is changed; Document readiness; {{kit.cfTitle}}; {{kit.shTitle}}.
- Comparison: **unverified**.

### CareersPublic

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: {{h1}}; You and the position; About you; Your availability; Privacy notice; Your private link; Choose your interview times; Your choices; Messages from the clinic; Talent pool.
- Comparison: **unverified**.

### CashClosing

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 2; headings: November; {{xpTitle}}; Count for Fri 20 Nov · Cheras; Who approves; Waiting for approval; {{kit.cfTitle}}; {{kit.shTitle}}.
- Comparison: **unverified**.

### CheckoutSettings

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: `tabStyle`, `tabs`
- Dialog markup occurrences: 2; headings: Panels; Self-pay; {{xpTitle}}; {{f.title}}; {{kit.cfTitle}}; {{kit.shTitle}}.
- Comparison: **unverified**.

### ClaimMessageTemplate

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 3; headings: Claim message template; Claim message; Live preview; Fields in use; {{kit.cfTitle}}; {{kit.shTitle}}.
- Comparison: **unverified**.

### ClaimPack

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 3; headings: Claim pack; What prints; {{kit.cfTitle}}; {{kit.shTitle}}.
- Comparison: **unverified**.

### ClaimsFollowUp

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 2; headings: {{xpTitle}}; {{listTitle}}; {{d.name}}; Logs; {{kit.cfTitle}}; {{kit.shTitle}}.
- Comparison: **unverified**.

### ClinicianCredentials

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 2; headings: Doctors; {{xpTitle}}; Review credentials · {{cur.name}}; Change history; {{kit.cfTitle}}; {{kit.shTitle}}.
- Comparison: **unverified**.

### Compliance

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: `TABS`, `tabs`
- Dialog markup occurrences: 1; headings: Compliance; {{xp.title}}; Licences; Fridge log · Vaccine fridge 1; {{otherTitle}}; Add a licence; Add a reading; Needs attention; Are you sure?.
- Comparison: **unverified**.

### Consultation

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: Ahmad Ismail; Clinical note; Medicines; {{cfTitle}}.
- Comparison: **unverified**.

### ConsultationSettings

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: Diagnosis list; Add diagnosis; Selected: {{selName}}; Are you sure?.
- Comparison: **unverified**.

### ControlledRegister

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: `views`
- Dialog markup occurrences: 1; headings: Controlled register; How a check works; Selected; Controlled medicines; Controlled register: {{selMed}}; Check this register; Earlier checks; CONTROLLED MEDICINE REGISTER; {{cfTitle}}.
- Comparison: **unverified**.

### CorrectPanelPayment

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 2; headings: Correct a Panel payment; Recorded Panel payments · Cheras; {{d.title}}; Reverse this payment; Are you sure?.
- Comparison: **unverified**.

### CreateClaim

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 2; headings: Create panel claim; INV-C-02418; Patient details; Billing details; Treatment items; Claim; Before you create; AIA due rule; Are you sure?.
- Comparison: **unverified**.

### Credentials

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: {{xTitle}}; {{listTitle}}; Credential types · entered by the clinic; Add a record; {{dTitle}}; Reminders; Are you sure?.
- Comparison: **unverified**.

### DailySummaryOld

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: {{xpTitle}}; {{title}}; How this report counts; Your downloads.
- Comparison: **unverified**.

### DirectorHome

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: Needs your approval; {{xp.title}}; Clinic right now; {{xp.title}}; On duty; Staff on duty today; To review; {{xp.title}}; {{kDlgTitle}}.
- Comparison: **unverified**.

### DirectorOverview

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: `tabsI`
- Dialog markup occurrences: 0; headings: {{greetLine}}; Clinic overview; {{mTitle}}; {{pTitle}}; {{tTitle}}; {{rTitle}}; Still to come from Panels; By branch; Decisions and stock; More detail (Insights).
- Comparison: **unverified**.

### Discipline

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: Cases; {{cur.person}}; Open a case; Outcome; What KPOne will not do; Are you sure?.
- Comparison: **unverified**.

### DispensarySettings

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: Dispensary settings; {{cfTitle}}.
- Comparison: **unverified**.

### DoctorChecks

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: Waiting for doctor's check; How it works; {{cfTitle}}.
- Comparison: **unverified**.

### DrugLabel

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 2; headings: Drug labels; Medicine lines; {{xpTitle}}; Print preview; Label limits; Print log; Are you sure?.
- Comparison: **unverified**.

### EmergencyAlert

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 2; headings: Emergency lane; Red zone · A-017 Lim Wei Jie · 63 M · chest pain; Emergency lane · no queue number order; First response by the CA (from the clinic's SOP); {{cfTitle}}.
- Comparison: **unverified**.

### FamilyBill

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 2; headings: Family bill; {{i.no}} · {{i.name}}; {{cfTitle}}.
- Comparison: **unverified**.

### FinanceHome

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: {{xpTitle}}; Today's money by branch; {{listTitle}}; Panel outstanding; Pay later still owed; Are you sure?.
- Comparison: **unverified**.

### FollowUp

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: {{tk.id}} · {{tk.name}}; {{cfTitle}}.
- Comparison: **unverified**.

### HR

- Keyed labels: `decide` To decide; `decided` Decided today; `roster` Roster this week; `open` Open shifts; `att` Attendance today; `staff` Staff files; `today` Today per branch; `month` This month by branch; `claimpay` Claim pay; `claimmonth` Claims monthly; `ot` Overtime; `corr` Corrections; `leaveset` Leave settings; `attset` Attendance settings; `minstaff` Minimum staffing; `claimset` Claim settings; `cred` Credentials register; `credset` Credential types; `payroll` Payroll · November run; `locum` Locum statements; `locumrates` Locum rates; `sops` SOPs; `memos` Memos; `letters` Letters; `lettertpl` Letter templates; `hrmsg` HR messages; `msgset` Message channels; `appraisals` Appraisals; `apprcrit` Appraisal criteria; `incidents` Incidents; `discipline` Discipline; `jobs` Job applications; `jobset` Job positions and settings; `team` HR team; `me` My HR; `myleave` My leave; `myatt` My attendance; `myclaims` My claims; `mysops` SOPs to read; `mymemos` My memos; `myletters` My letters; `mylocum` My locum pay; `mydir` Staff directory
- Tab/view/group declarations: `visTabs`
- Dialog markup occurrences: 0; headings: {{title}}.
- Comparison: **unverified**.

### HomeAA

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: {{greet}}; {{rk.title}}; {{tilesTitle}}; {{ovTitle}}; {{ro.title}}; My week.
- Comparison: **unverified**.

### HomeAdm

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: {{greet}}; {{ringTitle}}; {{sideTitle}}; {{tilesTitle}}; {{nxTitle}}; My week; {{ovTitle}}; Sales this month by branch.
- Comparison: **unverified**.

### HomeBM

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: {{greet}}; {{rtTitle}}; {{liveTitle}}; {{l.title}}; {{ovTitle}}; My week.
- Comparison: **unverified**.

### HomeCA

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: {{greet}}; My routine today; Right now at Cheras; Waiting, longest first; Your next things; My week; For you.
- Comparison: **unverified**.

### HomeDoctor

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: {{greet}}; {{tTitle}}; My patients right now; Waiting for me, longest first; Needs me now; This week; My week; For you.
- Comparison: **unverified**.

### HomeFin

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: `groups`, `wgroups`
- Dialog markup occurrences: 1; headings: {{greet}}; Waiting for you; {{tilesTitle}}; {{wl.title}}; {{ovTitle}}; {{s.title}}; {{watch.title}}; {{goto.title}}; {{s.title}}; My week.
- Comparison: **unverified**.

### HomeHR

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: {{greet}}; {{ringTitle}}; {{sideTitle}}; {{tilesTitle}}; {{nxTitle}}; My week; {{ovTitle}}; Sales this month by branch.
- Comparison: **unverified**.

### HomeMA

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: {{greet}}; My routine; Right now in triage and the treatment room; In the treatment room; Now; My week; For you.
- Comparison: **unverified**.

### HomeOffice

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: {{greet}}; {{ringTitle}}; {{sideTitle}}; {{tilesTitle}}; {{nxTitle}}; My week; {{ovTitle}}; Sales this month by branch.
- Comparison: **unverified**.

### HomePanel

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: {{greet}}; {{rk.title}}; {{tilesTitle}}; {{nxTitle}}; {{ovTitle}}; My week.
- Comparison: **unverified**.

### HomeStore

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: {{greet}}; {{rtTitle}}; {{liveTitle}}; {{l.title}}; {{ovTitle}}; My week.
- Comparison: **unverified**.

### HomeSup

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: {{greet}}; {{rtTitle}}; {{liveTitle}}; {{l.title}}; {{ovTitle}}; My week.
- Comparison: **unverified**.

### HqView

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: Activity across branches; {{sel.name}} now; {{cfTitle}}.
- Comparison: **unverified**.

### HrDashboard

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: Today per branch; {{xTitle}}; {{monthHead}}; Rostered now; Staff cost · Nov; Staff cost; Needs attention; Are you sure?.
- Comparison: **unverified**.

### HrMessages

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: {{xTitle}}; Sent messages; Channel settings; What staff can be told; Are you sure?.
- Comparison: **unverified**.

### Incidents

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: `tabs`
- Dialog markup occurrences: 1; headings: Reports; {{xTitle}}; {{cur.kind}} report; Report an incident; Status; Add an action; Discipline; Are you sure?.
- Comparison: **unverified**.

### Insights

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: `tabs`
- Dialog markup occurrences: 1; headings: {{xpTitle}}; {{trendTitle}}; {{splitTitle}}; {{rankTitle}}; {{sideTitle}}; Downloads; How this tab counts; Are you sure?.
- Comparison: **unverified**.

### InvBranchRequest

- Keyed labels: `urg` Urgent: needed before Thursday
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: {{title}}; Done; {{sc.title}}.
- Comparison: **unverified**.

### InvCreatePO

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: {{title}}; Done; {{sc.title}}.
- Comparison: **unverified**.

### InvDispatch

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: {{title}}; Done; {{sc.title}}.
- Comparison: **unverified**.

### InvPoApprove

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: {{title}}; Done; {{sc.title}}.
- Comparison: **unverified**.

### InvPurchaseRequest

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: {{title}}; Done; {{sc.title}}.
- Comparison: **unverified**.

### InvReceive

- Keyed labels: `chk` I checked each batch and expiry against the boxes
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: {{title}}; Done; {{sc.title}}.
- Comparison: **unverified**.

### InvReceiveTransfer

- Keyed labels: `chk` I counted what arrived, line by line
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: {{title}}; Done; {{sc.title}}.
- Comparison: **unverified**.

### InvReportDiff

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: {{title}}; Done; {{sc.title}}.
- Comparison: **unverified**.

### InvRfq

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: {{title}}; Done; {{sc.title}}.
- Comparison: **unverified**.

### InvSupplierBill

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: {{title}}; Done; {{sc.title}}.
- Comparison: **unverified**.

### InvoiceView

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: `docTabs`, `sideTabs`
- Dialog markup occurrences: 2; headings: Invoice; Kavitha d/o Raman; Bill to; {{xpTitle}}; Print {{doc.kind}}; Are you sure?.
- Comparison: **unverified**.

### JobApplications

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: Steps; {{xTitle}}; {{listTitle}}; {{panelTitle}}; {{cur.name}}; Phone screening checklist; Are you sure?.
- Comparison: **unverified**.

### LabourCost

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: Labour cost; Labour cost by branch, {{monthLabel}}; What counts; {{cfTitle}}.
- Comparison: **unverified**.

### Leave

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: `tabs`
- Dialog markup occurrences: 1; headings: Waiting for you; {{xTitle}}; {{calTitle}}; {{selTitle}}; Roster on those days; Are you sure?.
- Comparison: **unverified**.

### Letters

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: Issue a letter; Templates; {{listTitle}}; Server preview; Issued; Are you sure?.
- Comparison: **unverified**.

### LocumPay

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: Locum pay; Statements; Rates; Enter a rate for {{sheetFor}}; My pay · Dr. Kamal Rahim; {{sName}}; Statement.
- Comparison: **unverified**.

### MC

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 2; headings: Medical certificate; MCs; New medical certificate; Preview; Are you sure?.
- Comparison: **unverified**.

### ManagePayments

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: Mohd Faizal bin Hassan; Rules on this page; {{xpTitle}}; Receipts · {{count}}; {{cur.title}}; Are you sure?.
- Comparison: **unverified**.

### McTemplates

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 2; headings: Medical certificate templates; Template revisions; Fields filled at issue; {{xpTitle}}; {{revTitle}}; Are you sure?.
- Comparison: **unverified**.

### Me

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: `groups`
- Dialog markup occurrences: 0; headings: Good morning, {{first}}; {{tTitle}}; Branch money and targets; {{l.title}}; {{s.title}}.
- Comparison: **unverified**.

### MedicineCatalogue

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: {{xpTitle}}; Medicine catalogue; {{sel.name}}; Prices at Cheras; {{kDlgTitle}}.
- Comparison: **unverified**.

### MedicineDetails

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: `tabs`
- Dialog markup occurrences: 1; headings: Medicine details; {{tab.label}}; Add new {{tab.single}}; Good to know; {{kDlgTitle}}.
- Comparison: **unverified**.

### Memos

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: {{listTitle}}; {{cur.title}}; Who hasn't read yet; How reading works; Write a memo; Your read; {{kDlgTitle}}.
- Comparison: **unverified**.

### Modern

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: {{kDlgTitle}}.
- Comparison: **unverified**.

### MyCredentials

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: My clinician credentials; Dr. Farah N.; Where these print; Staff credentials in KPOne HR.
- Comparison: **unverified**.

### MyQueue

- Keyed labels: `wait` Waiting; `seeing` Seeing now; `back` Back from treatment; `check` Medicine to check; `done` Done today; `hold` On hold; `red` Red zone alert; `mine` My patients; `all` All doctors; `await` Call next; `await` Waiting; `aserving` Serving now; `ahold` On hold; `adone` Completed; `arooms` Rooms today
- Tab/view/group declarations: `visTabs`
- Dialog markup occurrences: 0; headings: {{title}}.
- Comparison: **unverified**.

### OpenShifts

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: Open shifts; {{xTitle}}; {{cur.when}}; Post an open shift; {{kDlgTitle}}.
- Comparison: **unverified**.

### OpeningImport

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: Import opening stock · Cheras; Checked rows; The file; Independent sign-off; {{cfTitle}}.
- Comparison: **unverified**.

### Panel

- Keyed labels: `claim` To claim; `sub` Submitted; `follow` Follow up; `pay` Waiting for payment; `paid` Paid; `query` Queried; `branch` My branch; `all` All branches; `abranch` Claims by branch; `apanels` By Panel; `apay` Payments by branch
- Tab/view/group declarations: `visTabs`
- Dialog markup occurrences: 0; headings: {{title}}.
- Comparison: **unverified**.

### PanelClaims

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: {{xpTitle}}; {{statusTitle}} · {{panName}}; {{dName}}; Photos; Logs; {{kDlgTitle}}.
- Comparison: **unverified**.

### PanelInvoices

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: {{xpTitle}}; {{panel}} · finalised invoices with an approved Panel share; About this list; Your downloads; {{kDlgTitle}}.
- Comparison: **unverified**.

### PanelPayments

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: 1 · The payment; Panel statement; {{xpTitle}}; 2 · Split across accepted AIA claims; 3 · Check; Recorded wrongly later?; {{kDlgTitle}}.
- Comparison: **unverified**.

### PanelPriceChanges

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 2; headings: Panel price changes; Propose a Panel price; {{cfTitle}}.
- Comparison: **unverified**.

### PanelResponsibility

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 2; headings: Panel responsibility; {{xpTitle}}; {{listTitle}}; {{d.name}}; Reason given; {{kDlgTitle}}.
- Comparison: **unverified**.

### PanelSummary

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: Panel summary; {{modeTitle}} by age; How this report counts; {{kDlgTitle}}.
- Comparison: **unverified**.

### PasswordPages

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: {{title}}.
- Comparison: **unverified**.

### Patient

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: Nur Aisyah binti Razak; {{xp.title}}; Visits; Still to collect; Panel; {{xp.title}}; Problems the doctor noted; Change history; {{kDlgTitle}}.
- Comparison: **unverified**.

### PatientBilling

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: Waiting to pay · {{waitN}}; Invoice; {{cfTitle}}.
- Comparison: **unverified**.

### PatientCreate

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 2; headings: Create patient; Identity; Guardian contact (required); Contact; Address; Identifiers; Required by Registration settings; Quicker way.
- Comparison: **unverified**.

### PatientDirectory

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: Patient directory; Today at Cheras; Good to know.
- Comparison: **unverified**.

### PatientEdit

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 2; headings: Edit patient; Edit Patient Master; {{sec.title}}; {{countLabel}}; Identifiers; Change history.
- Comparison: **unverified**.

### PatientMerge

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: `views`
- Dialog markup occurrences: 1; headings: Waiting for approval; Compare the two records; {{xp.title}}; Propose a merge; Decide this request; After approval; {{kDlgTitle}}.
- Comparison: **unverified**.

### PaymentMethods

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: {{xpTitle}}; Payment methods · {{count}}; {{cur.name}}; {{kDlgTitle}}.
- Comparison: **unverified**.

### Payroll

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: Run steps; {{xTitle}}; Staff pay; Employer pays; Checks; {{kDlgTitle}}.
- Comparison: **unverified**.

### Payslip

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 2; headings: Payslip; Payslip · {{month}}; {{sec.title}}; Employer contributions (information); My payslips; Opened from.
- Comparison: **unverified**.

### Pharmacy

- Keyed labels: `prep` To prepare; `docchk` Waiting for doctor's check; `ready` Ready to hand over; `handed` Done today; `returns` Returns to check; `retdone` Returns checked this month; `branch` My branch; `all` All branches; `adisp` Cases by branch; `achecks` Returns and doctor checks
- Tab/view/group declarations: `visTabs`
- Dialog markup occurrences: 0; headings: {{title}}.
- Comparison: **unverified**.

### PharmacyCase

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: Allergies; Medicines; Services; Summary; Patient; {{kDlgTitle}}.
- Comparison: **unverified**.

### PharmacyCompleted

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 2; headings: Dispensary completed; Medicine returns; What the check does; Labels; Follow-up.
- Comparison: **unverified**.

### PilotReadiness

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: `tabStyle`, `tabs`
- Dialog markup occurrences: 1; headings: {{listTitle}}; {{dTitle}}; Why this matters; {{kDlgTitle}}.
- Comparison: **unverified**.

### PreviousConsultation

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 2; headings: Previous consultation; Previous Consultation; Vitals; Clinical Note; Diagnoses; Previous visits; About this page.
- Comparison: **unverified**.

### Pricing

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: Price books; {{xpTitle}}; Charges; {{sel.name}}; Versions in this book; {{kDlgTitle}}.
- Comparison: **unverified**.

### ProfileSettings

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: Profile; Update password; Appearance settings; Your settings; Elsewhere.
- Comparison: **unverified**.

### PublicQrForm

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: `stepTabs`
- Dialog markup occurrences: 1; headings: QR check-in form; Siapa yang mengisi borang?; Maklumat pesakit; Maklumat penjaga; Maklumat perlindungan; Persetujuan &amp; hantar; {{kDlgTitle}}.
- Comparison: **unverified**.

### PublicQrStatus

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: `stateTabs`
- Dialog markup occurrences: 1; headings: QR status page; Status pendaftaran; Cawangan Klinik Putrijaya.
- Comparison: **unverified**.

### PurchaseOrders

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: `tabStyle`, `tabs`
- Dialog markup occurrences: 1; headings: {{xpTitle}}; PO/CHR/2026/0118 · Medipharm Supplies; Approval; Printable order.
- Comparison: **unverified**.

### QrCheckins

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: `tabs`
- Dialog markup occurrences: 1; headings: {{xp.title}}; Waiting for review; {{P.name}}.
- Comparison: **unverified**.

### QrLinks

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: Branches; {{xp.title}}; {{cur.name}} · {{cur.label}}; Link history · {{cur.name}}; Use this QR; Change the link.
- Comparison: **unverified**.

### QueueBoard

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: {{title}}; Call next; {{cfTitle}}.
- Comparison: **unverified**.

### QueueDisplaySettings

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: Call patients by; Rooms; Screen content; Posters ({{posN}} of 8); The TV at Cheras; {{xp.title}}; Who can change this.
- Comparison: **unverified**.

### QuickAnswers

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: `groups`
- Dialog markup occurrences: 2; headings: Quick answers; Questions; {{ans.k}}; How this is answered; Want to ask anything else?.
- Comparison: **unverified**.

### Recalls

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: `tabs`
- Dialog markup occurrences: 1; headings: Recalls; Start a recall; {{selMed}}; Recalled batches; Where it is now; What to do with the stock; Lift the recall; {{cfTitle}}.
- Comparison: **unverified**.

### Receiving

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: This delivery; Lines on the order; Exceptions; Rules on this page.
- Comparison: **unverified**.

### ReferralLetter

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: `groups`
- Dialog markup occurrences: 2; headings: Referral letter; Referrals; New referral; Preview.
- Comparison: **unverified**.

### RegisterVisit

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: Register visit; 1. Patient; Identity; Guardian contact (required); Contact; Address; Identifiers; 2. Visit; Family link; Emergency contact (required for adults); 3. Who pays; 4. Check and register.
- Comparison: **unverified**.

### Registration

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: `tabsDef`
- Dialog markup occurrences: 1; headings: Registration; Today by branch; QR check-ins waiting for review; {{qrX.title}}; {{qrTitle}}; {{R.name}}; Patient directory; {{fuTitle}}.
- Comparison: **unverified**.

### RegistrationSettings

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: New-patient details.
- Comparison: **unverified**.

### ReorderSuggestions

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: Reorder suggestions; Ask for these; How it is worked out; {{cfTitle}}.
- Comparison: **unverified**.

### Reports

- Keyed labels: `daily` Daily summary; `tx` Transactions; `bd` Billing detail; `ps` Panel summary; `pi` Panel invoices; `sm` Stock movement; `sv` Stock value; `svmargin` Cost and margin; `svloss` Losses; `medsales` Medicine sales; `branch` My branch; `all` All branches; `alive` Branches now; `afeed` Activity across branches; `adaily` Daily summary by branch; `aps` Panel summary by branch; `asm` Stock movement by branch; `asv` Stock value by branch
- Tab/view/group declarations: `visTabs`
- Dialog markup occurrences: 0; headings: {{title}}.
- Comparison: **unverified**.

### ReturnsToCheck

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: Waiting for a check; {{xpTitle}}; {{cur.med}}; Who checks.
- Comparison: **unverified**.

### ServiceCatalogue

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: {{xpTitle}}; Clinical service catalogue; {{sel.name}}; Consultation tariff.
- Comparison: **unverified**.

### Sidebar

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: `groups`
- Dialog markup occurrences: 2; headings: {{area.title}}; Not for your role: {{roleLabel}} cannot open {{pageTitle}}; View the canvas as.
- Comparison: **unverified**.

### SignIn

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: Welcome to KPOne.
- Comparison: **unverified**.

### SkuEdit

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 2; headings: SKUs; SKUs; Edit {{code}}; Item; Barcode and storage; Code and units; Legacy pack fields; Status; This SKU now; Who can change SKUs; {{cfTitle}}.
- Comparison: **unverified**.

### Sops

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: {{listTitle}}; {{cur.title}}; Your acknowledgement; Who hasn't acknowledged · {{waitingCount}}; Publish an SOP.
- Comparison: **unverified**.

### Staff

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: Staff; {{CURP.name}}; Roles; Branches; Sign-in; Recent access changes; Rules.
- Comparison: **unverified**.

### StaffClaims

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: `TABS`, `tabs`
- Dialog markup occurrences: 1; headings: Staff claims; {{xTitle}}; {{listTitle}}; {{cur.no}}; Who decides.
- Comparison: **unverified**.

### StaffFiles

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: `TABS`, `tabs`
- Dialog markup occurrences: 1; headings: Staff files; {{fileName}}; {{g.title}}.
- Comparison: **unverified**.

### StatusGuide

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 2; headings: Status guide and targets; Statuses; {{cfTitle}}.
- Comparison: **unverified**.

### Stock

- Keyed labels: `sheet` New stock count; `hand` On hand; `sheet` Count; `low` Low stock and reorder; `ordered` On order; `transfer` Transfers; `expiry` Expiring and held; `wo` Write off; `hana` Hana suggests; `arrived` Deliveries to check; `counts` Counts due; `sheet` Start count; `forms` By form; `hand` Show items; `spare` Other branches need; `diffs` Open differences; `holds` Batch holds; `recalls` Recalls (every branch); `wo` Write off stock; `returns` Returns to check; `preq` Purchase requests; `po` Purchase orders; `recv` Receive delivery; `sinv` Supplier invoices; `sret` Supplier returns; `sprice` Supplier price list; `recalls` Recalls; `holds` Place holds; `cdreg` Controlled register; `sheet` Correct stock with a governed count; `cdledger` Open register; `cdledger` Register of one medicine; `tusage` Treatment room usage; `wo` Expired or held stock?; `sheet` Count sheet; `wo` Submit count; `wo` Write-offs; `packs` Pack checks; `health` Stock health; `sheet` Count this stock; `branch` My branch; `hq` HQ; `requests` Branch requests; `sent` Dispatched; `hqstock` Central stock; `sheet` Count; `buy` To buy; `po` Purchase orders
- Tab/view/group declarations: `visTabs`
- Dialog markup occurrences: 0; headings: {{title}}.
- Comparison: **unverified**.

### StockCount

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: Counts due; {{xpTitle}}; Count sheet; Who does what; Differences after recount.
- Comparison: **unverified**.

### StockHealth

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: Stock health; What the columns mean; Related; {{cfTitle}}.
- Comparison: **unverified**.

### StockMovement

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: `tabStyle`
- Dialog markup occurrences: 1; headings: {{xpTitle}}; Stock movement; How this report counts; Who sees this.
- Comparison: **unverified**.

### StockOtherBranches

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: Stock other branches need; New stock request; Related; {{cfTitle}}.
- Comparison: **unverified**.

### StockValue

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: Journal summary (CSV); Related; {{cfTitle}}.
- Comparison: **unverified**.

### SupplierInvoices

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: `tabs`
- Dialog markup occurrences: 1; headings: Supplier invoices; Record an invoice; Invoice {{dNum}}; Decide this invoice; Who does what; {{cfTitle}}.
- Comparison: **unverified**.

### SupplierPriceList

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: Supplier price list; {{formTitle}}; Related; {{cfTitle}}.
- Comparison: **unverified**.

### SupplierReturns

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: `tabStyle`
- Dialog markup occurrences: 2; headings: Supplier returns; {{xpTitle}}; SR-0021 · Amoxicillin 250 mg capsule; Dispatch; Steps; Credit note; {{formTitle}}; Are you sure?.
- Comparison: **unverified**.

### Suppliers

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 2; headings: {{xpTitle}}; Suppliers; {{sel.name}}; {{formTitle}}; Are you sure?.
- Comparison: **unverified**.

### TerminalCheck

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 2; headings: {{xpTitle}}; {{dayTitle}}; {{cur.name}}; {{formTitle}}; Are you sure?.
- Comparison: **unverified**.

### TransactionDetail

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: `groups`
- Dialog markup occurrences: 2; headings: {{xpTitle}}; Transaction detail; How this report counts; Privacy and limits; Your recent runs; {{formTitle}}; Are you sure?.
- Comparison: **unverified**.

### Transfers

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: `tabStyle`, `tabs`
- Dialog markup occurrences: 2; headings: {{xpTitle}}; TR-0087 · Puchong to Cheras; Open differences; This receipt; {{formTitle}}; Are you sure?.
- Comparison: **unverified**.

### TreatmentRoom

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: {{c.title}}; Routes; {{cfTitle}}.
- Comparison: **unverified**.

### TreatmentUsage

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: `tabStyle`
- Dialog markup occurrences: 2; headings: Choose a batch; {{xpTitle}}; Recorded today; Record treatment room use or waste; Expired or held stock?; {{formTitle}}; Are you sure?.
- Comparison: **unverified**.

### Triage

- Keyed labels: `triage` To triage; `triaged` Triaged today; `treat` Treatment room; `treated` Done today; `transfer` Transfer in; `result` Waiting for result; `doc` With the doctor
- Tab/view/group declarations: `visTabs`
- Dialog markup occurrences: 0; headings: {{title}}.
- Comparison: **unverified**.

### TriageGuideline

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: Versions; Version {{cur.n}}; {{cfTitle}}.
- Comparison: **unverified**.

### VisitDetails

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 1; headings: {{vn}}; Visit Reason; Provisional Panel coverage; Cancelled; Documents; Next for this visit; Consultation.
- Comparison: **unverified**.

### VisitEdit

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 2; headings: Edit visit; Edit registered Visit; What changes; Rules.
- Comparison: **unverified**.

### VisitHistory

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 2; headings: Visits · 9; {{cur.date}} · {{cur.doctor}}; Ordered and dispensed; {{xp.title}}; Documents; {{xp.title}}; {{formTitle}}; Are you sure?.
- Comparison: **unverified**.

### WaitingRoomTv

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: `phaseTabs`, `modeTabs`
- Dialog markup occurrences: 2; headings: Waiting-room TV; Panggilan terdahulu; {{formTitle}}; Are you sure?.
- Comparison: **unverified**.

### WebsiteBranches

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 2; headings: Branches; {{selName}}; Opening hours; Contact blocks; Completeness; Revisions; {{formTitle}}; Are you sure?.
- Comparison: **unverified**.

### WebsiteCareers

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 2; headings: {{listTitle}}; {{selT}}; {{g.title}}; {{s.title}}; {{formTitle}}; Are you sure?.
- Comparison: **unverified**.

### WebsiteDoctors

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 2; headings: Profiles; {{selName}}; Registration and consent; Portrait and branches; Completeness; Revisions; {{formTitle}}; Are you sure?.
- Comparison: **unverified**.

### WebsiteFaqs

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 2; headings: {{listTitle}}; {{selT}}; {{g.title}}; {{s.title}}; {{formTitle}}; Are you sure?.
- Comparison: **unverified**.

### WebsiteLittleShield

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 2; headings: {{listTitle}}; {{selT}}; {{g.title}}; {{s.title}}; {{formTitle}}; Are you sure?.
- Comparison: **unverified**.

### WebsiteMedia

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 2; headings: {{xTitle}}; Upload private image; {{formTitle}}; Are you sure?.
- Comparison: **unverified**.

### WebsitePromotions

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 2; headings: {{listTitle}}; {{selT}}; {{g.title}}; {{s.title}}; {{formTitle}}; Are you sure?.
- Comparison: **unverified**.

### WebsiteReleases

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: `views`
- Dialog markup occurrences: 2; headings: Saved releases; {{selT}}; {{xTitle}}; {{pv.hero}}; Omissions; Director decision; Decision history; Retained media; {{formTitle}}; Are you sure?.
- Comparison: **unverified**.

### WebsiteServices

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: None detected.
- Dialog markup occurrences: 2; headings: {{listTitle}}; {{selT}}; {{g.title}}; {{s.title}}; {{formTitle}}; Are you sure?.
- Comparison: **unverified**.

### WriteOff

- Keyed labels: No literal key/label pair; inspect declaration/runtime.
- Tab/view/group declarations: `tabStyle`
- Dialog markup occurrences: 2; headings: Write-offs; {{xpTitle}}; WO-0034 · Expired stock, Dispensary; Who does what; When you approve; {{formTitle}}; Are you sure?.
- Comparison: **unverified**.

## Wrapper-only or overridden tab routing

| Alias | Runtime target / state |
|---|---|
| AllAgents | Admin {"tab": "agents"} |
| Appraisals | HR "role-dependent function (see HR_TAB or Compliance)" |
| Approvals | Admin {"tab": "inbox"} |
| Attendance | HR "role-dependent function (see HR_TAB or Compliance)" |
| AuditLog | Admin {"tab": "audit"} |
| BatchHolds | Stock {"tab": "holds", "view": "branch"} |
| BillingDetail | Reports {"view": "branch", "tab": "bd"} |
| BillsToCollect | Billing {"tab": "collect"} |
| Branches | Admin {"tab": "branches"} |
| CashClosing | Billing {"tab": "close"} |
| ClaimsFollowUp | Panel {"tab": "follow"} |
| Compliance | Admin "role-dependent function (see HR_TAB or Compliance)" |
| ConnectedApps | Admin {"tab": "apps"} |
| ControlledRegister | Stock {"tab": "cdreg", "view": "branch"} |
| Credentials | HR "role-dependent function (see HR_TAB or Compliance)" |
| Discipline | HR "role-dependent function (see HR_TAB or Compliance)" |
| DoctorChecks | Pharmacy {"tab": "docchk"} |
| FollowUp | Admin {"tab": "inbox"} |
| HqView | Reports {"view": "all", "tab": "alive"} |
| HrDashboard | HR "role-dependent function (see HR_TAB or Compliance)" |
| HrDecide | HR "role-dependent function (see HR_TAB or Compliance)" |
| HrMessages | HR "role-dependent function (see HR_TAB or Compliance)" |
| HrRoster | HR "role-dependent function (see HR_TAB or Compliance)" |
| Incidents | HR "role-dependent function (see HR_TAB or Compliance)" |
| JobApplications | HR "role-dependent function (see HR_TAB or Compliance)" |
| Leave | HR "role-dependent function (see HR_TAB or Compliance)" |
| Letters | HR "role-dependent function (see HR_TAB or Compliance)" |
| LocumPay | HR "role-dependent function (see HR_TAB or Compliance)" |
| ManagePayments | Billing {"tab": "receipts"} |
| MedicineSales | Reports {"view": "branch", "tab": "medsales"} |
| Memos | HR "role-dependent function (see HR_TAB or Compliance)" |
| OpenShifts | HR "role-dependent function (see HR_TAB or Compliance)" |
| PanelClaims | Panel {"tab": "sub"} |
| PanelInvoices | Reports {"view": "branch", "tab": "pi"} |
| PanelPayments | Panel {"tab": "pay"} |
| PanelSummary | Reports {"view": "branch", "tab": "ps"} |
| PatientDirectory | Registration {"tab": "patients"} |
| PatientMerge | Admin {"tab": "merge"} |
| Payroll | HR "role-dependent function (see HR_TAB or Compliance)" |
| PurchaseOrders | Stock {"tab": "po", "view": "branch"} |
| QrCheckins | Registration {"tab": "qr"} |
| Recalls | Stock {"tab": "recalls", "view": "branch"} |
| Receiving | Stock {"tab": "recv", "view": "branch"} |
| RegFollowUp | Registration {"tab": "followup"} |
| RegToday | Registration {"tab": "board"} |
| ReorderSuggestions | Stock {"tab": "low", "view": "branch"} |
| RepDaily | Reports {"view": "branch", "tab": "daily"} |
| ReturnsToCheck | Pharmacy {"tab": "returns"} |
| Sops | HR "role-dependent function (see HR_TAB or Compliance)" |
| Staff | Admin {"tab": "staff"} |
| StaffClaims | HR "role-dependent function (see HR_TAB or Compliance)" |
| StaffFiles | HR "role-dependent function (see HR_TAB or Compliance)" |
| StockCount | Stock {"tab": "counts", "view": "branch"} |
| StockExpiry | Stock {"tab": "expiry", "view": "branch"} |
| StockHealth | Stock {"tab": "health", "view": "branch"} |
| StockMovement | Reports {"view": "branch", "tab": "sm"} |
| StockOnHand | Stock {"tab": "hand", "view": "branch"} |
| StockOtherBranches | Stock {"tab": "spare", "view": "branch"} |
| StockValue | Reports {"view": "branch", "tab": "sv"} |
| SupplierInvoices | Stock {"tab": "sinv", "view": "branch"} |
| SupplierPriceList | Stock {"tab": "sprice", "view": "branch"} |
| SupplierReturns | Stock {"tab": "sret", "view": "branch"} |
| TerminalCheck | Billing {"tab": "terminal"} |
| TransactionDetail | Reports {"view": "branch", "tab": "tx"} |
| Transfers | Stock {"tab": "transfer", "view": "branch"} |
| TreatmentUsage | Stock {"tab": "tusage", "view": "branch"} |
| VisitHistory | Billing {"tab": "history"} |
| WriteOff | Stock {"tab": "wo", "view": "branch"} |

## Guarded runtime tab inventories

- HR:team — decide, corr, ot, decided, roster, open, minstaff, att, today, month, staff, cred, payroll, locum, locumrates, claimpay, claimmonth, sops, memos, letters, hrmsg, appraisals, incidents, discipline, jobs, leaveset, attset, claimset, credset, lettertpl, msgset, apprcrit, jobset.
- HR:me — myleave, myatt, myclaims, myroster, mysops, mymemos, myletters, myappr, myinc, mycred, mylocum, mydir.
- Reports:branch — daily, tx, bd, ps, pi, sm, sv, svmargin, svloss, medsales.
- Reports:all — alive, afeed, adaily, aps, asm, asv.
- Admin:main — inbox, approve, decided, staff, assign, branches, rooms, audit, merge, mergedone, licences, fridge, compother, agents, runs, runstoday, apps.

## Wrapper drawer and global states

- Ask KPOne: closed; Claude/API path; idle/examples; blank-question refusal; waiting; cancelled; answered; Ask again; Connected apps. Demo fixed replies are not real AI responses.
- Sign-in account list; signed-in chip; sign-out; two-press shared-demo reset; branch cycle; role/access refusal; unconnected-action feedback.
- Popup background substitution: Consultation→VisitDetails and Panel→Billing when role cannot open the original background. Verify product privacy independently.

## Remaining audit work

Resolve every discovery mapping at controller method level; enumerate dynamic inline drawers/confirmations from actual runtime per tab; attach all team comparisons. Until then no page or PR is labelled demo-complete. This document deliberately exposes unresolved items rather than counting a regex census as verified UX coverage.
