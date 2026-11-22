# APPR-1: the "Approvals & tasks" inbox (design note)

Status: **owner decisions recorded (22 Nov 2026), not built.** Owner decision 30 (20-21 Nov 2026, `docs/decisions/2026-11-20-finance-inventory-proposals.md`):
"One 'Approvals & tasks' inbox listing every approval waiting for the person; each opens its own page to decide (the rules behind each
approval do not change)." This note says what the inbox lists, how it counts, who sees what, and how it is built in slices. It
authorises nothing by itself. Synthetic data only. Written by Staff Experience (Kavitha, with Aiman's review to come) on 22 Nov 2026
from a read-only survey of `main` (every route, controller and service that holds a waiting-for-a-person decision).

## 1. What it is, and is not

- **Is:** one page, `/approvals`, that lists the decisions waiting for **this person**, grouped by area, each row opening the page that
  already owns the decision. Plus a small number (count) the person can see without opening it.
- **Is not:** a place to decide. The inbox never approves, rejects or checks anything, and it never copies a rule. Every approval keeps
  its own page, permission, branch check, self-bar (maker is never checker), lock, audit and idempotency exactly as today.
- **Is not:** a notification system. Nothing is sent by message, email or push; the inbox is read when the person opens KPOne.
- **No new permission** is created. A person sees an item only if they hold that item's existing decide permission **and** (for a branch
  item) have an effective assignment at the active branch.

## 2. What the survey found (facts, 22 Nov 2026)

- There is **no** generic inbox, task list or "waiting for me" feature. The only inbox is the AI proposals inbox (`/agents/inbox`).
- A few counts exist in department spaces: HR space "Approvals waiting (N)" (leave, attendance corrections, overtime, staff claims),
  Pharmacy `returnsToCheck`, Stock `openingsWaiting`, Front desk `qrPending`, Billing today's cash close. The Clinic management hub and
  the top bar carry no numbers.
- About **35 kinds** of waiting decision exist (table in section 3). Roughly a third already have a list page with a count; the rest
  have a status column but no cross-record list (refunds, responsibility proposals, receiving exceptions, supplier returns, governed
  counts, transfer differences, pack conversions, many HR queues).
- Two things are wrong today and should be fixed by the owning teams, not by the inbox: the HR space claims count includes claims the
  viewer cannot decide (their own), and the website release approval has no maker-is-not-approver bar (only a typed release number).

## 3. What the inbox lists

Each source below is "one thing waiting for a person to decide". **Scope** B = per active branch, O = whole organisation.
**Barred** = who may not decide it, so it must not appear for them.

### 3.1 Money (Billing, Panel, Finance)
| Source | Decide permission | Scope | Waiting means | Barred (hidden for) | Opens |
|---|---|---|---|---|---|
| Daily cash close | `payments.cash_closing.approve.branch` | B | latest count with no decision, day ended | the counter | `/cash-closings` |
| Refund | `payments.refunds.approve.branch` | B | recorded, no decision | the recorder | the visit's billing page (no list page today) |
| Panel or pay-later proposal | `coverage.approve.branch`, `outstanding.approve.branch` (and an approval limit) | B | proposal `proposed` | none (limit-gated) | the visit's billing page |
| Panel claim adjustment | `panel.claims.adjustments.confirm.branch` | B | `requested` | none | the billing / Panel view |
| Panel price change | `panel.prices.approve.organisation` | O | `proposed` | the proposer | `/panel-price-changes` |
| Staff claim: approve | `hr.claims.approve.organisation` | O | `waiting` | the claimant (and rank rule) | `/hr/claims` approvals |
| Staff claim: pay | `hr.claims.pay.organisation` | O | `approved`, unpaid | claimant and approver | `/hr/claims` pay |

### 3.2 Stock and purchasing (Inventory, Dispensary)
| Source | Decide permission | Scope | Barred | Opens |
|---|---|---|---|---|
| Governed count or write-off approval | `inventory.variances.approve.branch` | B | proposer, counter, recounter | `/inventory/counts`, `/inventory/writeoffs` |
| Receiving exception | `inventory.receiving.exceptions.approve.branch` | B | the receiver | Inventory receiving panel |
| Supplier return (single or group) | `inventory.supplier_returns.approve.branch` | B | the requester | `/inventory/supplier-returns` |
| Supplier invoice | `inventory.supplier_invoices.approve.branch` | B | the recorder | `/inventory/supplier-invoices` |
| Purchase order | `inventory.purchase_orders.approve.organisation` | O perm, B list | whoever raised or submitted it | Inventory home |
| Purchase request (review: convert or reject) | `inventory.purchase_orders.create.organisation` (`ProcurementService::CREATE_PERMISSION`; the person who **raises** a request needs `inventory.purchase_requests.create.branch`, which is not a decide permission) | B | the requester (`refuseRequester`) | `/inventory/purchase-requests` |
| Stock request (transfer) | `inventory.stock_requests.approve.branch` | B | none (AI-raised: the raiser) | Inventory home |
| Transfer difference | `inventory.variances.approve.branch` | B | the receiver | Inventory home |
| Opening balance sign-off | `inventory.opening.verify.branch` | B | whoever recorded it | `/inventory/readiness` |
| Pack conversion check | `inventory.pack_conversions.verify.organisation` | O | the proposer | `/inventory/skus` |
| Medicine return check (DSP-7) | `inventory.variances.approve.branch` + `dispensary.view.branch` | B | the recorder | `/dispensary/returns-to-check` |
| Agent proposal | `inventory.stock_reviews.manage.branch` (view: `ai_proposals.view.branch`) | B | who started the run | `/agents/inbox` |

### 3.3 Patients, clinical, governance
| Source | Decide permission | Scope | Barred | Opens |
|---|---|---|---|---|
| Patient merge | `patients.merge.approve.organisation` | O | the proposer | `/patient-merges` |
| Triage guideline draft | `triage.guideline.manage.organisation` | O | the entrant | `/triage-guideline` |
| Website release / output plan | `website.releases.approve.organisation` (Director) | O | none today (typed release number) | the release page |
| Doctor's check of prepared medicine (a **task**) | `dispensary.doctor_check.own` (the assigned doctor) / `.override.branch` (CA Supervisor) | B | to confirm in the verify service | **not registered in v1**: today the only list page, `/dispensary/doctor-checks`, requires `.override.branch`, so a doctor's row would open a 403. Dispensary decides whether the doctor gets a reachable page (or the row opens the case page); the source is added when that exists |
| Fridge out-of-range review | `compliance.fridge.review.branch` | B | none | `/clinic-compliance` |
| Compliance task to verify | `compliance.audits.perform.branch` | B | who marked it done | `/clinic-compliance` |

### 3.4 HR (all O scope; all barred for the subject or the entrant)
Leave request; leave opening or adjustment entry; attendance correction; overtime; roster week (approve or return); bank detail
confirmation; staff record correction; credential record; payroll pay profile, statutory table, MTD schedule and payroll run
(approve, return, lock); appraisal sign-off; open-shift ask; locum pay rate; incident (acknowledge); plus tasks: memo and SOP to read.
Permissions and routes are listed in the survey; every one is already an `hr.*.manage|approve|review.organisation` permission.

### 3.5 Deliberately **not** in v1 (state, not a queue)
Active recalls to lift, held batches, the controlled-register check, stock review worklists (low / expiring), QR check-in review
(already shown by the Registration board), invoice adjustment reversal and payment reversal (immediate second-person actions, no queue),
discipline cases, staff account requests. They stay on their own pages; a phase 2 may add "tasks" if the owner wants them.

## 4. Design

### 4.1 One contract per source (the owning team writes it)
A source is a small class in the owning team's domain folder, registered in one list. It declares:

1. `key` and a plain English `label` (for example "Refunds to approve").
2. `permissions()`: the decide permission(s) (the inbox calls the same check the owning page uses).
3. `scope()`: branch or organisation.
4. `count(User $actor, Branch $branch): int`: a **dedicated `COUNT(*)`** that already excludes anything the actor is barred from
   deciding. It must come from **one shared query or scope class that the owning page also uses for its own list**, so the inbox can
   never disagree with the page and no rule is copied. **Where the page's rule today lives only inside a `*PageService`** (the
   survey found this for the HR queues, whose rank and self-bar rules sit inside the page services), the owning team first extracts
   that rule into a small shared scope class and points its page at it; that extraction is the first step of its slice (APPR-1d for
   HR) and is covered by the page's existing tests. The inbox never imports a `*PageService`.
5. `rows(User $actor, Branch $branch, int $limit): list<InboxRow>`: up to 5 items, **only for a source whose items have a document
   number** (visit, invoice, claim, receipt, request or document number, as the owner allowed on 22 Nov). **An item is a fixed label owned
   by the source, the document number, a rounded age ("waiting 3 days") and a link.** A source cannot supply free text, a name, an
   amount or a reason: the item type has only those fields. A source whose items have no number (for example a leave request) shows
   its one summary row and does not expand. Per-record links carry the record's public id (a UUID) and no data.
   The count and the existence of waiting items are shown only to people who hold the decide permission.
6. `route`: the named route of the owning page (and a parameter where the page is per record).

Why this shape: the rules stay with the owning team (Billing keeps the refund rule, HR keeps its rank rule), the inbox cannot
copy or weaken them, and adding a new approval later is one class plus one test.

### 4.2 The page
`/approvals`, title **Approvals & tasks**, uses the shared `PageHeader`. Sections by area (Money, Stock, Patients and clinical, HR),
each with its sources as rows: label, count, oldest waiting (for example "oldest 3 days"), and an **Open** button to the owning page.
A source the person cannot decide, or that has zero waiting, is hidden (not greyed). An empty inbox says "Nothing is waiting for you."
Expanding a source shows up to 5 items (no more; the owning page has the full list). Mobile: one card per source, 44 px targets, no
horizontal scroll. Dark mode through theme tokens only (UX-13 pattern). Daniel's look is required before build.

### 4.3 The number
A single read-only endpoint, `GET /approvals/count`, returns the total and per-area totals for this person at the active branch.
It runs **only dedicated `COUNT(*)` queries**, never a page service (the survey shows the HR space count builds whole page payloads
and must not be reused). **Access is never cached:** on every request the server re-checks the person's decide permission and
effective branch assignment for each source. Only the raw per-source counts are cached, 60 seconds, under a key that includes the
**user id** and the branch id (the own-item exclusion differs per person, so a count is never shared between people). A person's
own decision on an item clears that person's cached counts; any other staleness is accepted for at most 60 seconds, and a count never
grants or keeps access. The number is polled every 60 seconds only while the tab is visible and never blocks a page render. The same
branch-local midnight and Asia/Kuala_Lumpur fallback as My day apply to "waiting N days". The number shows in two places: on the **Me** row of the account menu (with a small number on the account menu button), and on a
Clinic management row. (KPOne is Classic only since CO-1; there is no sidebar.) It never shows a patient name, amount or reason.

### 4.4 Privacy and safety
- Rows carry **no patient name, IC, phone, amount, reason, note or file name**. A row says what kind of thing and how long it has waited.
  (Whether money items may show an amount is owner question Q2.)
- The count and rows are computed server-side with a fresh permission check and an effective branch assignment; the Vue page is only a
  convenience. A person with no assignment at the active branch sees no branch items (the page never lists a branch they cannot work in).
- **Who may open the page.** The page opens for any human role that has at least one source, approval or task. Because memos and
  SOPs to read are held by every human role (`hr.sops.acknowledge.own`), in practice every human role can open it; only the TV
  account gets 403. **The number, the account-menu badge and the Clinic management row appear only for people who hold at least one
  approval decide permission**, so someone with only "to read" tasks sees those tasks on My day instead of a number everywhere.
- **Other branches.** The "N waiting at other branches" line counts only branches where the person has an **effective assignment**
  and holds that source's permission, and it is computed only when the inbox page is opened (not in the nav count), so the cost is
  not multiplied on every page.
- The inbox writes nothing (no audit row per view; it is a count of data the person can already see on the owning pages).

### 4.5 What it does not change
No rule, permission, route, migration, table or approval behaviour of any owning page. `/agents/inbox` stays as it is and is listed as one source.

## 5. Slices (each its own PR, owner ship-it and Daniel's look)

| Slice | Owner | Content | Existing assertions touched |
|---|---|---|---|
| APPR-1a | Staff Experience | the contract, the registry, `/approvals` page with an Approvals section and a Tasks section, `/approvals/count`, the number next to Me, a Clinic management row, the "N waiting at other branches" line; **sources: only the five that already have a cheap count** (cash close, patient merge, Panel price change, supplier invoice, purchase request) | **One line** in the exact map of `ClinicManagementTest::test_hub_returns_only_authorized_implemented_destinations_for_every_catalogue_role`: `'/approvals' => [<explicit list of the approval decide permissions>],`. The list is written out in the test so a new source visibly widens who gets the row, and a second test fails if the explicit list and the registry's approval permissions differ. If a source also needs a role (as the DSP-7b returns list needs `ca_supervisor`), its hub exception is added the way DSP-7b's was and listed then. `DepartmentSpaceMatrixTest` is not affected. |
| APPR-1b | Inventory (Zulkifli) | stock sources (3.2), reusing each page's own list query | none expected |
| APPR-1c | Billing (Farah) | refund, responsibility, adjustment; a "Refunds waiting" list so the refund has a place to open | none expected |
| APPR-1d | HR (Liyana) | all HR sources (3.4), and the HR space claims count is fixed to exclude the viewer's own | the HR space count assertion, if it pins the old number |
| APPR-1e | Patients, Panel, Dispensary, Compliance, Website | the remaining sources | none expected |

Each team's PR adds its source classes and **one contract test per source**: the source declares a permission and a route that
exists; the count equals the owning page's own waiting count for a seeded case; the actor who made the item (the barred person) sees
0; a role without the permission sees nothing and gets no row; the count query runs without N+1.

### Shared tests Staff Experience writes in APPR-1a
- every registered source passes the contract (permission present in the catalogue, route resolves, scope declared);
- `/approvals` and `/approvals/count`: 403 for the TV account and any role with no source; 200 with the right per-area counts for a
  seeded Director, Finance Officer, CA Supervisor, HR Manager, Branch Manager, Account Assistant and Store Admin (ROLE-1 roles);
- no patient identifier, amount or reason appears in either payload (key and value scan, like `AggregatePayloadGuard`);
- a branch item never appears without an effective assignment at the active branch;
- the count endpoint does not call a page service (a source test fails if `*PageService` is imported by an inbox class);
- the cache never keeps access: after a role or branch assignment is removed the next request shows nothing, and counts for two
  people with different own-item exclusions are never shared;
- the other-branch line counts only branches with an effective assignment and the source's permission;
- a row has no field other than label, rounded age and link (type test), and a midnight test uses the branch time zone.

## 6. Questions for the owner (answered 22 Nov 2026; see section 9)

1. **Approvals only, or tasks too?** The doctor's check of prepared medicine, and memos or SOPs to read, are tasks assigned to one
   person, not decisions. My recommendation: show approvals first, add the doctor check and "to read" items as a second section in
   v1 (they are already per person), and leave state items (held batches, recalls) out.
2. **Show an amount on money rows?** For example "Refund RM 45.00 waiting". My recommendation: no amounts or names in the inbox in v1;
   the owning page shows them. A Finance Officer who wants the amount opens the row.
3. **Where does the number live?** My recommendation: next to **Me** and on a link in the account menu, plus a row in Clinic
   management. The Clinic management row needs one line added to an existing exact-map test (an assertion change I will list for you).
4. **Several branches.** A Director or someone who can switch branch works in one active branch at a time. My recommendation: the
   inbox shows the active branch and one muted line "N waiting at other branches" (with the branch names) for people who can switch;
   the org-wide HR, Panel price and patient merge items always show.
5. **Fix two existing gaps now?** (a) the HR space claims count includes the viewer's own claims; (b) a website release has no
   "someone other than the maker approves" bar. I recommend (a) is fixed in APPR-1d and (b) is raised with the Website owners as a
   separate decision (it changes an approval rule, so it is not part of the inbox).
6. **Refund and responsibility have no list page.** I recommend Billing adds a small "waiting" list (APPR-1c), so a row opens
   something useful instead of a single visit.

## 7. Risks
- **Cost:** about 35 counts. With dedicated COUNT queries, a 60 second cache and polling only while visible, the cost per person is
  bounded (one cached call a minute). Latest-action-per-case counts (governed cases, supplier returns) are the heaviest and may need
  an index or a status column; Inventory decides in APPR-1b.
- **Drift:** if a team changes a page's waiting rule and forgets the source, the inbox disagrees with the page. The contract test
  (count equals the page's own waiting count) is the guard, and each source reuses the page's own query.
- **Scope creep:** the inbox must stay read-only. A "Decide here" button is out of scope and would need the owner's decision, because
  it would move a rule out of its owning team.

## 8. After this note
Owner answers the six questions; Daniel looks at the page sketch (section 4.2); Aiman reviews; then APPR-1a is built. "My day" (decision
31) comes next as its own note and will link to this inbox.

## 9. Owner decisions, 22 Nov 2026 (the owner's own words, relayed by B.E.R.N.I.S.S)
"Approvals inbox: 1 yes, 2 no, 3 yes, 4 yes, 5 yes, 6 yes."
1. **Tasks are a second section; states are left out.** v1 Tasks: the doctor's check of prepared medicine, and memos and SOPs to
   read. Held batches, active recalls, the controlled-register check, stock review worklists and similar states stay on their own pages.
2. **No amounts** (nor names or reasons) on any row.
3. **The count sits next to Me, plus a Clinic management row.** The exact test line is in section 5 (APPR-1a); it is listed for the
   owner before any edit.
4. **Active branch plus a muted "N waiting at other branches"** (with branch names) for people who can switch branches. Organisation-wide
   items (HR, Panel price, patient merge) always show.
5. **The HR space claims count is fixed to exclude the viewer's own claims, in the HR slice (APPR-1d).** The website release
   maker-is-not-approver gap is a **separate later item**, not part of this work.
6. **Billing adds a small "waiting" list for refunds and Panel responsibility proposals** (APPR-1c), so those rows open a useful page.
Next: Aiman's review, Daniel's look at the page sketch (section 4.2), then APPR-1a.

## 10. Daniel's design points and the owner's document-number decision, 22 Nov 2026
Daniel: OK with changes. Sketches: `docs/design/sketches-2026-11-22/` (`approvals-*`, and `sketch.html#approvals`).
- **One row per source:** label, count, "oldest N days" in muted words, and an outline **Open** button. No amber or red age colours in v1
  (if wanted later, use the Q-1 waiting-time pattern).
- **Expanding a source** shows up to 5 items, each with its **document number**, because the owner answered "document numbers yes" (visit,
  invoice or claim number; still no patient name, amount or reason). A source without numbered items shows only its row.
- **The number's place in the Classic top bar:** a small **neutral pill** with the number on the account menu button (accessible name
  "Approvals & tasks, 3 waiting"; not red; not a live region), repeated on the "Approvals & tasks" row inside the account menu and the
  phone drawer, and on the Clinic management row. Hidden at 0.
- **Tasks use the same row** as approvals (label, count, Open), sit after Approvals, and each area heading matches the Clinic
  management group names.
- **"N waiting at other branches"** is a muted line under the area it belongs to, naming the branches, with **no link to switch
  branch** from there.
- **On a phone:** one card per source, the count and the Open button on one line, 44 px targets, nothing scrolling sideways.
- Both notes need Daniel's build shots after APPR-1a and DAY-1a.

## 11. Owner approval of the test change, 22 Nov 2026
"approve the My day and Approvals inbox test changes, inbox first, document numbers yes": the one `ClinicManagementTest` exact-map line in
section 5 is approved as listed. Build order: APPR-1a first, then DAY-1a and DAY-1b. Both slices still need the owner's ship-it before a PR
opens.
