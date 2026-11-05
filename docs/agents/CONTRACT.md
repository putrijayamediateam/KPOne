# Agent platform — wave 1 contract

Status: **owner-authorised 2026-11-05.** Owner decision the same day: **no AI service is called from
KPOne** (no Claude API key; the existing Claude plan is used only for building). Wave 1 agents are
rule-based and work only from database queries. Planning
source: the owner's agentic handover of 2026-11-04. This file is the single contract every wave 1 builder
follows. Change it only in its own PR, before the slices that depend on the change.

## 1. Principles (non-negotiable)

1. **Agents propose. People approve. The system executes.** No agent changes clinic data. In wave 1 an
   approved proposal records the decision only; executing it through a domain service is a later,
   separately authorised step per proposal type.
2. **An agent never sees more than the person who asked.** Every tool call is checked against the
   requesting user's permissions and effective branch, and against the agent's own tool allowlist.
3. **No AI service calls in wave 1.** KPOne sends nothing to Anthropic or any other AI provider. Agents
   are deterministic PHP rules over the data sources in section 5; every figure shown comes from a query.
4. **Aggregate data only on agent screens.** Data sources return totals, counts and catalogue item names
   (medicine or SKU). Never patient names, KP numbers, IC or passport numbers, phone numbers, addresses,
   clinical notes, diagnoses, staff personal data or credentials.
5. **Everything is logged.** Who asked, which agent, which data sources, who decided. Audit rows carry
   identifiers and counts, never secrets.
6. **Clinical decisions stay with doctors.** No wave 1 agent touches diagnosis, prescribing or documents.
7. **Agents are labelled as AI**, never presented as human staff.

## 2. Tables

All tables carry `organisation_id`. Mutations follow the KPOne rule: backend permission and branch scope,
`DB::transaction`, row lock, optimistic `lock_version`, idempotency key where retried, and an audit row.

| Table | Columns (besides `id`, timestamps) | Notes |
|---|---|---|
| `ai_agents` | `organisation_id`, `code` (unique per organisation, e.g. `director_assistant`), `display_name`, `job_title`, `department_id` (nullable FK `departments`), `kind` (`build` / `runtime`), `avatar_key` (nullable), `is_enabled`, `lock_version` | Registry. Never deleted; disabled instead. |
| `ai_agent_tools` | `ai_agent_id`, `tool_code` | Allowlist. Unique (`ai_agent_id`, `tool_code`). |
| `ai_agent_runs` | `organisation_id`, `branch_id` (nullable), `ai_agent_id`, `requested_by_user_id` (nullable for scheduled runs), `task_type`, `status`, `started_at`, `finished_at`, `last_step_summary` (max 200, no patient data), `error_code` (nullable), `idempotency_key` (nullable, unique per organisation) | One row per task. |
| `ai_agent_run_steps` | `ai_agent_run_id`, `sequence`, `kind` (`data_read` / `decision` / `note`), `tool_code` (nullable), `summary` (max 200), `created_at` | Append-only (PostgreSQL trigger refuses update and delete). |
| `ai_agent_messages` | `ai_agent_run_id`, `from_agent_id`, `to_agent_id`, `body` (encrypted, max 2,000), `created_at` | Append-only. Feeds the office speech bubbles. |
| `ai_proposals` | `organisation_id`, `branch_id`, `ai_agent_run_id`, `ai_agent_id`, `type`, `payload` (encrypted JSON), `payload_hash`, `status`, `decided_by_user_id`, `decided_at`, `decision_reason` (max 500), `lock_version`, `idempotency_key` (unique per organisation) | The approval inbox. |

Quick answers for the Director are **not stored**: each answer is one run; the run and its steps are kept,
the figures are recomputed on demand.

## 3. Status names

- **Runs:** `queued`, `working`, `awaiting_approval`, `completed`, `failed`, `cancelled`.
- **Proposals:** `pending`, `approved`, `rejected`, `withdrawn`, `expired`. (`executed` and
  `execution_failed` are reserved for a later execution step and refused by a check constraint in wave 1.)
- **Office lights:** green = a run `working`; yellow = a proposal `pending` or run `awaiting_approval`;
  red = last run `failed` within 24 hours; grey = idle.

## 4. Permissions

| Permission | Holders | Use |
|---|---|---|
| `ai_agents.view.organisation` | `director`, `administrator`, `technical_admin` | Agent office and registry (no patient data on these pages). |
| `ai_agents.manage.organisation` | `director` | Enable or disable an agent; change its tool allowlist. |
| `ai_assistant.use.organisation` | `director` | Use the Director quick answers page. |
| `ai_proposals.view.branch` | `director`, `ca_supervisor`, `finance_officer` | See the approval inbox for their branches. |
| Decision on a proposal | Holders of the proposal type's domain permission (section 6) | Approve or reject. |

Added by one additive migration in the registry slice (firstOrCreate, give only what is missing, a missing
role is logged and skipped, `down()` a no-op), and mirrored in `PermissionCatalogue::roles()`. None of
these is in `AUTHORITY_OVER_PEOPLE_AND_ACCESS`.

## 5. Data sources (wave 1, read-only)

A data source is a PHP class implementing `App\Domain\Agents\Tools\AgentTool` with `code()`,
`requiredPermission()`, `inputSchema()` and `run(User $requester, ?Branch $branch, array $input): array`.
Every call checks the requester's permission and effective branch and the agent's allowlist.

| Tool code | Wraps | Permission checked |
|---|---|---|
| `insights.today` | `ClinicInsightsService::today()` | `insights.view.organisation` |
| `insights.sales_summary` | the Sales summary figures used by RPT-01a | `insights.view.organisation` |
| `inventory.stock_review` | `InventoryStockReviewService` low-stock, expiring and expired counts per SKU | `inventory.view.branch` |

## 6. Proposal types (wave 1)

| Type | Proposed by | Decided by (permission) | Payload |
|---|---|---|---|
| `inventory.restock_suggestion` | `inventory_analyst` | `inventory.stock_reviews.manage.branch` | SKU public id, location, current quantity, reorder level, suggested quantity, reason code |

Approval records the decision and audit only. It creates no purchase order, movement or stock change.

## 7. No AI service (owner decision 2026-11-05)

- KPOne makes no call to any AI service. There is no API key setting and no AI dependency.
- A guard test fails if any application code references `api.anthropic.com`, `ANTHROPIC_` settings or an
  AI SDK package.
- Adding AI calls later needs a new owner decision (API key, spending limit and a data review) and a
  contract change.

## 8. Runtime agents in wave 1

| Code | Name | Title | Department | Tools |
|---|---|---|---|---|
| `director_assistant` | Dato' Arif | Chief of Staff (AI agent) | Leadership | `insights.today`, `insights.sales_summary`, `inventory.stock_review` |
| `inventory_analyst` | Hana | Inventory Analyst (AI agent) | Clinic Operations | `inventory.stock_review` |

Build agents (shown in the office only, no tools): `ui_designer` Daniel, `technical_admin_agent` Faris,
`qa_reviewer` Nadia. Names are the owner's draft and can be renamed in the registry.

Seeded by a local and testing seeder only (fictional data), never by a migration.

## 9. Knowledge pack files

One file per agent at `docs/agents/<code>.md`, sections: Role, Scope, Data sources (tool codes), Rules,
Example input and output, Must never do. **No company figures, salaries, staff lists, supplier costs or
credentials in these files**; that data lives in KPOne's database and reaches an agent only through tools.

## 10. Reserved names (to prevent clashes between parallel slices)

| Slice | Migration prefix | Owns |
|---|---|---|
| AG-1 registry and activity log | `2026_11_05_100000` to `100900` | `app/Domain/Agents/` (models, registry, run log), the permission migration, `PermissionCatalogue` lines |
| AG-2 approval inbox | `2026_11_05_200000` to `200900` | `app/Domain/Agents/Proposals/` |
| AG-3 agent office page | none | `resources/js/pages/Agents/Office.vue` and its components |
| AG-4 data sources | none | `app/Domain/Agents/Tools/` and the no-AI guard test |
| AG-5 Director quick answers | none | `app/Domain/Agents/Assistant/`, `resources/js/pages/Agents/Assistant.vue` |
| AG-6 Inventory analyst | none | `app/Domain/Agents/Inventory/`, an Artisan command `agents:inventory-review` |

Routes live under `/agents/*` and are named `agents.*`. Only AG-1 edits `PermissionCatalogue`; later slices
ask for a contract change instead.

## 11. Office feed shape (`GET /agents/office-feed`)

```json
{
  "serverTime": "2026-11-05T02:00:00Z",
  "pendingProposals": 3,
  "agents": [
    {
      "code": "inventory_analyst",
      "displayName": "Hana",
      "jobTitle": "Inventory Analyst (AI agent)",
      "department": "Clinic Operations",
      "kind": "runtime",
      "avatarKey": null,
      "isEnabled": true,
      "light": "yellow",
      "currentTask": "Daily stock review, Cheras",
      "lastStep": "Proposed 3 restock suggestions",
      "lastStepAt": "2026-11-05T01:58:10Z",
      "pendingProposals": 3
    }
  ],
  "messages": [
    { "from": "inventory_analyst", "to": "director_assistant", "summary": "3 items below reorder level at Cheras", "at": "2026-11-05T01:58:12Z" }
  ]
}
```

`messages` holds at most the 20 newest, with a summary of at most 120 characters. The feed carries no
patient or staff personal data.

## 12. Code location

The agent platform lives in a new domain folder, `app/Domain/Agents`, alongside the domains listed in
`AGENTS.md`.

## 13. Ask KPOne (owner decision 2026-11-05, Option A)

The owner asks Claude questions about anything in KPOne **from Claude Code on the computer that runs
KPOne**, under the owner's existing Claude plan. KPOne itself still calls no AI service (section 7); Claude
Code is the owner's own tool reading KPOne through a masked, read-only interface.

**Masked read schema.** A migration creates a PostgreSQL schema `ai_read` of views over the operational
tables. Masking rules, applied in the views, so unmasked values never leave the database through this path:

| Field | Shown as |
|---|---|
| Patient name, guardian name | Initials only, e.g. `A. B.` |
| NRIC or passport number | Last 4 characters, e.g. `••••5678` |
| Phone, email, address | Not included |
| KP patient number, visit, invoice and claim numbers | Shown (internal identifiers, used to find the record in KPOne screens) |
| Clinical free text (notes, diagnosis text, instructions, MC reasons), invoice notes, Bill to | Not included; structured codes, counts and dates are included |
| Encrypted columns, credentials, tokens, sessions, password hashes, audit metadata | Not included |
| Staff | Display name and role only; no email, phone or personal fields |

To see a real patient's details, the owner opens the record in KPOne by its KP number, under the normal
screen permissions and audit.

**Read-only, three ways.**
1. A separate PostgreSQL login (`kpone_ai_reader`) with `SELECT` on schema `ai_read` only. The owner creates
   it once on the KPOne database using a documented command; the password goes in
   `AI_READER_DB_USERNAME` and `AI_READER_DB_PASSWORD` in the server's environment (placeholders only in
   `.env.example`).
2. A dedicated `ai_reader` database connection that sets `default_transaction_read_only = on` and a
   10-second `statement_timeout`.
3. Two Artisan commands: `kpone:read-schema` (lists the views and columns with a short description) and
   `kpone:read "<one SELECT>"`, which accepts a single `SELECT` or `WITH` statement against `ai_read` only,
   refuses anything else, caps output at 500 rows and prints a table or JSON. Each run writes an audit row
   with the row count and a hash of the query, never the results.

**Claude Code guide.** A project skill `.claude/skills/ask-kpone/SKILL.md` tells Claude how to answer: run
`kpone:read-schema`, query through `kpone:read` only, never open `.env` or connect to the database any
other way, explain answers in plain English with the numbers taken from query output, and say when data is
synthetic.

**Privacy.** Query results are sent to Anthropic as part of the owner's Claude use. All data is synthetic
today; **before real patient data is in KPOne, this path needs the PDPA and legal review** recorded in the
production gate.

| Slice | Migration prefix | Owns |
|---|---|---|
| AG-7 Ask KPOne | `2026_11_05_700000` to `700900` | schema `ai_read`, `config/database.php` `ai_reader` connection, `app/Console/Commands/Read*`, `.claude/skills/ask-kpone/` |
