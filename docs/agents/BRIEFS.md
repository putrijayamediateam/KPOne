# Wave 1 builder briefs

One brief per builder. Each builder is a separate Claude session on its own branch. Paste the brief as the
first message. Order: AG-1, AG-2, AG-3, AG-4, AG-7 and DEMO-1 can start once this contract is merged; AG-5 waits
for AG-1 and AG-4; AG-6 waits for AG-1, AG-2 and AG-4. No slice calls an AI service (contract section 7).

## Rules every brief includes

```text
You are building one slice of KPOne. Read CLAUDE.md, AGENTS.md, docs/BASELINE.md and
docs/agents/CONTRACT.md first; the contract wins over your own design choices.
- Work only on your branch and only in the files the contract reserves for your slice.
- Never open, print or edit .env. Use .env.example only. Synthetic .test data only.
- Every state change: backend permission and branch scope, DB::transaction, row lock,
  lock_version, idempotency key where retried, audit row with identifiers only.
- Do not change any existing test assertion. If one must change, stop and ask the owner.
- KPOne must not call any AI service; add no API key setting or AI package.
- Run the full gate before handing off: php artisan test on a task-owned PostgreSQL 18 database
  (verify host, port and database name before the first query), Pint, frontend types, lint and
  format, npm run build, node --test tests/Frontend/*.mjs, migrate:fresh --seed, git diff --check.
- Hand off in plain English: what changed, test results, and screenshots at 1440 and 390 px in
  light and dark for any screen. Do not commit or push until the owner says "ship it".
  Never merge.
- Add one row to docs/BASELINE.md section 1 for your slice.
```

## AG-1 — Agent registry and activity log

Branch `feature/ag-1-agent-registry`. Build the `ai_agents`, `ai_agent_tools`, `ai_agent_runs`,
`ai_agent_run_steps` and `ai_agent_messages` tables (contract section 2), the permission migration (section
4), an `AgentRunRecorder` service that starts a run, appends steps and messages and finishes a run, and a
read-only JSON endpoint `GET /agents/office-feed` (permission `ai_agents.view.organisation`) returning each
agent with its light colour, current task summary, last step, pending proposal count and the last 20
messages. Seed the five agents of section 8 in a local and testing seeder. Registry page `/agents` lists
agents; the Director can enable or disable one (lock_version, audit). Append-only triggers on steps and
messages, with tests that update and delete are refused.

## AG-2 — Approval inbox

Branch `feature/ag-2-approval-inbox`. Build `ai_proposals` (section 2 and 6) and a `ProposalService` with
`propose()` (called by agents, idempotent), `approve()`, `reject()` (reason required) and `withdraw()`.
Decision needs the type's domain permission and the proposal's branch; the proposer agent's requester can
never decide their own run's proposal. Approval records the decision only and changes no stock. Page
`/agents/inbox` (permission `ai_proposals.view.branch`) with Pending, Approved and Rejected tabs and
Approve / Reject buttons. Until AG-1 merges, reference agents and runs by id only; add the foreign keys in
a migration inside your reserved range once AG-1 is on `main`.

## AG-3 — Agent office page

Branch `feature/ag-3-agent-office`. Build `/agents/office`: a 2.5D isometric office in CSS and SVG (no
Three.js or other new dependency), desks grouped by department, a status light above each agent (section
3), a speech bubble for recent messages, a Director desk showing the pending proposal count, and a click
panel with the agent's title, current task, last steps and pending proposals. Every agent carries a visible
"AI agent" label. Develop against a fixed JSON sample matching the office feed shape in
contract section 11; switch to the live endpoint once AG-1 is on `main`. Polls every 10 seconds, pauses
when the tab is hidden, works at 390 px.

## AG-4 — Read-only data sources

Branch `feature/ag-4-data-sources`. Build the `AgentTool` interface and the three data sources of contract
section 5. Every call checks the requester's permission and effective branch and the agent's allowlist,
and returns aggregates only. Tests: permission and branch refusals, an allowlist refusal, a payload scan
that fails if a result contains keys such as `name`, `patient`, `ic`, `nric`, `passport`, `phone`,
`email`, `address`, `diagnosis` or `note`, and the no-AI guard test of contract section 7.

## AG-5 — Director quick answers (after AG-1 and AG-4)

Branch `feature/ag-5-director-quick-answers`. Page `/agents/assistant` (permission
`ai_assistant.use.organisation`) for Dato' Arif: a list of preset questions (for example "Patients seen
today per branch", "Sales this week against last week", "Items below reorder level") with branch and date
filters. Each answer is one `director_assistant` run that reads the data sources and shows a table and a
short sentence built from a fixed template, never generated text. The question list lives in code, in
one place, so new questions are a small PR.

## AG-6 — Inventory analyst (after AG-1, AG-2 and AG-4)

Branch `feature/ag-6-inventory-analyst`. Command `agents:inventory-review {branch}` (and an optional
scheduler entry, off by default) runs `inventory_analyst` over `inventory.stock_review` and creates
`inventory.restock_suggestion` proposals through `ProposalService::propose()`, one per low-stock SKU, with
a deterministic suggested quantity (reorder level minus current quantity, rounded up) and a controlled
reason code, all computed in PHP. Idempotent per branch, SKU and local date.

## DEMO-1 — Synthetic demo setup (parallel)

Branch `feature/demo-1-setup`. A local-only seeder `KPOneDemoSetupSeeder` that, after the presentation
seeder, moves the demo CA, CA Supervisor and Finance Officer to Cheras through `BranchAssignmentService`,
creates `demo.ca_supervisor2@kpone.test` through `StaffProvisioningService`, sets Business details and the
agreed invoice footer, and creates a small fictional catalogue with published prices and verified units.
Opening stock is recorded and then signed off by the second CA Supervisor through the existing services,
so independence rules are exercised, not bypassed. Refuses to run outside `local` and `testing`, and stops
if any user email does not end in `.test`. Document the commands in `docs/DEVELOPMENT.md`.

## AG-7 — Ask KPOne (parallel, independent of AG-1 to AG-6)

Branch `feature/ag-7-ask-kpone`. Build contract section 13: the `ai_read` schema of masked views (one view
per operational area: patients, visits, queue, consultations in structured form, dispensary, inventory,
billing and payments, Panel claims, staff directory), the `ai_reader` connection, the `kpone:read-schema`
and `kpone:read` commands, the audit row, the `.env.example` placeholders and the `ask-kpone` project skill.
Document in `docs/DEVELOPMENT.md` the one-time command the owner runs to create `kpone_ai_reader`.
Tests: every view masks names to initials and IC to the last 4 characters and has no phone, email,
address, clinical free text, encrypted or credential column (a test reads the column list of every view);
`kpone:read` refuses INSERT, UPDATE, DELETE, DDL, multiple statements and any table outside `ai_read`; the
connection is read-only; the row cap and the audit row. A schema test fails if a new migration adds a view
to `ai_read` without updating the masking test.
