# KPOne — full handoff for continuing elsewhere (26 Nov 2026)

This file is self-contained. It is written so another assistant (for example ChatGPT) can pick up the work with no access to the Claude sessions. It combines: (A) what KPOne is and the rules that govern it, (B) the current state as of 26 Nov 2026 about 00:30 UTC, and (C) the full coordinator handoff of 25 Nov with every owner decision.

- **Repository:** `github.com/putrijayamediateam/KPOne` (private). `main` is canonical.
- **This file lives on branch** `claude/gallant-gauss-mw7ht3` as `docs/HANDOFF-FULL-2026-11-26.md`. The shorter coordinator handoff is `docs/HANDOFF-2026-11-25.md` on the same branch (neither is on `main`).
- **Talk to the owner in Bahasa Melayu.** Code, commits and docs stay in English. Owner approvals are given as multiple-choice (tick-box) questions, never as open questions.

---

# Part A. What KPOne is

KPOne is Klinik Putrijaya's internal digital operating platform: a modular-monolith Laravel app for a clinic group with one organisation and **three branches (Cheras, Sungai Besi, Puchong)**.

**Stack:** PHP 8.4, Laravel 13, Inertia 3 + Vue 3 + TypeScript, Tailwind 4, PostgreSQL 18. Laravel Herd on the owner's Windows laptop for development; GitHub Actions CI (jobs: attest, static, backend shards 1-3, ci).

**Domains (`app/Domain`):** Organisation (branches, public check-in links, Inventory) · Identity · Access (`PermissionCatalogue`) · Audit · Patient · Visit · Queue · Clinical (encounter, allergy, treatment plan, checkout, hold, Dispensary) · Shared. Business rules live in domain services and policies, never only in controllers or Vue.

**Roles:** director, resident_doctor, ca (clinic assistant), ca_supervisor, panel_officer, finance_officer, business_development, marketing, hr_manager, technical_admin, administrator, queue_display (waiting-room TV), plus newer branch_manager, account_assistant, store_admin and medical_assistant. Roles and permissions are separate; permissions are named with `.own`, `.branch` or `.organisation`.

**What is built and merged (high level):** staff identity and RBAC; Patient Master; Registration and Visits; Consultation Queue with waiting-room TV calls; clinical encounter, allergies, treatment plan; Dispensary and OTC; checkout, invoices, receipts, payments, Panel (corporate/insurer) responsibility and manual Panel Claims; Insights and CSV reports; Medical Certificate, referral and attendance letters, drug labels; inventory (receiving, holds, stock counts, write-offs, transfers, purchasing programme, opening import); KPOne HR (roster, leave, attendance with photo, payroll, locum portal, etc.); draft-only website content; a rule-based AI agent platform with an optional Claude connector; triage backend. Over 1,100 PRs merged. Nothing is production-approved; Yezza (the clinic's current system) remains the operational source of truth until the owner decides otherwise.

**Non-negotiable rules (from `AGENTS.md` and `CLAUDE.md`):**
- Never create, read, print or commit a real `.env`; use `.env.example` names only. No secrets in code.
- Synthetic data only (fictional `.test` identities), except the one written exception below for kpjaya.com inventory and prices.
- Every state-changing operation: backend permission and branch check, `DB::transaction`, row locks, optimistic `lock_version`, idempotency key where retries are possible, and an audit record (no secrets in audit).
- Account creation only through `StaffProvisioningService`; no public registration; unknown Google identities never create users.
- Do not build features whose phase is not authorised (see `docs/BASELINE.md` section 4).
- Never weaken a test to pass. **Changing an existing test assertion, or adding a permission, needs the owner's approval.**
- Do not commit or push unless the owner asks after reviewing the diff. Never force-push, never rewrite someone else's branch, never push to `main` directly.
- Staff screens are in English; patient-facing screens in Bahasa Melayu; the TV stays in English.
- Test servers must never load the real `.env`; use a task-owned database.

**Quality gate before any hand-off:**
```bash
php artisan test
composer run lint:check
composer run types:check
npm run types:check
npm run lint:check
npm run format:check
npm run build
node --test tests/Frontend/*.mjs
php artisan migrate:fresh --seed   # local/test database only
git diff --check
```
Note: `tests/Frontend/nav-02-top-bar.test.mjs` shells out to plain `php`; in containers where plain `php` is not the project's PHP 8.4 it fails locally, but it passes in CI. Not a real failure.

**Release flow:** implementation → independent review → green CI on the exact head with `main` contained → merge (one PR at a time) → post-merge CI.

**Team model (all are AI "builder team" personas, not real staff):** each area has a Lead who builds and a Reviewer who checks, with a memory file `docs/teams/<area>/TEAM.md`:
| Area | Lead | Reviewer | Designer |
|---|---|---|---|
| Staff Experience (shell, homes) | Kavitha | Aiman | — |
| Patients & Registration (+ triage, patient flow) | Nurul (+ Syazwan, Aqilah) | Hafiz (+ Farhana, Imran) | Balqis |
| Queue & TV | Amirul | Mira | Yasmin |
| Dispensary & OTC | Aisyah | Hakim | Iman |
| Billing | Farah | Kumar | Siti |
| Inventory | Zulkifli | Mei Ling | Wei Jie |
| Panel | Syafiq | Priya | Arjun |
| Catalogue & settings | Adlina | Ravi | Nadia |
| HR | Liyana | Suresh | Hui Min |
| Agent platform | Irfan | Shalini | Ezra |
| Design (owns `docs/design/`) | Daniel (Lead UI/UX) | — | — |
The coordinator (B.E.R.N.I.S.S) assigns work, relays owner decisions, checks CI and merges. Teams never merge and never edit `docs/BASELINE.md`.

---

# Part B. Current state (26 Nov 2026, about 00:30 UTC = 08:30 MYT)

**`main` is at `23639679`.** Merged on 25 Nov: #1120 Clean Look tokens, #1127 DSP-27 dispensary list, #1122 IMP-1 catalogue import (merged by this coordinator after checking: CI green on the exact head `fcdb3b98` with main contained, Mei Ling's OK, owner ship-it, no screen).

**All Claude team sessions stopped.** The owner said "stopkan kejap" (pause). Eight team sessions had already failed at about 12:26 UTC on 25 Nov with "You've hit your 5-hour limit" (Claude usage limit). The weekly limit for several teams resets Fri 27 Nov 06:00 UTC. Irfan's session was interrupted by the coordinator at the owner's request.

**Open PRs (merge queue, serial; refresh from `main` only on "your turn"):**
| Order | PR | Team | State now |
|---|---|---|---|
| 1 | #1125 Clean Look shell (head `72d5d5d0`) | Kavitha | Daniel OK. CI red in backend shard 3. The fix is the two owner-approved test changes (DisciplineTest:69 and PharmacySpaceTest:73), not yet pushed; then merge `main` (`23639679`) in and re-run CI. |
| 2 | #1119 Clean Look spec (head `bbc896d6`) | Daniel | Draft 2 was written but **never committed** (only in Daniel's Claude container; may be lost). Needs redoing or recovery. |
| 3 | #1121 Q-16a zone order | Amirul | Was green; needs refresh from main. |
| 4 | #1123 Q-16b red-zone alert | Amirul | Daniel OK with one fix: "0 min" becomes "<1 min". |
| 5 | #1126 Q-16c details panel | Amirul | Daniel accepts the side panel once three items are in: patient named in the header, vitals with units, "Not yet reviewed" allergies as a warning badge. |
| 6 | #1124 PNL-30 approve selected | Syafiq | Needed on Sunday for the Panel price proposals from IMP-1; status of Syafiq's check unknown (session hit the limit). |
| 7 | #1111 TOAST-INV | Zulkifli | Waiting. |
| 8 | #1109 Panel settings toast | Syafiq | Waiting. |
| 9 | #1112 BIL-36 | Farah | Waiting. |
| 10 | #1113 BIL-37 | Farah | Waiting. |
| 11 | #1128 REL-AG wording (head `e5f28db5`, docs) | Irfan | Shalini OK, changes applied. |
| 12 | #1118 REL-HR docs (head `7e9bec56`) | Liyana | Suresh's review and the owner's line-14 wording recorded. |
| 13 | #1117 SUG-1b | Adlina | Owner ship-it with one assertion change. |

**Merge check for each PR:**
```bash
git fetch origin main
git fetch origin refs/pull/N/head:refs/tmp/pN
git merge-base --is-ancestor origin/main refs/tmp/pN   # must pass
```
Then confirm all CI checks are green on that exact head, the Reviewer's OK (and Daniel's for screens) is recorded, and merge with the expected head SHA.

**Pushed branches without a PR yet:**
- `feature/clean-look-registration` @ `dfe98f85` (Nurul): Registration Clean Look restyle; its 5 test changes are owner-approved; needs Daniel and Hafiz.
- `feature/clean-inv-1-stage1-restyle` @ `a8048811` (Zulkifli): first inventory restyle; one owner-approved test change.
- `feature/dsp-28-returns-history` @ `51d316cf` (Aisyah): B3 + B5, owner ship-it; needs TEAM.md row, gate, PR.
- `feature/auto-1c-doctor-warning` (Nurul): owner ship-it; needs Daniel and Hafiz.
- `feature/rel-pr-approved-wording` (Nurul): owner ship-it with its test group; needs Daniel and Hafiz.
- `feature/q-16d-rooms-today`, `feature/q-16e-average-wait`, `feature/q-16f-tv-calls-today` (Amirul).

**Work that exists only in stopped Claude containers (not on GitHub; treat as possibly lost):** Daniel's spec draft 2; Kavitha's #1125 test fixes; Nurul's Consultation restyle start; Farah's billing restyle start; Irfan's Agents/Insights/Reports restyle (`feature/ag-cl-agents-restyle`, not on origin); Aisyah's restyle plan.

**Waiting on the owner:**
1. BIL-38: its scope is unknown (never pushed by the old session). The owner chose "rebuild" and must describe what BIL-38 should do; only then can its two test changes be approved.
2. Dispensary gap items B4, B6-B10: wording lost; Daniel was asked to rebuild the list from the Clean Look canvas. Not done yet.
3. Card fix for Kavitha (agreed by Daniel, no test changes): in `@theme inline` set `--radius-xl: var(--radius)`; add `@layer components { :where(.rounded-xl.border.bg-card) { box-shadow: var(--shadow-card); } :where(.bg-card .rounded-xl.border.bg-card, [data-slot=card] .rounded-xl.border.bg-card) { box-shadow: none; } }`; and ui/sheet uses the `--popover` surface with a backdrop rgba(0,0,0,.18) light, .5 dark. Include before/after shots of Billing and Registration at 1440 px light.

**Deadlines (MYT):** Stage 0 Thu 26 Nov 20:00 (tokens, components, shell) · Stage 1 Sat 28 Nov 20:00 (CA flow) · Stage 2 Sun 29 Nov 18:00 (everything else) · live CA test at Puchong on kpjaya.com Mon 30 Nov 08:30. If a feature is not ready by Sunday afternoon, propose hiding its buttons rather than risk the CA flow.

**Important if continuing in ChatGPT:** ChatGPT cannot reach the Claude team sessions. It can only act on GitHub if you connect it; otherwise it can advise, review diffs you paste, and write code for you to commit. Keep the same rules: no `.env`, synthetic data, owner approval for test-assertion and permission changes, one PR merged at a time after green CI.

---

# Part C. Coordinator handoff (25 Nov 2026, updated through 26 Nov)

## 1. The goal: Monday 30 Nov 08:30 MYT (00:30 UTC)

The owner runs a live test with the CAs at the **Puchong** branch on **kpjaya.com**. Before then:

1. **Clean Look replaces Classic.**
   - The look is the owner's locked canvas "KPOne Clean Look": https://claude.ai/artifact/Jyr8UV231y4roWRyyqxxRJ. The clickable demo is https://claude.ai/artifact/KUkn7hc8xW9k2REtRUCFbG.
   - It is restyled **in place**, page by page, on the same shared components.
   - There is no second version, no switch and no V2-style folder.
   - The spec is `docs/design/CLEAN-LOOK-SPEC.md` (PR #1119).
2. **Every canvas feature gets a working backend.** The owner chose "build everything before Monday", including:
   - the canvas proposal boards: Family link, family bill, Treatment room, Emergency lane;
   - the HQ purchasing slices;
   - BIL-33 and BIL-34.
3. **Sunday 29 Nov evening, on kpjaya.com:**
   - First, the owner gives a separate "go" for the data clean: keep the staff, remove the rest of the dummy data. Back up first, and show the list of what will be removed before removing anything.
   - Then switch the AI connector off. Irfan is writing the steps; nobody changes the server without the owner.
   - Then import the catalogue and prices with IMP-1:
     ```
     php artisan inventory:import-catalogue catalogue.csv --branch-prices=branch_prices.csv --actor=<named Administrator> --panel-proposer=<named Panel Officer> --panel-reference="<ref>" --branch=PUCHONG
     ```
     Run it with `--dry-run` first.
   - The Director or Finance Officer approves the Panel price proposals with "Approve selected" (PNL-30, #1124).
   - Verify the base units on Pilot readiness.
   - Import opening stock with unit cost through `/inventory/opening-import`.
   - A second person signs off the opening stock.
   - Smoke-test the whole CA flow.
4. The owner shares the inventory file using these templates: `catalogue.csv`, `branch_prices.csv` and `opening_<branch>.csv`. The columns are in `docs/teams/inventory/IMP-1.md` on branch `feature/imp-1-catalogue-import`.

**Stages (MYT):**

| Stage | Deadline | Scope |
|---|---|---|
| Stage 0 | Thu 26, 20:00 | Tokens, components and shell |
| Stage 1 | Sat 28, 20:00 | The CA flow: Registration, queue and TV, consultation and triage, dispensary, billing and daily close, inventory, and the homes for CA, CA Supervisor and doctor |
| Stage 2 | Sun 29, 18:00 | Everything else |

**Risk:** the account's weekly usage limit is nearly spent for several teams (Nurul, Liyana, Syafiq, and Kumar as a subagent). It resets **Fri 27 Nov 06:00 UTC (14:00 MYT)**. If a feature is unfinished by Sunday afternoon, propose hiding its buttons for Monday rather than risking the CA flow.

## 2. Owner decisions on 25 Nov (tick box in the coordinator session)

**Clean Look and kpjaya.com**
- The accent is blue: #006ae0 for fills and text, #007aff for non-text. Pink stays on the logo only.
- The TV stays in English.
- Fast process:
  - Daniel's OK and the team Reviewer's OK, then green CI on the exact head with main contained, then the coordinator merges.
  - The owner gives one ship-it per group.
  - Changed existing assertions and new permissions still go to the owner.
- **Written exception:** the clinic's real inventory and its HQ and branch prices may go on kpjaya.com for the Puchong test. Patients stay fictional, and the AI connector stays OFF on kpjaya.com.

**Import (IMP-1, #1122)**
- The command is approved.
- The actor is a named Administrator. The Director lacks `prices.publish`, by design.
- Branch prices are Self-pay only.
- `unit_cost` goes in at opening.
- Panel prices are filed as proposals, not published.

**Queue (Q-16)**
- Zone order: red, then yellow, then the rest; within a zone, Urgent first, then arrival.
- The red-zone alert goes to **all the branch's resident doctors**. This is a narrow TRI-1c exception: the alert shows the queue number, the minutes waiting and "taken by" only.
- The details panel is shown to CA, CA Supervisor, Medical Assistant and the patient's own doctor, with no new permission.
- Bulk selection is selection only.
- The TV signed-in line is dropped.

**TRI-2 Emergency lane:** all 9 answers are A, with a **new permission** `queue.emergency.respond.branch` for resident_doctor. It reuses Amirul's Q-16b alert.

**Inventory**
- STK-50 (only the Director approves POs) and STK-51 (only Finance decides supplier bill mismatches) are approved, with their assertion lists.
- STK-46 gets two **new permissions**:
  - `inventory.cost.enter.organisation` for finance_officer and store_admin;
  - `inventory.cost.check.organisation` for finance_officer, store_admin and director.
- STK-46 replay rule: correct the average from the start, with a "Changed on" note.
- Receipts from before STK-10B may get a hand cost.
- The Suppliers page gains "Payment terms (days)". Its one assertion change is approved.
- STK-61: the remainder of a short dispatch becomes a new request.
- "Paid in Bukku" has no second check.

**BIL-33 (void approval)**
- Finance Officer and CA Supervisor may ask for a void.
- The Branch Manager approves; the Director is the fallback when a branch has none.
- **New permission** `invoices.void.approve.branch` for branch_manager and director.
- A pending void does not block payment.

**BIL-34 (refund approval)**
- `payments.refunds.approve.branch` goes to branch_manager.
- **New permission** `payments.refunds.review.branch` for finance_officer.
- Finance and the Director keep approving as a fallback.
- The review threshold is a **per-branch setting**, default RM300.
- The review applies to all refunds.
- **Wait for Kumar's review before building** (after the Fri reset).

**HR, documents and suggestions**
- REL-HR: the wording is agreed, the code PR waits for go-live, and the location consent text is final.
- SUG-1b: ship-it, with one assertion change.
- Stage 0 tokens (#1120) and shell (#1125): ship-it, with their listed assertion changes.

**Answered 25 Nov 2026 (tick box, next coordinator session):**
- Shell #1125: `DisciplineTest` line 69 checks the case URL `/hr/discipline/<id>` instead of the bare `/hr/discipline` index path. Approved.
- `PharmacySpaceTest:73`: the Pharmacy link moves to `/dispensary`. Approved.
- Family link F1: (A) reuse `patients.update.organisation`; no new permission.
- Family link F2: (A) no; relatives are never suggested by a shared phone number.
- Family link F3: (A) the Consultation "Family" card shows only relatives waiting today plus a link; no relatives' conditions.
- The emergency contact field on RegisterVisit: **before Monday** (the owner chose B, against the recommendation). Nurul's team builds it in Stage 1, after the Registration restyle; if it is not ready by Sunday afternoon, propose hiding it rather than risking the CA flow.
- STK-50: `InventoryWalkSeederTest` lines 87 to 90 expect the demo PO to be approved by the Director instead of the Finance Officer. Approved.
- STK-47/60 and STK-48: (A) the "Central store" and "Quotes (RFQs)" links move to the Inventory task links; the one combined change to `dd-12-inventory-headers.test.mjs` (18 links become 20, "Orders and suppliers · 7" becomes 8) is approved.
- The Thursday request-day reminder: (A) shown only to CA Supervisor and Store Admin; CAs do not request from the store; no permission change.
- AUTO-1c (doctor busy or absent warning chip with "Reassign" on the Registration and Queue boards; no new permission or migration): ship-it. Opens after Daniel's and Hafiz's OK.
- REL-PR (approved wording for the MC, referral letter and attendance confirmation, printed without the DRAFT banner on new copies, on the clinic doctor's verbal approval of 25 Nov; QR privacy notice version `approved-2026-11-25-v1`; issued copies stay frozen; public QR is not switched on): ship-it, and the listed assertion changes are approved as one group (MedicalCertificateTest, ReferralTest, AttendanceTest, ClinicianCredentialsTest, `registration-contact-settings.test.mjs`, `classic-only-co9-kept-public-show.test.mjs`).
- REL-HR line 14 (owner in Liyana's session, verbatim: "lulus wording baris 14 seperti dicadangkan"): the Leave settings defaults label becomes "KPOne defaults chosen by the owner. Change them here if the clinic's rules differ." Recorded in HR-PLAN 30.2 and 30.4 item 5 on #1118 (head `7e9bec56`). The code change still waits for go-live.
- Clean Look is a full replacement (owner, restated 25 Nov): no second design, no switch, and no Classic remnants when the work is done. Added step, Sun 29 Nov afternoon: Daniel sweeps every screen against the Clean Look canvas, and Kavitha adds a guard test that fails if the old Classic styles (12 px card corners, pink accent, retired classes) come back. The guard may not change existing assertions without the owner.
- Dispensary B3 and B5 (`feature/dsp-28-returns-history`: "Checked this month" tab and the Controlled filter on Returns to check; read only, no permission, migration or assertion change): ship-it.
- Dispensary B4, B6, B7, B8, B9 and B10: their wording was never pushed. Daniel rebuilds the gap list by comparing the Dispensary screens on main with the Clean Look canvas and demo; Aisyah builds from Daniel's list.
- Registration Clean Look restyle (`feature/clean-look-registration`, Nurul): the five class-string assertion changes are approved as a group: `dark-1.test.mjs` S1-001 and S1-008, `ui-dropdown-darkmode-polish.test.mjs` 88-91, `reg-6-phone-input-fit.test.mjs` line 62, and `ux-3-accessible-names-headings.test.mjs` (the Registration h1 becomes visible).
- CLEAN-INV-1 (Zulkifli): `stk6-transfer-shortages.test.mjs:88` changes from `/dark:/` to `/bg-warning-wash/` (approved by the owner by tick box in Zulkifli's session).
- Rule-based AI agents on kpjaya.com (agents:daily-run, agents:inventory-review; they call no outside service): (A) they stay enabled after the real inventory and prices are imported. This replaces the 21 Nov "fictional data only on kpjaya.com" answer for those agents only. The Claude connector, Ask through Claude and the chatbox stay OFF (appendix A).
- REL-AG wording: PR #1128 (docs only, Shalini OK with changes applied), 13th in the queue.
- Git in team sessions: each new team session's safety check refuses `git commit` and `git push` until the owner allows it in that session. Allowed so far: Liyana, Nurul.

**Not yet answered (ask the owner):**
1. BIL-38: the owner must type "lulus 2 perubahan ujian BIL-38" in Farah's session. **Update 25 Nov 12:13 UTC:** BIL-38 is not on origin (Farah searched every branch, commit, PR and TEAM.md); the old session never pushed it. The owner chose "rebuild" by tick box in Farah's session; its scope must come from the owner's description. No BIL-38 test-change approval exists until then.

Rule for the next coordinator: every new owner answer is appended to section 2 of this file and pushed straight away, so nothing is lost if the account changes.

## 3. Merge queue

See Part B for the current queue and merge check.

## 4. Work in progress by team

- **Kavitha (Staff Experience):**
  - Shell #1125, then the homes and My day, then the Stage 2 shared pages and the website draft boards.
  - Follow-ups from Aiman: build the menu once per request, make the branch switcher reachable, and guard `?tab=`.
- **Nurul (Patients & Registration):**
  - Registration and consultation restyle.
  - Treatment room (Syazwan), Family link (Aqilah), TRI-2 (Syazwan, last).
  - AUTO-1c and REL-PR are waiting for ship-its.
- **Amirul (Queue & TV):** Q-16d Rooms today (branch pushed), average wait, TV calls-today stats, and the board and TV restyle.
- **Aisyah (Dispensary):** DSP-27, then B3, B5, B6, B7, B9 and B4, then B8 and B10. Keep main's flow: Verify completes the case, and there is no Ready tab.
- **Farah (Billing):**
  - Billing restyle and the family bill note with Aqilah.
  - BIL-33 and BIL-34 after Kumar reviews them.
- **Zulkifli (Inventory):**
  - IMP-1.
  - The inventory restyle first, then STK-50/51 (approved), STK-46, STK-49, STK-48, STK-47/60 and STK-61. All the notes are written.
- **Syafiq (Panel):** PNL-30, then the Panel restyle. His usage is nearly out.
- **Adlina (Catalogue):** SUG-1b, then the Stage 2 restyle. The supplier multi-contact edit is Stage 2.
- **Liyana (HR):** the CA-facing HR flow has no gaps. Stage 2 restyle. REL-HR docs PR #1118.
- **Irfan (Agents):** the connector-off steps for kpjaya.com, the REL-AG wording PR, and the Stage 2 restyle.
- **Daniel (Design):** spec #1119, then reviews the restyle PRs.

## 5. Team sessions (Claude only; not usable from ChatGPT)

Claude session IDs in the second account (all stopped): Kavitha `session_01QytCz8VB6Ukx2xUipErwq8`, Zulkifli `session_01QytCz75dj13VxUN9aHny9u`, Farah `session_01QytCzALVGzbcu5uMdMWkmF`, Amirul `session_01QytCz8ttcwt2J7qPs2As5U`, Nurul `session_01QytCz9MAfczDK7YK3qiUYc`, Aisyah `session_01QytCz8Xq6buhu8zeNq1ReP`, Daniel `session_01QytCzAKit73o4oRFSfwSTU`, Syafiq `session_01QytCz9aLvtTbGYmEugr1DQ`, Adlina `session_01QytCz7pNEwocUgikAANwbR`, Liyana `session_01QytCz7UfKDMdicdq9iHKtt`, Irfan `session_01QytCz9vNwNfEwyKwSt2Zfu`; coordinator `session_01QytCz78kVkBSywMPTyfWfw`. Each team session needed the owner's own words in that session before it could `git commit`/`git push` (allowed so far: Liyana, Nurul, Zulkifli).

## 6. Still owed in docs

The coordinator's BASELINE docs PR is still owed. It should record:
- the merges after #1000;
- the verbal approvals of 25 Nov ("owner-confirmed, verbal, 25 Nov 2026"; the written copies are in the clinic Google Drive);
- section 6 item 30, now that the label is confirmed;
- the cost-policy banner wording in section 4;
- every decision in section 2 above.

## Appendix A. AI connector OFF on kpjaya.com (Irfan, 25 Nov, from `main` at `23639679`; nothing was run on any server)

When: Sunday 29 Nov evening, after the data clean and before the IMP-1 dry run. Who: the person who manages the kpjaya.com server, with the owner present.

1. In the server's environment settings (never in code), set `AGENTS_CONNECTOR_ENABLED=false`, `AGENTS_ASK_CLAUDE_ENABLED=false`, `AGENTS_CHAT_ENABLED=false` and `AGENTS_PREPARE_ENABLED=false`. Leave `MCP_REDIRECT_DOMAINS`, `AGENTS_ASK_CLAUDE_REQUIRE_SCOPE`, `PASSPORT_*` and `AI_CHAT_API_KEY` as they are.
2. Run `php artisan config:clear`, `php artisan config:cache`, `php artisan agents:connector-revoke` (prints "Revoked N token(s).", one counts-only audit row `ai_connector.tokens_revoked`).
3. Verify: `php artisan config:show agents` shows all four false; `https://kpjaya.com/mcp`, `/.well-known/oauth-protected-resource` and `/.well-known/oauth-authorization-server` answer 404 (a 401 means the config was not reloaded); `php artisan tinker --execute="echo \Laravel\Passport\Token::where('revoked', false)->count();"` prints 0; `/agents/chat` is not found for the Director. Write down who, when, and the outputs.
4. Undo only on the owner's written decision: set `AGENTS_CONNECTOR_ENABLED=true` (others only if named), `config:clear`, `config:cache`; `/mcp` then answers 401 and the Director reconnects KPOne in the Claude app.

The scheduled rule-based agents (`agents:daily-run`, `agents:inventory-review`) call no outside service and are not affected.
