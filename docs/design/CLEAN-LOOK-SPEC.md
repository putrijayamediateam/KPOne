# KPOne Clean Look: spec (Stage 0)

Status: **reconstructed draft 2, independently reconciled 26 Nov 2026; coordinator reviewed; publication pending.** Based on the recovered PR #1119 draft 1, the uploaded full handoff, local demo boards, approved implementation decisions and recorded evidence. This is not a recovery of the lost Claude-container draft or a new designer sign-off. Authority: the
owner's decision of 25 Nov 2026 (Clean Look replaces Classic, restyled **in place**, no second version, no switch).
Nothing here changes a permission, route, payload, validation or business rule. A restyle never changes who sees what.

Original draft source: the owner's locked canvas "KPOne Clean Look" (https://claude.ai/artifact/Jyr8UV231y4roWRyyqxxRJ), described in draft 1 as read
board by board (Sidebar, Settings, Registration, HomeCA, Modern and the other boards), plus the measured contrast work
in `NEWLOOK-PILOT.md` and the DS-0 tokens (`resources/css/space-shell.css`). Contrast numbers below are **computed**
from the hex values, not eyeballed. Where the canvas misses our rules, this spec says what we use instead (section 9).

## 0. Rules of the road

1. **In place.** Same shared components, same pages, same files. A shared component change restyles every page that
   uses it; that is the point. No `look-new:` prefixes, no `data-look`, no parallel folders, no new-look preference.
2. **Tokens, not hexes.** A page never writes a hex or a palette class (`bg-pink-50`, `text-emerald-700`). Pages use the
   token names in section 2. The old per-page tints go away in the page PR that touches them.
3. **Light and dark ship together.** Every token has both values; every PR shows light and dark.
4. **All GUIDE.md rules still apply** (one filled button per view, status as dot plus word, 44 px targets below 1024 px,
   names under the staff-visibility rule, money never splits across lines, read-only pages render no controls, reasons
   start empty). Section 9 lists the few places where the canvas shows something GUIDE forbids and what wins.
5. **The canvas is a mock-up.** It shows decoration we do not build: the "Proposal" and "Being built" tags, the "Viewing
   as <role>" switcher, journey and map boards, the fictional numbers. Do not build those. What the canvas shows is the
   look, not the feature list; backend gaps are the Leads' gap lists to B.E.R.N.I.S.S.

## 1. What the look is, in one paragraph

Calm, Apple-like and mostly white. A light-grey page (`#f5f5f7`), white cards with a very soft shadow and 14 px
corners, one blue accent for the main action, selection and links, pill-shaped segmented tabs, large quiet headings,
generous rows, and a thin icon rail on the left that opens a menu panel on hover. KPOne pink stays for the logo mark
only. Dark mode is a near-black page with raised dark cards, the same blue made lighter.

## 2. Tokens

Names are the existing ones in `resources/css/app.css` (so every component already reading them restyles at once), plus
the additions marked **new** in draft 1. Core values shipped in #1120; the radius/card/sheet follow-up is tracked separately in section 14.

| Token | Light | Dark | Notes (measured) |
|---|---|---|---|
| `--background` | `#f5f5f7` | `#0b0b0d` | Page |
| `--card`, `--popover` | `#ffffff` | `#141416` | Cards, rows, panels, menus |
| `--card-quiet` **new** | `#fafafc` | `#1b1b1e` | One level inside a card (field wells, table header) |
| `--foreground`, `--card-foreground` | `#1d1d1f` | `#f5f5f7` | 16.9:1 on dark card; light ≥ 15:1 |
| `--muted-foreground` | `#6e6e73` | `#a1a1a6` | 5.07:1 on white, 4.66:1 on `#f5f5f7`; dark 7.15:1 on `#141416`. **Never `#86868b`** (3.62:1) |
| `--muted` | `rgba(0,0,0,0.06)` | `#2c2c30` | Segmented-control track, hover wash, quiet chips |
| `--accent`, `--accent-foreground` | `rgba(0,0,0,0.06)` / `#1d1d1f` | `#232327` / `#f5f5f7` | The shadcn "accent" is a hover wash, not the blue |
| `--primary`, `--primary-foreground` | `#006ae0` / `#ffffff` | `#4d94ff` / `#0b0b0d` | The blue for **fills and text** (owner: accent blue, KPOne pink on the logo only). 5.07:1 white on it; dark label 6.55:1 |
| `--accent-blue` **new** | `#007aff` | `#4d94ff` | The owner's exact blue, for **non-text** use only: rail icon, selected wash and indicator, focus ring, switch knob accents, chart highlight. 4.02:1 on white, enough for graphics (3:1), not for 13 to 14 px text |
| `--ring` | `#007aff` | `#6ba6ff` | Focus ring (graphic, 3:1). Dark 7.47:1 on `#141416` |
| `--link` **new** | `#006ae0` | `#6ba6ff` | Text links. 5.07:1 on white, 4.6:1 on `#f5f5f7`; dark 7.47:1 |
| `--border` | `rgba(0,0,0,0.08)` | `#2a2a2e` | **Decoration only** (card edges, row dividers) |
| `--input` | `#8a8a93` | `#6b6b73` | **Edge of a control** (fields, outline buttons, selected states). 3.42:1 on white; 3.48:1 on dark card |
| `--field-fill` | `#ffffff` | `#1b1b1e` | Field background |
| `--brand`, `--brand-strong` | `#c2185b` / `#9f1239` | `#ff6b9d` / `#ff9ec0` | Logo mark only |
| `--success` | `#1d7a3a` | `#30d158` | Text. 5.39:1 on white; dark 9.1:1 |
| `--success-wash` **new** | `#e9f8ee` | `#12281a` | Chip background. Text on it 4.91:1; dark 7.73:1 |
| `--warning` | `#8a5a00` | `#ffb454` | Text. 5.43:1 on `#fff4dc`; dark 10.4:1 |
| `--warning-wash` **new** | `#fff4dc` | `#33240f` | Chip and banner background |
| `--info`, `--info-wash` **new** | `#1a4fa0` / `#e8f0fe` | `#6ba6ff` / `#1a2a44` | 6.86:1; dark 5.84:1. Information, "waiting", counts |
| `--destructive` | `#c7141f` | `#ff6961` | Text and fill. 5.92:1 on white; dark 6.52:1 |
| `--sidebar-*` | rail `#fbfbfd`, line `rgba(0,0,0,0.08)` | rail `#1b1b1e`, shell `#141416`, line `#2a2a2e` | Section 6 |
| `--radius` | `0.875rem` (14 px) | same | `--radius-xl` and `--radius-lg` map to `--radius`; `--radius-md` 10 px, `--radius-sm` 8 px, pills `980px` |
| `--shadow-card` **new** | `0 1px 2px rgba(0,0,0,.04), 0 2px 8px rgba(0,0,0,.03)` | `none` (the border carries it) | Cards only. No shadow on rows inside a card |
| `--shadow-pop` **new** | `0 18px 50px rgba(0,0,0,.18), 0 1px 2px rgba(0,0,0,.06)` | `0 18px 50px rgba(0,0,0,.6)` | Menus and dialogs |

The canvas's purple (`#6d28d9`) marks "proposal" only. It does not ship. Red dots for "needs you" use `--destructive`.

`color-scheme: light` / `dark` stays in `:root` / `.dark` (UX-8). Chart colours keep their current values until the
Insights team restyles the charts (Stage 2).

## 3. Type

- **Family:** `-apple-system, BlinkMacSystemFont, 'SF Pro Text', Geist, Inter, 'Segoe UI', system-ui, sans-serif`. Geist
  and Inter are **self-hosted** (never loaded from Google; that is the standing privacy decision). Kavitha names the
  package and puts the files under `public/fonts`. The system stack carries the page while they load.
- **Scale (px, weight):** page title 26/600 (-0.02em), 30 from 1024; hero or greeting 28 to 34/600; section title 17
  to 19/600; card title 15/600; body and rows 14/500-400, line height 1.45; secondary text 13; metadata and help
  12.5 (never below 12; the canvas uses 10.5 to 11.5 in places, we do not). Big figures 28 to 56/700.
- **Figures:** `font-variant-numeric: tabular-nums` on every money amount, count and time. Money never wraps.
- **Case:** sentence case everywhere (titles, buttons, menus, chips). No all-caps labels.
- **Colour of text:** `--foreground` for what you read and act on; `--muted-foreground` for metadata and help.

## 4. Spacing, radius, size

- **Page:** 16 px side padding below 640 px, 24 px to 1023, 32 px from 1024. Content column max 1280 px centred from
  1440 px, except boards that are tables or kanban, which may use the full width.
- **Approved case-layout exception (26 Nov):** the existing Dispensary case keeps its 1500 px frame and side-column widths; three columns start at Tailwind `xl` (1280 px). Below that, patient → medicines/services → actions stack. This does not change the control breakpoint: 36 px from 1024, 44 px below 1024. Existing clinical field groups and action order remain.
- **Gaps:** 16 between cards, 24 between page sections, 8 between a label and its field, 12 inside a row group.
- **Cards:** `--card`, 14 px radius, `--shadow-card`, padding 16 (phone) or 20. A card header is 15/600 with a quiet
  action on the right.
- **Rows** inside a card: min height 48 (56 when two lines), 1 px `--border` divider inset 16 px from the left. No card
  inside a card; use `--card-quiet` for a well.
- **Controls:** minimum 36 px tall from 1024 px, **minimum 44 px below 1024 px** (GUIDE; including tablets). Buttons and tabs are **pills** (`980px`);
  fields and selects are 10 px radius; icon buttons are 36/44 square with 10 px radius.
- **Switch:** 44 × 26, knob 22; on-state track `--success` fill with the knob right and a word beside it ("On"/"Off"
  or the setting's own label). Colour is never the only signal.
- **Segmented control** (Week | Month, All | Waiting): `--muted` track, 3 px padding, pill segments, selected segment
  `--card` with `--shadow-card`, unselected label `--foreground`. Used for a few views of the **same** data.
- **Tabs with counts** (Registration: All 34, Waiting 12): pills with a count chip; selected pill `--primary` wash
  (`rgba(8,82,218,.08)` light, `rgba(77,148,255,.16)` dark) with `--link` text, and a count chip using `--info-wash`.
- **Focus:** 2 px `--ring` outline, 2 px offset, on every interactive element. Never removed.
- **Motion:** 180 ms colour transitions, 300 ms panel slide. `prefers-reduced-motion` turns all of it off.

## 5. Components: canvas element → shared component to change

Owner: Kavitha (Staff Experience) changes these once, in the tokens PR and the components PR. Teams do not restyle a
shared component in their own PR; they report a gap to Kavitha and Daniel.

| Canvas element | Shared component (in `resources/js/components`) | Change |
|---|---|---|
| Main action (blue pill) | `ui/button` default | `--primary` fill, pill, 36/44 px, 14/500. Exactly one per view |
| Row and secondary actions | `ui/button` outline | Pill, `--input` edge, `--foreground` label, transparent fill |
| Quiet action ("Change logo") | `ui/button` link/ghost | `--link` text, no edge, 44 px target |
| Destructive | `ui/button` destructive | `--destructive` fill, **only inside a confirm dialog** (GUIDE) |
| Card | `ui/card` | Section 4 |
| Page header (title, subline, segmented view, action) | `ui/page-header` (`PageHeader`) | Title 26/600, one subline 13 muted, segmented control and the single main action on the right. Slots stay |
| Segmented control, tabs with counts | `OperationalTabs`, `ui/tabs` | Section 4. Phone: horizontal scroll with right-edge fade, 44 px |
| Status chip | `StatusBadge`, `ui/badge` | Dot plus word, `-wash` background, 12.5/600, 8 px radius; five tones: success, warning, info, destructive, neutral (`--muted`). Never a bare coloured pill |
| Table | `OperationalTable`, `CompactPagination` | `--card`, header row `--card-quiet`, 13/600 muted headers, 52 px rows, hover wash, numbers right-aligned tabular; cards below 1024 px |
| Field, select, date, time, textarea | `ui/input`, `OperationalSelect`, `ui/date-field`, `ui/textarea` | 36/44 px, 10 px radius, `--input` edge, `--field-fill`, focus ring, label above 13/500, help below 12.5 muted, counter on text boxes |
| Switch | `ui/switch` | Section 4 |
| Dialog (confirm, form) | `ui/dialog`, `OperationalConfirmDialog` | `--popover`, 14 px radius, `--shadow-pop`, 20 px padding, close X 44 px, backdrop below |
| Side sheet | `ui/sheet` | Same surface; full screen below 640 px with actions pinned and safe-area padded |
| Menu, dropdown, popover | `ui/dropdown-menu`, popover | `--popover`, 1 px `--border`, `--shadow-pop`, 12 px radius, items 40 px (44 on touch), hover wash |
| Toast | Sonner `Toaster` | Dark pill in both themes (`rgba(29,29,31,.92)`, white text), 12 px radius, max 520 px, bottom centre (consultation focus mode keeps its one approved exception) |
| Alert / banner | `ui/alert` | `-wash` background, icon plus words, 12 px radius, no left border stripe |
| Disclosure | `ui/disclosure`, collapsible | Chevron that turns, 44 px row, `--card-quiet` header |
| Empty state | `ui/empty` (add if missing) | Muted icon, one sentence saying what is missing and what to do next |
| Avatar, initials | `ui/avatar` | 36 px circle `#e5e5ea` / `#2c2c30`, 12.5/600 |
| Breadcrumb | `ui/breadcrumb` | 13 muted with `/`, current page 13/600 |

**Glass popup rules (from the canvas):** the **backdrop** behind a dialog is `rgba(0,0,0,.18)` light (`rgba(0,0,0,.5)`
dark); the **blocking overlay** page (for example "Not for your role", a full-page notice) may use a white 88% wash
with a 3 px blur. Menus and dropdowns are solid, never translucent: text on a translucent surface cannot be measured.
No blur behind text we need to read.

## 6. The shell: sidebar, header, account

Replaces the top bar (`AppHeader`, `WorkspaceHeader`, `AppSidebar`) in `AppShell`; Kavitha owns it.

**Structure (desktop, from 1024 px).** A **72 px icon rail** on the left, always visible, plus a **248 px menu panel**
that opens next to it.

- Rail, top to bottom: KPOne mark (36 px, pink "P" logo, links to the user's home); then area buttons in four groups
  separated by hairlines: **Today** | **Front desk, Consultation, Dispensary** | **Billing, Panel** |
  **Inventory, HR, Reports**; at the bottom **Settings**, then the account avatar (links to Me) with the online dot.
  Each rail button is 44 × 44, 21 px stroke icon (1.7), selected: `--accent` wash and `--primary` icon.
- Needs-attention marker on a rail icon: an 8 px `--destructive` dot with a 2 px rail-coloured ring, **plus** the
  count in the panel (never the dot alone for the meaning).
- Panel: area title 17/600, a search button, a **pin** toggle, a close button; then sections. A section has a 22 px
  icon, a 14/500 label, an optional count chip, an optional "+" add action (28 px, 44 on touch), and an indented
  tree of child links (13.5, 32 px rows, 1.5 px left rail line). The current page is bold on a hover wash.
- **Behaviour:** hover (or focus) on the rail opens the panel after 120 ms and closes it 300 ms after the pointer or
  focus leaves; click or tap on a rail button opens it (touch has no hover); **Pin** keeps it open and shifts the page
  right; the choice is remembered per person in local storage (not a new database preference). Escape closes an
  unpinned panel. `prefers-reduced-motion`: no slide.
- **Branch switcher** and **duty line** ("On duty since 08:02") sit at the bottom of the panel, above the account.
- **Account (avatar):** link to Me; its menu holds Profile, Password and look (theme), Sign out. The "Viewing as <role>"
  switcher in the canvas is a demo tool. **Do not build it.**

**Below 1024 px.** The rail becomes a drawer opened by a 44 px menu button at the top left (logo centred, branch
switcher and avatar right, as in the canvas phone header). The drawer shows the rail areas as a list, then the panel
content for the chosen area, full width. Every target 44 px. The drawer closes when a link is followed (UX-1).

**Menus come from the server, not from a role table.** The panel content is built by `ClinicManagementNavigation`
(existing, permission-filtered) so a link appears only for someone who may open it. The canvas's `ROLE_BOARDS` table is a
demo and is not copied. A section with no visible child is not shown; an area with no visible section is not shown.
The "Proposal" and "Being built" tags are not shown. Counts (Waiting 12, To pay 9) come from existing endpoints; where
none exists the chip is simply not shown (that is a backend gap for the owning team's list, not a reason to fake it).

**Page frame.** Each page: page header (title, subline, segmented view, the one main action at the right), then the
content. No top bar. The branch name appears in the panel and as a quiet subline, not as a second header.

**The TV and the public pages** (QR form, status, careers, sign-in) keep their own layouts (`PublicCheckInLayout`,
`AuthLayout`, the TV screen) and take only the new tokens and fonts. The TV is not restyled to the sidebar look and **stays in English** (owner, 25 Nov).

## 7. Homes ("Today" and "My day")

One layout, role-specific contents; each home is a **grid of cards** on the page background:

1. A greeting line (28 to 34/600, "Good morning, Aina") with the duty line and branch as a muted subline.
2. A **"Needs you" card** first, as a list of rows with a count chip and one outline action each ("Waiting patients 12
   · Open queue"). It is the one place the page's main action may live.
3. Role cards, two columns from 1024 px, one on phone: the CA's day (clock in, my routine, this week), the
   supervisor's approvals, the doctor's queue, finance's money day, the Director's overview. Contents are the pages'
   existing data; no new endpoint is assumed.
4. Quiet cards at the end: shortcuts, updates.

Real pages: `MyDay`, `Dashboard`, `Spaces/*`, `Insights/*`. Branch Manager, Account Assistant, Store Admin and Medical Assistant already exist in `PermissionCatalogue`. Their homes use existing permissions; a demo role label does not grant access or authorise a new permission.

## 8. Dark mode

Derived from the canvas's own dark sidebar values (`#0b0b0d` page, `#141416` shell, `#1b1b1e` rail, `#2a2a2e` line):
page `#0b0b0d`, card `#141416`, quiet well `#1b1b1e`, divider `#2a2a2e`, control edge `#6b6b73`, text `#f5f5f7`,
muted `#a1a1a6`, blue fill `#4d94ff` with `#0b0b0d` text. Cards lose their shadow and keep a 1 px `--border`. Washes are
dark tints of the status colour with the bright status colour as text (section 2). Printed documents, drug labels and
the TV in print keep a white page (PRINT-1) whatever the theme.

## 9. Where the canvas breaks our rules (what wins)

| Canvas shows | Problem | We use |
|---|---|---|
| Blue `#007AFF` fill with white text | 4.02:1, fails 4.5:1 for 13/14 px text | `#006ae0` for fills and link text (5.07:1, the same blue to the eye); `#007aff` itself for icons, selection, focus ring |
| Grey `#86868b` text | 3.62:1 on white | `--muted-foreground` `#6e6e73` |
| Text 10.5 to 11.5 px | Under our 12 px floor | 12 px minimum, 12.5 for metadata |
| Pale hairline (4 to 8% black) as the edge of fields and buttons | Edge under 3:1 | `--input` `#8a8a93` on controls; hairline stays for cards |
| Several filled blue pills in one view (rows, "Use") | One filled button per view | One filled; row actions outline |
| Status as a coloured pill without a word or dot (some boards) | Colour alone | Dot plus word, always (`StatusBadge`) |
| Pale-pink tints, emoji, "Proposal" tags, role switcher, fake numbers | Not product | Not built |
| Green switch alone | Colour alone | Switch plus a word |

## 10. Check before a page PR asks for my look

- Light and dark shots at 1440 and 390 (more only if the page is odd), no sideways scroll.
- No hex or palette class in page diffs. The final Classic-remnant guard is the recorded Sunday sweep task, not a claim that an exhaustive guard has already shipped.
- One filled button; row actions outline; every status dot plus word; 44 px targets below 1024 px.
- Text 12 px or more; contrast of anything new measured, not guessed.
- Same behaviour: the PR body lists every changed existing assertion (class-string tests are expected to change; the
  owner approves them as a group).
- Page title via `PageHeader`, content in cards, tables via `OperationalTable`.

## 11. Board → route → owner

Canvas board (file under `project/`) → current KPOne page → owning team. Stage 1 = Puchong CA flow (by Sat 28 Nov
20:00); Stage 2 = everything else (by Sun 29 Nov 18:00). "Shell" is Kavitha's one shell PR. A board marked **n/a** is a presentation-only map or guide. Family link, family bill, Treatment room and Emergency lane are authorised work in the 25 Nov handoff, subject to their domain notes and recorded review gates; do not exclude them merely because the demo calls them proposals.

### Stage 0 (shared)
| Board | Page | Owner |
|---|---|---|
| Sidebar | AppShell, AppSidebar, AppHeader, WorkspaceHeader, ClinicLayout | Kavitha (shell PR) |
| Modern, StatusGuide, Settings (pattern) | tokens, `ui/*` components | Kavitha (tokens and components PR) |

### Stage 1: Puchong CA flow
| Board | Real route (current) | Owner |
|---|---|---|
| Registration, RegisterVisit, WfRegisterVisit | `/registration`, `/registration/create` | Nurul |
| QrCheckins | `/registration` QR Intake tab; `/check-in/intakes/{id}` review | Nurul |
| PatientDirectory, Patient, PatientCreate, PatientEdit, PatientMerge | `/patients`, `/patients/create`, `/patients/{id}`, `/patient-merges` | Nurul |
| VisitDetails, VisitEdit, VisitHistory | `/visits/{id}`, edit visit, `/visits/{id}/history` | Nurul |
| Triage, TriageGuideline, EmergencyAlert | `/triage`, `/triage-guideline` | Nurul (Syazwan) |
| MyQueue, QueueBoard, WfQueueBoard | `/queue` (CA board, doctor "My queue") | Amirul |
| WaitingRoomTv | `/queue-display` | Amirul (tokens and fonts only) |
| Consultation, PreviousConsultation, TreatmentRoom, MC, ReferralLetter, AttendanceLetter | `/encounters/{id}` consultation, certificates, referrals | Nurul (clinical pages) |
| Pharmacy, PharmacyCase, PharmacyCompleted, ReturnsToCheck, DrugLabel, DoctorChecks, DispensarySettings | `/dispensary/*` | Aisyah |
| Billing, PatientBilling, ManagePayments, InvoiceView, BillingDetail (page), BillsToCollect, FamilyBill, CashClosing, TerminalCheck, BillingMap (map: n/a) | `/visits/{id}/billing`, `/payments`, `/pay-later-collections`, `/cash-closings`, `/payment-reconciliations` | Farah |
| Stock (On hand, Low stock, Expiring), StockCount, WriteOff, Receiving, InvReceive, InvReportDiff, Transfers, InvBranchRequest, InvDispatch, InvReceiveTransfer, InvMyWork, TreatmentUsage | `/inventory`, `/inventory/counts`, `/inventory/writeoffs`, receiving, `/inventory/transfers`, `/inventory/treatment-usage` | Zulkifli |
| HomeCA, HomeSup, HomeDoctor, Me | `/my-day`, `/me`, Today | Kavitha |

### Stage 2: everything else
| Board | Real route (current) | Owner |
|---|---|---|
| Panel, PanelClaims, CreateClaim, ClaimPack, ClaimsFollowUp, ClaimMessageTemplate, PanelPayments, CorrectPanelPayment, PanelInvoices, PanelSummary, PanelPriceChanges, PanelResponsibility, HomePanel | `/panel-claims`, `/panel-invoices`, `/panel-remittances`, `/panel-payment-corrections`, `/panel-price-changes`, `/checkout-settings` | Syafiq |
| MedicineCatalogue, MedicineDetails, ServiceCatalogue, Pricing, PriceCheck, Suppliers, SkuEdit, ConsultationSettings, RegistrationSettings, PaymentMethods, ApprovalLimits, CheckoutSettings, McTemplates, ClinicianCredentials, QrLinks, QueueDisplaySettings, Settings (Business details) | settings and catalogue pages | Adlina (QrLinks and QueueDisplaySettings: Nurul and Amirul reviewers) |
| Purchase*, Inv* (PO, RFQ, supplier bill), SupplierInvoices, SupplierPriceList, SupplierReturns, ReorderSuggestions, Recalls, ControlledRegister, StockHealth, StockMovement, StockValue, StockOtherBranches, OpeningImport, PilotReadiness, BatchHolds, HomeStore, InvTimeline, InvLanes, InventoryFlow (map: n/a) | `/inventory/*` | Zulkifli |
| HR, Leave, Attendance, OpenShifts, Credentials, MyCredentials, Sops, Memos, Letters, StaffFiles, StaffClaims, Payroll, Payslip, LocumPay, LabourCost, Appraisals, Incidents, Discipline, JobApplications, HrMessages, HrDashboard, HomeHR, Compliance, CareersPublic | `/hr/*`, `/careers/*` | Liyana |
| AgentOffice, AgentDailySummary, AllAgents, Approvals, QuickAnswers, Insights, Reports, DailySummaryOld, TransactionDetail | `/agents/*`, `/approvals`, `/insights/*`, `/reports/*` | Irfan (Reports pages with billing content: Farah reviews) |
| DirectorHome, DirectorOverview, HomeFin, HomeAdm, HomeOffice | Today for those roles | Kavitha |
| Staff, Admin, AccessControl, Branches, ProfileSettings, PasswordPages, SignIn, AuditLog, HqView | `/staff`, `/access-control`, `/branches`, `/settings/*`, auth pages, `/audit-logs` | Kavitha |
| WebsiteBranches, WebsiteCareers, WebsiteDoctors, WebsiteFaqs, WebsiteLittleShield, WebsiteMedia, WebsitePromotions, WebsiteReleases, WebsiteServices | `/website/*` | Kavitha's team (Stage 2, tokens only unless time) |
| PublicQrForm, PublicQrStatus | `/check-in`, `/check-in/status` | Nurul (tokens and fonts; patient screens stay Bahasa Melayu) |
| HomeBM, HomeAA, HomeMA, HomeStore | existing role home / My day routes, permission-filtered | Kavitha; domain owners review their cards |
| JourneyMap, BillingMap, InventoryFlow, StatusGuide | none | **n/a** (maps and guides) |

A board that exists in the canvas but has no row above gets a row from the owning team's gap list; the Lead tells
B.E.R.N.I.S.S, and Daniel adds it here.

## 12. Recorded design decisions (do not reopen)

1. ~~Main action colour~~ **Decided (owner, 25 Nov): accent blue `#007aff`; KPOne pink on the logo only.** Spec applies it as
   `#007aff` for graphics and `#006ae0` for text and button fills, because `#007aff` under white text measures 4.02:1.
   This is the settled colour split, not a pending colour question.
2. **Pill buttons and tabs.** The canvas uses full pills for all buttons; the 18 Nov note kept rows at 10 to 14 px.
   This spec follows the canvas (pills) for buttons and tabs, 10 px for fields.
3. **Fonts.** Geist and Inter are already self-hosted on main through #1120; use the existing files and licences.

## 13. Stage 0 implementation sequence (original plan)

1. Tokens (`app.css`), fonts, `color-scheme`, the token contrast test.
2. `ui/*` components in section 5 order: button, input family, badge, card, tabs, table, dialog and sheet, menus,
   switch, toast, alert, empty state. One PR.
3. Shell: rail, panel, drawer, page frame, account. One PR.
4. Role homes.

Every PR: light and dark shots at 1440 and 390 of the **ten busiest existing pages** (before and after) so a shared
change is seen on real content.

## 14. Reconciliation snapshot (26 Nov)

This is a point-in-time coordinator snapshot, not deployment approval. Individual PRs retain their own exact-head CI and review gates.

- Core tokens and self-hosted fonts merged in #1120. Shell #1125 merged at `88210df4347404506ac55f49d0afb14f974e9385`. The shared radius/card/sheet follow-up #1129 merged at `14701efae9106f568947e5286db2a47ee06a0009`; its post-merge CI is a separate release gate.
- Desktop rail has a three-letter branch chip with full accessible name and tooltip; its panel contains the switcher. Phone header contains the switcher. PageHeader carries the branch subline. Pin preference is per person and panel focus accounts for the portalled branch control.
- Legacy `rounded-xl border bg-card` uses the 14 px radius/card shadow, with no duplicate nested shadow. Sheets use popup surface/foreground/shadow and 18% light / 50% dark overlay. No role or business-state behavior changes.
- Queue review evidence now covers the recovered Q16a–f slices, including `<1 min`, patient-named details, vital units and warning styling for unreviewed allergies. Local implementation evidence does not mean those branches are merged.
- Dispensary preserves the existing workflow: doctor Verify completes the case; no invented Ready tab, approvals inbox or follow-up link. The case restyle and its six owner-approved assertion groups are reviewed local work. Its approved `xl` layout and 36/44 px control minima are recorded in section 4. Remaining shared attention/check partials are separate scoped work.
- The final Classic-remnant sweep and guard remain Sunday work. This spec does not certify every screen compliant. Patient-facing screens remain Bahasa Melayu and TV/staff screens English.
- Deadlines (MYT): Stage 0: 26 Nov 20:00; Stage 1: 28 Nov 20:00; Stage 2: 29 Nov 18:00; Puchong CA test: 30 Nov 08:30. These are handoff targets, not reported completion times.

## 15. Provenance and bounded review

The original draft 1 is the version of this path at commit `bbc896d69c4e3e5298e7e62d34deddecb557df12` in PR #1119. The full handoff states that Daniel's draft 2 was never committed and may be lost. This document is a reconstruction reviewed by the current Staff Experience team, not recovered draft 2 bytes or Daniel's historical sign-off. Canvas descriptions and contrast calculations are inherited from draft 1; this reconciliation inspected the supplied local demo and actual CSS tokens and does not claim a new live-canvas session or contrast recalculation.

Resolved owner choices remain settled: replacement in place/no theme switch, blue accent/pink logo only, self-hosted fonts, permission-filtered role homes, authorised domain work subject to its recorded gates, and the exact approved case breakpoint/assertion changes. No new product feature or role grant is introduced. Remaining implementation discrepancies are scoped follow-up work, not a reason to reopen settled choices. Existing assertion changes still require explicit approval.
