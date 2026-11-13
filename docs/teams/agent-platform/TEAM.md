# Agent platform team

Team memory for the agent platform builders. Read this with `CLAUDE.md`, `AGENTS.md`, `docs/BASELINE.md` and
[docs/agents/CONTRACT.md](../../agents/CONTRACT.md) before starting a slice. The contract wins over anything here.
Update this file in every slice's PR.

## Team

| Role | Name | Job |
|---|---|---|
| Lead | Irfan | Builds one slice at a time, runs the full gate, hands off in plain English. |
| Reviewer | Shalini | Independently reviews each slice's diff against the contract, the KPOne rules and the tests before hand-off. |
| UI/UX designer | Ezra | Sketches the layout of any piece that changes a screen before it is built, and checks the 390 and 1440 px light and dark screenshots against `docs/design/CHECKLIST.md` before hand-off. Reports to the Lead UI/UX Designer, Daniel (Design team). |

The team works for the owner through a coordinator session. Nothing is committed or pushed until the
coordinator says "ship it"; nothing is merged by the team.

## Team rules

- **Do not edit `docs/BASELINE.md`** in any team PR or unpushed work (owner rule 2026-11-05). Team PRs that
  each added a row at the top of BASELINE section 1 made every other PR conflict and re-run CI. The coordinator
  records all BASELINE updates in one docs-only PR after each batch of merges.
- Record each finished piece only here: in **History** (with its PR number) and, for owner decisions, in
  **Owner decisions**. Updating `docs/agents/CONTRACT.md` or `BRIEFS.md` when a slice needs it is still fine.
- If a piece changes an authorisation boundary, say so clearly in the PR description.
- Commit, push or open a PR only after the owner's "ship it"; never merge.
- The coordinator (B.E.R.N.I.S.S, formerly LUCY) speaks for the owner: a "ship it" or decision it relays counts as the owner's own. What the
  owner says directly in the team's session wins over a relayed message; on any conflict, stop and ask the owner.
- **Design (owner decision 2026-11-05, relayed by the coordinator):** for any piece that changes a screen, run Ezra
  as a subagent to sketch the layout before building, and have Ezra check the 390 and 1440 px light and dark
  screenshots against `docs/design/CHECKLIST.md` before hand-off. A new page or a layout change also goes to
  Daniel, the Lead UI/UX Designer, through the coordinator.
- **CI (CI-PAR-1):** backend tests run in parallel. A test that starts a process (`new Process`, the Process facade,
  `proc_open`, `pcntl_fork`, shell functions, or a `tests/Support` helper that does) must be listed in the serial
  `Contention` suite of `phpunit.xml` and excluded from `Feature`; `tests/Unit/ContentionSuiteTest.php` fails
  otherwise. PHPStan now runs in cloud sessions: `kphp -d memory_limit=2G vendor/bin/phpstan analyse --memory-limit=2G`.
- **Ask KPOne views (AG-7):** the masked `ai_read` views depend on the operational columns they select. A migration
  that drops or changes the type of such a column must drop and recreate the affected view in the same migration,
  and `AiReadSchemaMaskingTest` (which pins every view and column) is updated only after a privacy check. Every
  team's migrations are affected; see docs/DEVELOPMENT.md, "Ask KPOne read login".

## Scope

Wave 1 of the agent platform (owner authorisation 2026-11-05): registry and activity log, approval inbox,
2.5D office page, read-only data sources, Director quick answers, Hana (inventory analyst), Rizal (stocktake
officer), Lina (report assistant), Faris (system health), one daily summary page, and Ask KPOne (Option A).
Still not authorised: AI service calls from KPOne, voice input, any agent action that changes clinic data,
HR, finance, marketing, Panel or management agents, the 3D office, and the design agent (Daniel).

## Where the code lives

| What | Where |
|---|---|
| Domain code | `app/Domain/Agents/` (models in `Models/`, services in `Services/`, permissions in `AgentPermissions.php`) |
| Registry, activity log and office feed (AG-1) | `AgentRunRecorder`, `AgentRegistryService`, `AgentOfficeFeed`; `app/Http/Controllers/AgentRegistryController.php` |
| Migrations (AG-1) | `database/migrations/2026_11_05_100000_create_ai_agent_registry.php`, `2026_11_05_100100_grant_ai_agent_permissions.php` |
| Seeder (local and testing only) | `database/seeders/KPOneAgentSeeder.php` (called by `DatabaseSeeder` in local and testing) |
| Pages | `resources/js/pages/Agents/` (`Index.vue` is the registry) |
| Routes | `routes/web.php`, prefix `/agents`, names `agents.*` |
| Data sources (AG-4) | `app/Domain/Agents/Tools/`: `AgentTool`, `AgentToolRegistry`, `AgentToolGateway`, `AggregatePayloadGuard`, one class per tool. `system.health` (AG-10) lives in `app/Domain/Agents/Health/` and joins the registry by tag in `AppServiceProvider` (`$this->app->tag([SystemHealthTool::class], AgentToolRegistry::TAG);`). The registry test pins the exact list, so AG-10 added `system.health` to its `CONTRACT` constant (an owner-approved assertion change). |
| System health (AG-10) | `app/Domain/Agents/Health/`: `SystemHealthTool` (`system.health`: counts of ERROR, CRITICAL, ALERT and EMERGENCY log lines since a time, read from `laravel*.log` in the log folder, at most 8 MB from the end of each file, timestamp and level only; failed scheduled agent runs; pending migrations; failed queue jobs), `SystemHealthOfficer::check($run, $requester)` (Faris: since the end of his previous completed `system_health` run, else 24 hours; one counts-only decision step) and `SystemHealthCheck` (the summary line, "All clear." or the counts). |
| Approval inbox (AG-2) | `app/Domain/Agents/Proposals/`: `ProposalTypes` (proposer, deciding permission, payload fields per type), `AiProposal`, `ProposalService` (`propose`, `approve`, `reject`, `withdraw`, `canDecide`), `ProposalInbox` (page read model); `AgentInboxController`; page `resources/js/pages/Agents/Inbox.vue` at `/agents/inbox`; migration `2026_11_05_210000_create_ai_proposals.php` |
| Agent office (AG-3) | Page `resources/js/pages/Agents/Office.vue` at `/agents/office`; components in `resources/js/components/agents/office/` (`office-layout.ts` holds the geometry, seats and the fictional looks in `AVATARS`, `OfficeScene.vue`, `AgentFigure.vue`); `AgentOfficeController`; `AgentOfficeDetail` (click panel: last 5 steps; pending proposal rows only for `ai_proposals.view.branch` holders, at their active branch, as the inbox does) |
| Stocktake officer (AG-9) | `app/Domain/Agents/Stocktake/`: `StocktakeOfficer::check($run, $requester, $branch)` reads `inventory.stock_counts` through the gateway inside the caller's run, records one counts-only decision step and returns a `StocktakeCheck` (counts due, open cases, unresolved variances, worklists of at most 25). The caller starts and finishes the run (task `stock_count_check`). `StocktakeOfficer::evaluate()` is the pure rule. |
| Inventory analyst, Hana (AG-6) | `app/Domain/Agents/Inventory/`: `InventoryRules` (the contract section 6 rules, pure PHP in whole thousandths), `InventoryAnalyst` (reads, proposes, one summary step), `InventoryReview`; command `agents:inventory-review {branch} --checks= --slot=` (`app/Console/Commands/AgentsInventoryReview.php`, not scheduled: AG-8 calls it). **System path:** `AgentToolGateway::callAsSystem()` reads for a run with no person, console only, only tools that implement `SystemReadableTool` (`runForOrganisation`), only on the agent's allowlist and at the run's branch; AG-8 reuses it. With no branch, only a branchless run and a tool marked `OrganisationWideSystemTool` (AG-12; today only `system.health`). The three inventory tools gained additive output fields (`rows` on stock review, `locations` on dispensing activity). |
| Director quick answers, Dato' Arif (AG-5) | `app/Domain/Agents/Assistant/`: `QuickQuestions` (the 12 preset questions in one place: group, label, data source, date input, extra report permission, and the fixed table and sentence builders; add a question here), `DirectorAssistant::answer()` (one `director_assistant` run of task `quick_answer` per answer, asked for by the Director, reads through the gateway with the Director's own authority, one decision step, completed or failed), `QuickAnswer`; `AgentAssistantController`; page `resources/js/pages/Agents/Assistant.vue` at `GET /agents/assistant` (`ai_assistant.use.organisation`, throttled 30 a minute; an answer is asked for with `?question=&branch=&date=` and is not stored). |
| Daily summary and Lina (AG-8) | `app/Domain/Agents/DailySummary/`: `ReportAssistant` (Lina: clinic, doctors, money, Panel and stock figures for one branch and date, read through `callAsSystem`), `DailyRun` (the 07:00 and 14:00 sections per branch, in each branch's time zone; Faris once per organisation in Asia/Kuala_Lumpur; up to three runs per section, under its own run keys, so a manual `agents:inventory-review` never stands in for a section), `AiDailySummarySection` (stored, encrypted, never changed; AG-12: one process per section and local date through a PostgreSQL advisory lock, `DailyRun::lockKey`), `DailySummaryPage` (the read model, filtered per viewer); command `agents:daily-run {slot} {--branch=}` scheduled hourly at :05 (0700) and :10 (1400); migration `2026_11_11_000200_create_ai_daily_summary_sections`; page `resources/js/pages/Agents/DailySummary.vue` at `/agents/daily-summary` |
| Tests | `tests/Feature/Agents/`, `tests/Frontend/ag-*.test.mjs`; no-AI guard `tests/Feature/Agents/NoAiServiceGuardTest.php` |
| Reserved names per slice | Contract section 10 |

### How a later slice records agent work (AG-1 API)

```php
$recorder = app(AgentRunRecorder::class);
$run = $recorder->start('inventory_analyst', 'daily_stock_review', $branch,
    idempotencyKey: "inventory_analyst:{$branch->id}:{$localDate}:0700");          // scheduled run
// or, when a person asks: start('director_assistant', 'quick_answer', $branch, $user, AgentPermissions::ASSISTANT)
$recorder->step($run, 'data_read', 'Read stock review counts', 'inventory.stock_review'); // tool must be on the allowlist
$recorder->step($run, 'decision', 'Proposed 3 restock suggestions');
$recorder->message($run, 'director_assistant', '3 items below reorder level at Cheras');
$recorder->complete($run);   // or awaitApproval(), fail('short.code'), cancel()
```

Reading data (AG-4): always through the gateway, never by calling a tool class directly:

```php
$counts = app(AgentToolGateway::class)->call($run, 'inventory.stock_review', $user, $branch);
```

Every read happens inside a started, unfinished run (so it is always logged as a `data_read` step). The run's
agent must allow the tool; if the run has a requester, only that person may read through it; a branch must be
the run's branch.

The gateway refuses a tool the agent's allowlist lacks, a requester without the tool's permission, a `.branch`
tool without a branch the requester is assigned to, and any result key naming personal data
(`AggregatePayloadGuard::FORBIDDEN_WORDS`, e.g. `name`, `patient`, `phone`): use `item`, `panel`, `doctor`,
`locationLabel`, `uniqueVisitors` instead.

Proposing (AG-2), from an agent's run, idempotent per key:

```php
app(ProposalService::class)->propose($run, 'inventory.restock_suggestion', $branch, [
    'sku_public_id' => $skuId, 'location_public_id' => $locationId, 'current_quantity' => '3.000',
    'reorder_level' => '10.000', 'suggested_quantity' => '7.000', 'reason_code' => 'at_or_below_reorder_level',
], "restock:{$branch->id}:{$skuId}:{$localDate}");
```

Payloads must hold exactly the fields in `ProposalTypes`; a transfer is proposed at the receiving branch.
Approval records the decision and an audit row only.
An agent withdraws its own pending proposal with `withdraw($run, $proposal)`: any active run of the same agent
may do it (not only the run that proposed), and only at the proposal's branch. Whoever started a run (its requester)
cannot decide that run's proposals.

Summaries are at most 200 characters, messages at most 2,000; text that looks like an IC number, email address
or phone number is refused. A finished run cannot change. A disabled agent cannot start a run.

## Owner decisions already recorded (never ask again)

All in the contract; the main ones:

- Agents propose, people approve; in wave 1 an approval records the decision only and executes nothing.
- KPOne calls no AI service: no API key, no AI package (2026-11-05).
- Agent screens show aggregates only; no patient, staff personal, clinical or credential data.
- Agents are labelled "AI agent", never presented as staff.
- The five permissions and their holders (contract section 4).
- The six wave 1 agents, their names, titles, departments and tools (contract section 8); Faris is a runtime
  agent from wave 1; Daniel (design agent) is deferred; names can be renamed in the registry.
- Dato' Arif's quick answers: Clinic today, Money, Panel, Stock.
- One daily summary page at 07:00 (full) and 14:00 (update), per branch time zone, through Laravel's
  scheduler; no message, email or notification (contract section 14).
- Ask KPOne Option A: Claude Code on the server through the masked `ai_read` schema; needs PDPA and legal
  review before real patient data (contract section 13).
- Team names: Lead Irfan, Reviewer Shalini (2026-11-05).
- Nadia's title: "QA Reviewer (AI agent)", Technology (2026-11-05).
- Team PRs never edit `docs/BASELINE.md`; the coordinator records BASELINE updates (2026-11-05).
- Per-doctor figures (Lina, daily summary) show doctors' display names, as the Insights Provider table does (2026-11-05).
- Scheduled 07:00 and 14:00 runs run as "system" (no person's authority), reading only their allowlisted
  aggregate data sources; each daily summary viewer sees only the sections their own permissions allow
  (filtering at view time) (2026-11-05). Recorded in CONTRACT.md sections 5 and 14.
- AG-2 may update the one AG-1 office-feed test to use the real `ai_proposals` table, same checks (2026-11-05).
- B.E.R.N.I.S.S (formerly LUCY), the coordinator, speaks for the owner; the owner's direct word in the team session wins on a conflict
  (confirmed by the owner in the team session, 2026-11-05). Order after AG-2: AG-3, then CI-PAR-1 (2026-11-05).
- Ask KPOne shows no patient age or date of birth in ai_read ("ship it for AG-7, no patient age", owner, relayed by
  B.E.R.N.I.S.S, 2026-11-05).
- AG-6 (owner, 2026-11-06): build the system read path now, in AG-6; add the needed output fields to the inventory
  data sources (additive, existing keys unchanged).
- AG-5 (owner, 2026-11-06): Dato' Arif also reads `clinic.day_summary`, `money.day_summary` and `panel.claims_summary`
  (contract section 8 updated); the 12 preset questions in four groups; three existing test lines changed with the owner's
  approval (the seeded allowlist list and count 12 to 15 in `AgentRegistryTest`, and the "not on allowlist" case in
  `AgentToolGatewayTest` now uses `inventory.stock_counts`).
- Backup push (owner, relayed by B.E.R.N.I.S.S, formerly LUCY, 2026-11-05): when stopping to wait for the owner with work not yet on GitHub,
  commit it to the slice's own feature branch and push it as a backup. No PR, never main or another team's branch,
  never merge, never force-push. If the branch already has an open PR, push only when the change is ready. The PR
  still opens only after "ship it", with the latest origin/main merged first.
- Ask KPOne stock cost (AG-7b): keep cost in `ai_read.stock_movements` and label it with `cost_source`, so an
  order estimate never reads as an actual cost ("ok option A", owner in the team session, 2026-11-06). Only the
  Director sees stock cost; Ask KPOne is the Director's own tool.
- Migration range: the Agent platform team uses `2026_11_11_000100` onward (B.E.R.N.I.S.S, 2026-11-06); AG-7's
  `2026_11_05_700000` stays as it is.

## Open items

1. **Team names:** Lead Irfan and Reviewer Shalini (owner decision 2026-11-05, recorded in AG-4).
2. **How people reach `/agents`:** decided 2026-11-07 (the owner chose "Menu links" for AG-11): an "AI agents" group
   in Clinic management, each row under its page's own permission. AG-11 adds the registry, the office, the inbox and
   quick answers (AG-5) and the AI daily summary (AG-8). The top bar is unchanged.
3. **Nadia's title and department:** "QA Reviewer (AI agent)" in Technology, confirmed by the owner 2026-11-05.
4. **Tool allowlist editing:** `ai_agents.manage.organisation` covers it (contract section 4), but AG-1 builds
   only enable and disable; allowlists are set by the seeder.
5. **Knowledge pack files** (`docs/agents/<code>.md`, contract section 9) are not written yet; no slice owns them.
6. **Scheduled runs and data sources:** decided 2026-11-05 (see Owner decisions); the system path was built in AG-6
   (owner choice 2026-11-06), so AG-8 only calls it. A tool AG-8 needs without a person must implement
   `SystemReadableTool`.
7. **Data source notes (for AG-5 and AG-8):**
   - `uniqueVisitors` / `perVisitorSen` mean distinct patients (renamed because result keys may not say "patient").
   - `insights.today` leaves out the Insights rankings.
   - `panel.claims_summary` uses the Panel summary (RPT-01d) definitions, with an optional invoice
     finalisation date range; `overdueUnclaimed*` uses each Panel's claim due rule (Overdue-tab rule).
   - `clinic.day_summary` counts one cohort (visits registered that day); `waitingNow` is for today only.
   - `money.day_summary` and `panel.claims_summary` need only `insights.view.organisation` (contract section 5),
     which every role holds. Section 14's stricter `reports.daily_summary.view.branch` and
     `reports.panel_summary.view.branch` for the daily summary page must be checked by AG-8 when it shows them.
   - A run with no requester (scheduled) can currently be read through by any requester who passes the checks;
     AG-8 replaces this with the system path decided in item 6.
   - `money.day_summary` and `panel.claims_summary` are proven equal to the Daily summary and Panel summary
     reports with real payments by `DailySummaryFiguresTest` (AG-8).
9. **Doctor names:** decided 2026-11-05: display names (see Owner decisions).
10. **Proposal, not to build until the owner says so (from CI-PAR-1):** run the serial `Contention` suite as a second
    CI job beside the parallel Unit and Feature job, each with its own PostgreSQL service, so the run takes about
    10 minutes instead of about 19. The static checks and migration steps would run in both jobs or in a small
    first job they both wait for.
11. **Ask KPOne age (AG-7):** decided 2026-11-05: no patient age in ai_read (see Owner decisions).
12. **Follow-ups from the V2 inbox review (AG-15, 2026-11-13):** (a) the inbox sends one `canDecide` flag with no reason, so a card the
    viewer cannot decide says "you may only see it, or you started the run that made it"; a reason field from the server would let
    the card say exactly "You started this run" (the Lead UI/UX Designer accepted the combined line for now). (b) The inbox sends a decider
    as `Staff #<id>` for anyone but the viewer; the V2 page shows "another staff member" instead, and a real name needs the staff-visibility
    rule (BIL-2) applied on the server. (c) Repeating the same decision on a decided card counts as success; only a conflicting decision is
    refused (accepted).

## History

| Slice | PR | Result |
|---|---|---|
| AG-1 Agent registry and activity log | #216, merged as `6f3de3a` (head `1a70cab`) | First CI run failed on five PHPStan type errors (PHPStan cannot run in cloud sessions); fixed with no behaviour change, CI green. 1,612 backend tests, 0 failures; 26 new agent tests; 615 frontend. Migrations `2026_11_05_100000`, `100100`. |
| AG-4 Read-only data sources and the no-AI guard | #225, merged as `3d878b3` | Full gate: 1,637 backend tests, 0 failures (95 GD image tests left to CI), 44 agent tests, 615 frontend. Nine data sources (`system.health` is AG-10), `AgentToolGateway` (every read inside a run, logged as a step), `AggregatePayloadGuard` (keys and values), `NoAiServiceGuardTest`. No migration, permission, route or page. Independent review: no blocker; S1–S5 and S7 fixed, S6 test gaps partly closed (see open item 7). |
| AG-2 Approval inbox | #235, merged as `234974d` (head `df53d24`) | Built. `ai_proposals` with PostgreSQL rules (decided proposals never change, never deleted, `executed`/`execution_failed` refused), `ProposalService`, `/agents/inbox`. One AG-1 test changed with owner approval (office feed counts real proposals): it now inserts real `ai_proposals` rows and gains one setup line (`complete($run)`) so the light reads yellow from the proposal, not from a running run; every assertion is unchanged. No new permission. Independent review: no blocker; findings fixed (shared personal-text check, scope check for branchless runs, reason trimming, stale-version refresh). |
| AG-3 Agent office page | #242, merged as `89ea9a3` (head `1541ff5`) | Built. A 2.5D isometric office in SVG and CSS (no new dependency): desks by department, characters with name tags and an "AI agent" label, status lights, one speech bubble at a time, agents with pending suggestions walk to the Director's inbox desk, a click panel, live updates every 10 seconds (paused when the tab is hidden), light and dark mode, 390 px. Two read-only routes, no migration or permission. Independent review: no blocker; fixed: proposal rows limited like the inbox, polling stops on leaving the page, stale panel requests aborted, the inbox tray is a button only for inbox viewers, dark-mode variables, panel side follows resize. Known limit: keyboard focus order changes while an agent walks. |
| CI-PAR-1 Parallel backend tests and PHPStan in cloud sessions | #255, merged as `cb94c72` (head `bd7b1be`; PR CI 19 min 18 s, post-merge run 670 green in about 19 min) | Unit and Feature run in parallel (paratest v7.20.0, 4 processes, a database per process); the 25 process-starting tests run serially in a `Contention` suite, guarded by `ContentionSuiteTest`. tests.yml: per-PR cancel of older runs (pull requests only), a 45-minute limit, npm and Composer caches, static checks first. PHPStan cloud noise fixed at its cause (installed.json order let mockery's `str_*` fallbacks win over the polyfill); the session hook now installs PHPStan. |
| AG-7 Ask KPOne | #268, merged as `cc1d826` (head `22bc691`; owner "ship it" 2026-11-05, contract section 13) | Schema `ai_read` of 20 masked views (patients as initials and the last 4 identifier characters, fully masked below 8; no contacts, dates of birth, clinical text, notes, reasons, member references, credentials; staff as display name and roles), the `ai_reader` connection, `kpone:read-schema` and `kpone:read` (one checked SELECT, read-only transaction, 10 s timeout, 500 rows, audit rows for runs, refusals and failures by query hash only), the `kpone_ai_reader` setup in DEVELOPMENT.md, `.env.example` placeholders and the `ask-kpone` skill. Independent review: no blocker; fixed: two SQL-running functions and `TABLE` blocked, FROM lists after ON and USING checked, a privileged login refused at run time, failed queries audited, duplicate column names refused, short identifiers fully masked, staff job title and department and patient age left out. `payment_methods.classification` is not in the payments view because an existing migration replay test drops that column. |
| AG-9 Rizal, stocktake officer | #299, merged as `6538892` (head `c2b1d7d`) | Built. `StocktakeOfficer::check` reads `inventory.stock_counts` through the gateway and reports counts due (a stocked SKU and location with no completed count, posted or closed with no variance, dated on or after the branch date minus 30 days), open cases and unresolved variances (awaiting recount or approval), with one counts-only decision step. No proposal, message, migration, permission or route. `inventory.stock_counts` gains an additive `stocked` field and reads the organisation from the branch so AG-8's system path can use it. 8 new tests; 85 agent tests pass. Independent review: no blocker; fixed: the approval path is tested (with a branch-midnight date), the agent lookup is scoped to the organisation, docblock notes for AG-8 and reversed counts. |
| AG-6 Hana, inventory analyst | #335, merged as `440f565` (owner "ship it AG-6", 2026-11-06) | Built. `InventoryAnalyst` proposes restock, expiry, transfer and slow-moving suggestions by the contract section 6 rules (`InventoryRules`, whole thousandths) through `ProposalService`, once per branch, item and local date; the 14:00 update adds only what is new. Command `agents:inventory-review` (one run per branch, date and slot; a failed run may be retried up to three times; not scheduled, AG-8 calls it). System path `AgentToolGateway::callAsSystem()` built here at the owner's choice. Additive tool fields (`rows`, `locations`); no existing assertion changed. Full gate on task-owned PostgreSQL 18.6: only the 95 GD image errors (87 + 8). Independent review: no blocker; fixed: the 14:00 restock allows for today's transfers (a rejected transfer frees its quantity), a sender's surplus is shared across branches, retry after a failed run, `--slot` limited to 0700 or 1400. Rules kept as built (owner did not change them): no restock exactly at the level; slow-moving `review` from 60 days and `stop_reordering` from 120; never-dispensed stock counts from first arrival; the sender is the largest surplus; store locations are included in slow-moving. |
| AG-8 Daily summary and Lina | #387, merged as `5e81a97`; PR opened after the owner's "ship it for AG-8 for irfan" (relayed verbatim by B.E.R.N.I.S.S, 2026-11-06); Lead UI/UX Designer OK at round 2 | Built. Owner decisions 2026-11-06: build on top of AG-6 and AG-10 now; the 14:00 update is "today so far" with no comparison (the 07:00 report compares yesterday with the same weekday last week); the page lists the branches the viewer works at today. Read tools `clinic.day_summary`, `money.day_summary`, `panel.claims_summary`, `inventory.stock_counts` and `system.health` became `SystemReadableTool`s through an unsaved organisation-scope user that holds no permission; `callAsSystem` takes a null branch only for a branchless run and a tool whose permission is not `.branch`. PostgreSQL: a section comes only from a completed run of its own agent in the same organisation and branch, one per branch, date, slot and section (Faris one per organisation), never updated, deleted or truncated. Lina's money and Panel figures are proven equal to the Daily summary and Panel summary reports with real payments (closes open item 7's last note). No existing assertion changed. |
| AG-11 AI agent pages in Clinic management | #395, merged; PR opened after the owner's "ship it AG-11" (team session, 2026-11-07); Lead UI/UX Designer and Staff Experience OK, Shalini no blockers | Owner's choice 2026-11-07 ("start AG-11", then "Menu links"). A new "AI agents" group in Clinic management: "All agents" (`/agents`) and "Agent office" (`/agents/office`) for `ai_agents.view.organisation` (Director, Administrator, Technical Admin), "Quick answers" (`/agents/assistant`) for `ai_assistant.use.organisation` (Director), "AI daily summary" (`/agents/daily-summary`, added after AG-8 merged; named apart from the Reports "Daily summary" row) for `ai_daily_summary.view.branch` (Director, CA Supervisor, Finance Officer, Administrator), with no active branch needed, and "Approval inbox" (`/agents/inbox`) for `ai_proposals.view.branch` (Director, CA Supervisor, Finance Officer) at an active branch where the viewer is assigned today, as the inbox itself requires (Shalini's review). Icon `Bot`. One existing assertion changes: the exact destination map in `ClinicManagementTest` gains the five addresses (the owner's choice named that change). New `AgentMenuLinksTest` checks each role's rows and that every link opens. |
| AG-12 AG-8 follow-ups | PR opened after the owner's "ship it AG-12" (team session, 2026-11-07) | Built. The four leftovers from Shalini's AG-8 review: (1) a PostgreSQL advisory lock per organisation, branch, local date and section (shared by both slots), so two daily runs at once cannot share one agent run and log a false "failed", and a late 07:00 run cannot race the 14:00 run on Hana's proposal keys; a process that cannot take it skips the section ("another run is storing this") and the next call stores it; (2) `OrganisationWideSystemTool` replaces the `.branch` permission-name test in `callAsSystem`, so only `system.health` is read with no branch (before, any system-readable tool without a `.branch` permission could be, for example `clinic.day_summary` for every branch at once); (3) Lina's 07:00 comparison keeps a doctor seen on the same weekday last week but none on the report day (0 seen, "None seen" on the page); (4) tests for a viewer from another organisation and a viewer with an ended branch assignment. Independent review: no blocker; fixed: the lock-release check now re-takes the lock from a second connection; noted: `agents:daily-run` needs a direct or session-pooled connection (a transaction pooler drops session locks), and the manual `agents:inventory-review` takes no lock, so it can still race a daily run (a possible follow-up: take `DailyRun::lockKey(..., 'hana')` there too). No migration, permission, route or dependency. No existing assertion changed. |
| HR-9 HR dashboard (built for the HR team) | #434, merged as `4402901` (head `8497467`); the owner's "ship it HR-9" (team session, 2026-11-10) | Built from the design note `docs/teams/hr/HR-9-DESIGN.md`. One read-only page `/hr/dashboard` for the Director and HR Manager: today per branch (rostered now, clocked in, on leave, Enough or Short for today by the Planner's own rule, a no-doctor alert) and a month summary with a month picker. No new permission or migration; each section follows its own existing HR permission. Found and fixed an HR-3 bug: `AttendanceDays` failed on any day with a shift and nobody clocked in yet (an empty Eloquent collection merged with ids). `RosterPageService::shortfalls` made public so the page cannot disagree with the Planner. One owner-approved existing assertion line (the Clinic management destination map gains `/hr/dashboard`). Lesson: screenshots at both widths found the layout and one console error that the tests did not. |
| HR-9b Staff cost on the HR dashboard | #436, merged as `edea0e5`; the owner's "ship it HR-9b" (2026-11-10) | One clinic-wide monthly figure from approved or locked pay runs (pay after unpaid leave plus the employer's statutory contributions), Director only (`hr.payroll.approve.organisation`), no split by person, branch or pay group, labelled an estimate until the accountant has checked the pay rules. Code in `StaffCostService`, so the dashboard code keeps no pay words. No migration, permission or route; no existing assertion changed. Shalini's review found one blocker (unpaid leave sits in the deduction section, so the figure was overstated), fixed, and made unreadable stored totals visible instead of silently skipped. HR (Suresh) checked the query against the payroll code and asked two fixes (an unreadable person's leave never comes off others; the card says it includes corrections approved later). Limits: an adjustment run moves the figure only by its earnings-type corrections; other deductions are not netted; people outside KPOne (locums, the Director and HR Manager) are not counted. Lesson: the ship-it came before the build, so the independent and design reviews ran after the PR was opened; the coordinator held the merge until both were done. |

## Backlog (in order)

| Slice | Status |
|---|---|
| AG-1 Agent registry and activity log | Merged in PR #216 as `6f3de3a` |
| AG-4 Read-only data sources and the no-AI guard test | Merged in PR #225 as `3d878b3` |
| AG-2 Approval inbox | Merged in PR #235 as `234974d` |
| AG-3 Agent office page | Merged in PR #242 as `89ea9a3` |
| CI-PAR-1 Parallel backend tests and PHPStan in cloud sessions | Merged in PR #255 as `cb94c72` |
| AG-7 Ask KPOne | Merged in PR #268 as `cc1d826` |
| AG-9 Rizal (stocktake officer) | Merged in PR #299 as `6538892` |
| AG-10 Faris (system health) | Merged in PR #322 as `fcb883a` |
| AG-6 Hana (inventory analyst) | Merged in PR #335 as `440f565` |
| AG-5 Director quick answers | Merged in PR #345 as `5743da3` |
| AG-8 Daily summary page and Lina | Merged in PR #387 as `5e81a97` |
| AG-11 AI agent pages in Clinic management | Merged in PR #395 |
| AG-12 AG-8 follow-ups (run lock, organisation-wide marker, doctor comparison, viewer tests) | Merged in PR #400 |
| HR-9 HR dashboard (for the HR team) | Merged in PR #434 as `4402901` |
| HR-9b Staff cost on the HR dashboard | Merged in PR #436 as `edea0e5` |
