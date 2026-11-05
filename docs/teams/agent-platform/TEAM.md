# Agent platform team

Team memory for the agent platform builders. Read this with `CLAUDE.md`, `AGENTS.md`, `docs/BASELINE.md` and
[docs/agents/CONTRACT.md](../../agents/CONTRACT.md) before starting a slice. The contract wins over anything here.
Update this file in every slice's PR.

## Team

| Role | Name | Job |
|---|---|---|
| Lead | *to be named by the owner* | Builds one slice at a time, runs the full gate, hands off in plain English. |
| Reviewer | *to be named by the owner* | Independently reviews each slice's diff against the contract, the KPOne rules and the tests before hand-off. |

The team works for the owner through a coordinator session. Nothing is committed or pushed until the
coordinator says "ship it"; nothing is merged by the team.

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
| Tests | `tests/Feature/Agents/`, `tests/Frontend/ag-*.test.mjs` |
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

## Open items

1. **Team names:** the owner names the Lead and the Reviewer.
2. **How people reach `/agents`:** AG-1 adds no menu or Clinic management link, because the existing tests pin
   the exact header and hub destinations; adding one changes those assertions and needs the owner's approval.
   The page opens by typing `/agents`.
3. **Nadia's title and department:** the contract gives only her name and code; the seeder uses
   "QA Reviewer (AI agent)" in Technology. The owner may rename.
4. **Tool allowlist editing:** `ai_agents.manage.organisation` covers it (contract section 4), but AG-1 builds
   only enable and disable; allowlists are set by the seeder.
5. **Knowledge pack files** (`docs/agents/<code>.md`, contract section 9) are not written yet; no slice owns them.
6. **AG-2 foreign keys:** `ai_proposals` references `ai_agents` and `ai_agent_runs` from AG-1. The office feed
   already counts `pending` proposals once that table exists.

## Backlog (in order)

| Slice | Status |
|---|---|
| AG-1 Agent registry and activity log | Owner approved; PR open (not merged) |
| AG-4 Read-only data sources and the no-AI guard test | Next |
| AG-2 Approval inbox | |
| AG-3 Agent office page | |
| AG-7 Ask KPOne | |
| AG-9 Rizal (stocktake officer) | |
| AG-10 Faris (system health) | |
| AG-6 Hana (inventory analyst) | |
| AG-5 Director quick answers | |
| AG-8 Daily summary page and Lina | |
