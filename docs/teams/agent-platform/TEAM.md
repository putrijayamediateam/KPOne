# Agent platform team

Team memory for the agent platform builders. Read this with `CLAUDE.md`, `AGENTS.md`, `docs/BASELINE.md` and
[docs/agents/CONTRACT.md](../../agents/CONTRACT.md) before starting a slice. The contract wins over anything here.
Update this file in every slice's PR.

## Team

| Role | Name | Job |
|---|---|---|
| Lead | Irfan | Builds one slice at a time, runs the full gate, hands off in plain English. |
| Reviewer | Shalini | Independently reviews each slice's diff against the contract, the KPOne rules and the tests before hand-off. |

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
| Data sources (AG-4) | `app/Domain/Agents/Tools/`: `AgentTool`, `AgentToolRegistry`, `AgentToolGateway`, `AggregatePayloadGuard`, one class per tool. **AG-10 adds `system.health` without editing this folder:** put the class in `app/Domain/Agents/Health/` and tag it in a service provider, `$this->app->tag([SystemHealthTool::class], AgentToolRegistry::TAG);`. The registry test accepts any subset of the ten contract codes that includes AG-4's nine. |
| Approval inbox (AG-2) | `app/Domain/Agents/Proposals/`: `ProposalTypes` (proposer, deciding permission, payload fields per type), `AiProposal`, `ProposalService` (`propose`, `approve`, `reject`, `withdraw`, `canDecide`), `ProposalInbox` (page read model); `AgentInboxController`; page `resources/js/pages/Agents/Inbox.vue` at `/agents/inbox`; migration `2026_11_05_200000_create_ai_proposals.php` |
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

## Open items

1. **Team names:** Lead Irfan and Reviewer Shalini (owner decision 2026-11-05, recorded in AG-4).
2. **How people reach `/agents`:** AG-1 adds no menu or Clinic management link, because the existing tests pin
   the exact header and hub destinations; adding one changes those assertions and needs the owner's approval.
   The page opens by typing `/agents`.
3. **Nadia's title and department:** "QA Reviewer (AI agent)" in Technology, confirmed by the owner 2026-11-05.
4. **Tool allowlist editing:** `ai_agents.manage.organisation` covers it (contract section 4), but AG-1 builds
   only enable and disable; allowlists are set by the seeder.
5. **Knowledge pack files** (`docs/agents/<code>.md`, contract section 9) are not written yet; no slice owns them.
6. **Scheduled runs and data sources:** decided 2026-11-05 (see Owner decisions); AG-8 builds the system path.
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
   - Not yet proven by a test with real money: `money.day_summary` and `panel.claims_summary` are tested at zero
     only; AG-8's brief requires equality tests against the Daily summary and Panel summary reports.
9. **Doctor names:** decided 2026-11-05: display names (see Owner decisions).

## History

| Slice | PR | Result |
|---|---|---|
| AG-1 Agent registry and activity log | #216, merged as `6f3de3a` (head `1a70cab`) | First CI run failed on five PHPStan type errors (PHPStan cannot run in cloud sessions); fixed with no behaviour change, CI green. 1,612 backend tests, 0 failures; 26 new agent tests; 615 frontend. Migrations `2026_11_05_100000`, `100100`. |
| AG-4 Read-only data sources and the no-AI guard | #225, merged as `3d878b3` | Full gate: 1,637 backend tests, 0 failures (95 GD image tests left to CI), 44 agent tests, 615 frontend. Nine data sources (`system.health` is AG-10), `AgentToolGateway` (every read inside a run, logged as a step), `AggregatePayloadGuard` (keys and values), `NoAiServiceGuardTest`. No migration, permission, route or page. Independent review: no blocker; S1–S5 and S7 fixed, S6 test gaps partly closed (see open item 7). |
| AG-2 Approval inbox | this PR (owner approved 2026-11-05) | Built. `ai_proposals` with PostgreSQL rules (decided proposals never change, never deleted, `executed`/`execution_failed` refused), `ProposalService`, `/agents/inbox`. One AG-1 test changed with owner approval (office feed counts real proposals): it now inserts real `ai_proposals` rows and gains one setup line (`complete($run)`) so the light reads yellow from the proposal, not from a running run; every assertion is unchanged. No new permission. Independent review: no blocker; findings fixed (shared personal-text check, scope check for branchless runs, reason trimming, stale-version refresh). |

## Backlog (in order)

| Slice | Status |
|---|---|
| AG-1 Agent registry and activity log | Merged in PR #216 as `6f3de3a` |
| AG-4 Read-only data sources and the no-AI guard test | Merged in PR #225 as `3d878b3` |
| AG-2 Approval inbox | Owner approved; PR open (not merged) |
| AG-3 Agent office page | Next |
| AG-7 Ask KPOne | |
| AG-9 Rizal (stocktake officer) | |
| AG-10 Faris (system health) | |
| AG-6 Hana (inventory analyst) | |
| AG-5 Director quick answers | |
| AG-8 Daily summary page and Lina | |
