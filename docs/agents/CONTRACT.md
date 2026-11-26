# Agent platform — wave 1 contract

Status: **owner-authorised 2026-11-05.** Owner decision the same day: **no AI service is called from
KPOne** (no Claude API key; the existing Claude plan is used only for building). Wave 1 agents are
rule-based and work only from database queries. Planning
source: the owner's agentic handover of 2026-11-04. This file is the single contract every wave 1 builder
follows. Change it only in its own PR, before the slices that depend on the change.

## 1. Principles (non-negotiable)

1. **Agents propose. People approve. The system executes.** No agent changes clinic data. An approved proposal
   records the decision. Since the owner decision of 2026-11-18 (section 15), approving a **transfer** or **restock**
   suggestion may also **prepare work** (a stock request or a purchase request) through an Inventory service,
   called as the approving person, in the same transaction as the decision, and only while the switch
   `agents.prepare_enabled` is on (default off). Every other proposal type stays decision only.
2. **An agent never sees more than the person who asked.** Every tool call is checked against the
   requesting user's permissions and effective branch, and against the agent's own tool allowlist. A
   scheduled run, which no person asked for, runs as "system" (section 5): its results are filtered by
   each viewer's own permissions when shown.
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
  `execution_failed` stay reserved and refused by the check constraint: a prepared stock request or purchase request
  still has to be approved, sent and received, so the proposal stays `approved` and carries a link, section 15.)
- **Office lights:** green = a run `working`; yellow = a proposal `pending` or run `awaiting_approval`;
  red = last run `failed` within 24 hours; grey = idle.

## 4. Permissions

| Permission | Holders | Use |
|---|---|---|
| `ai_agents.view.organisation` | `director`, `administrator`, `technical_admin` | Agent office and registry (no patient data on these pages). |
| `ai_agents.manage.organisation` | `director` | Enable or disable an agent; change its tool allowlist. |
| `ai_assistant.use.organisation` | `director` | Use the Director quick answers page. |
| `ai_proposals.view.branch` | `director`, `ca_supervisor`, `finance_officer` | See the approval inbox for their branches. |
| `ai_daily_summary.view.branch` | `director`, `ca_supervisor`, `finance_officer`, `administrator` | Open the daily summary page for their branches (sections are filtered further, section 14). |
| `ai_connector.use.organisation` | `director` | Use the KPOne connector (section 16): the Director's own read-only door for the Director's AI app. Added by migration `2026_11_21_100100` (owner decision 2026-11-21). |
| Decision on a proposal | Holders of the proposal type's domain permission (section 6) | Approve or reject. |

Added by one additive migration in the registry slice (firstOrCreate, give only what is missing, a missing
role is logged and skipped, `down()` a no-op), and mirrored in `PermissionCatalogue::roles()`. None of
these is in `AUTHORITY_OVER_PEOPLE_AND_ACCESS`.

## 5. Data sources (wave 1, read-only)

A data source is a PHP class implementing `App\Domain\Agents\Tools\AgentTool` with `code()`,
`requiredPermission()`, `inputSchema()` and `run(User $requester, ?Branch $branch, array $input): array`.
Every call checks the requester's permission and effective branch and the agent's allowlist.

**Scheduled runs (owner decision 2026-11-05).** The 07:00 and 14:00 runs (section 14) run as "system":
they act with no person's authority and read only the read-only aggregate data sources on their agent's
allowlist. People's permissions are applied when the results are shown: each viewer of the daily summary
sees only the sections their own permissions allow (filtering at view time). The builder of AG-8 adds a
system path to the gateway for this; it must still check the agent's allowlist and record every read as a
step, and must never be reachable from a request made by a person.

| Tool code | Wraps | Permission checked |
|---|---|---|
| `insights.today` | `ClinicInsightsService::today()` | `insights.view.organisation` |
| `insights.sales_summary` | the Sales summary figures used by RPT-01a | `insights.view.organisation` |
| `inventory.stock_review` | `InventoryStockReviewService` low-stock, expiring and expired counts per SKU | `inventory.view.branch` |
| `inventory.branch_balances` | Current quantity and reorder level per SKU for every branch of the organisation | `inventory.view.branch` at the requesting branch |
| `inventory.dispensing_activity` | Last dispensing date and quantity dispensed per SKU over a window (default 60 days) | `inventory.view.branch` |
| `inventory.stock_counts` | Governed stock count cases (STK-5): last approved count per SKU and location, open cases and unresolved variances | `inventory.view.branch` |
| `clinic.day_summary` | Patients registered, waiting, served and average waiting time per branch and doctor for a local date | `insights.view.organisation` |
| `money.day_summary` | Net sales, receipts by method, discounts and outstanding due for a local date (Daily summary definitions) | `insights.view.organisation` |
| `system.health` | Counts only: error-level application log entries since the last run (never the log text), failed scheduled agent runs, pending database migrations, failed queue jobs if that table exists | `ai_agents.view.organisation` |
| `panel.claims_summary` | Approved Panel invoices not yet claimed, overdue claims and accepted-but-unpaid amounts per Panel (Panel summary definitions) | `insights.view.organisation` |

## 6. Proposal types (wave 1)

| Type | Proposed by | Decided by (permission) | Payload |
|---|---|---|---|
| `inventory.restock_suggestion` | `inventory_analyst` | `inventory.stock_reviews.manage.branch` | SKU public id, location, current quantity, reorder level, suggested quantity, reason code |
| `inventory.expiry_warning` | `inventory_analyst` | `inventory.stock_reviews.manage.branch` | SKU and batch public ids, location, quantity, expiry date, days left, suggested action code (`use_first` / `write_off_review`) |
| `inventory.transfer_suggestion` | `inventory_analyst` | `inventory.stock_reviews.manage.branch` at the **sending** branch (section 15) | SKU public id, from branch, to branch, quantity, both branches' current quantity and reorder level; from section 15: source and destination location public ids |
| `inventory.slow_moving` | `inventory_analyst` | `inventory.stock_reviews.manage.branch` | SKU public id, location, quantity, last dispensed date, days without dispensing, suggested action code (`stop_reordering` / `review`) |

Approval records the decision and audit. Expiry warnings and slow-moving suggestions stay decision only: they create
nothing, and staff act on them through the existing Inventory screens. For transfer and restock suggestions,
approval may also prepare work (section 15); it still moves no stock by itself, because a stock request or purchase
request has its own approval, dispatch and receiving steps.

**Fixed rules (computed in PHP, never guessed):**
- Restock: reorder level minus current quantity, rounded up, for SKUs at or below their reorder level.
- Expiry: batches with positive quantity expiring within the SKU's own alert window (STK-42; 7 to 365 days, none set means 90) capped at 90 days, of the branch-local date; `use_first` when
  60 to 90 days remain, `write_off_review` under 60 days or already expired.
- Transfer: suggested only when the receiving branch is below its reorder level and the sending branch would
  stay at or above its own reorder level after sending; quantity is the smaller of the shortage and that
  surplus. Restock is still proposed for any shortage left.
- Slow-moving: positive quantity and no dispensing for 60 days or more.

## 7. No AI service, except the chatbox (owner decisions 2026-11-05 and 2026-11-24)

- **The rule.** KPOne makes no call to any AI service **except through the one chatbox adapter** (section 17), which the owner authorised
  on 2026-11-24 slice by slice. Nothing else in KPOne calls an AI service, holds an AI key or depends on an AI SDK package.
- **The guard.** `NoAiServiceGuardTest` fails if any application code, configuration, route, frontend file or `.env.example` names an AI
  provider endpoint or setting, or if an AI SDK package is required or locked, **except** `app/Domain/Agents/Chat/Providers/` and
  `config/ai_chat.php`. A second check fails if any code outside the chatbox's own controller, middleware, command, binding and routes
  uses the chat classes (so no clinical, patient, billing or inventory code can call the AI).
- **The key.** The provider key is `AI_CHAT_API_KEY` in the server's environment secrets only, never in code, `.env.example` values, a commit or
  chat. The chatbox is off (`AGENTS_CHAT_ENABLED=false`) until the owner turns it on. The PDPA and lawyer review is approved (owner-confirmed, verbal, 25 Nov 2026; the owner keeps the written copy), so it no longer limits the chatbox to fictional data; the owner still decides when and where to turn it on.
- The KPOne connector (section 16) is a door that the owner's AI app calls; KPOne still calls nothing for it and holds no key for it.
- Any further AI feature (consultation, files, voice, images) needs its own owner decision and a contract change.

## 8. Runtime agents in wave 1

| Code | Name | Title | Department | Tools |
|---|---|---|---|---|
| `director_assistant` | Dato' Arif | Chief of Staff (AI agent) | Leadership | `insights.today`, `insights.sales_summary`, `inventory.stock_review`, `clinic.day_summary`, `money.day_summary`, `panel.claims_summary` (the last three added for AG-5, owner decision 2026-11-06) |
| `inventory_analyst` | Hana | Inventory Analyst (AI agent) | Clinic Operations | `inventory.stock_review`, `inventory.branch_balances`, `inventory.dispensing_activity` |
| `stocktake_officer` | Rizal | Stocktake Officer (AI agent) | Clinic Operations | `inventory.stock_counts` |
| `report_assistant` | Lina | Management Assistant (AI agent) | Leadership | `clinic.day_summary`, `money.day_summary`, `panel.claims_summary`, `inventory.stock_review` |
| `technical_admin_agent` | Faris | Technical Admin (AI agent) | Technology | `system.health` |

Dato' Arif's quick answers cover four groups (owner decision 2026-11-05): **Clinic today**, **Money**,
**Panel** and **Stock**, using the data sources above.

Build agent (shown in the office only, no tools): `qa_reviewer` Nadia. Faris is a runtime agent from
wave 1 (owner decision 2026-11-05).
**The design agent (Daniel) is deferred** until the owner has every function they want; the KPOne theme stays
as it is until then. Names are the owner's draft and can be renamed in the registry.

Seeded by a local and testing seeder only (fictional data), never by a migration.

**Registering them on a real server (AG-36, owner decision 2026-11-21).** The agents are listed once in `App\Domain\Agents\Registry\AgentCatalogue`,
read by the local and testing seeder and by `php artisan kpone:register-agents` (`--dry-run`, `--force` in production, `--enable`,
`--add-missing-tools`). The command is idempotent, creates agents disabled, never changes an existing agent's code, name, title,
enabled state or lock version, never removes a tool, and writes one `ai_agents.registered` audit row. Design:
[AGENT-REGISTRATION-DESIGN.md](AGENT-REGISTRATION-DESIGN.md).

## 9. Knowledge pack files

One file per agent at `docs/agents/<code>.md`, sections: Role, Scope, Data sources (tool codes), Rules,
Example input and output, Must never do. **No company figures, salaries, staff lists, supplier costs or
credentials in these files**; that data lives in KPOne's database and reaches an agent only through tools.

## 10. Reserved names (to prevent clashes between parallel slices)

| Slice | Migration prefix | Owns |
|---|---|---|
| AG-1 registry and activity log | `2026_11_05_100000` to `100900` | `app/Domain/Agents/` (models, registry, run log), the permission migration, `PermissionCatalogue` lines |
| AG-2 approval inbox | `2026_11_05_210000` to `210900` | `app/Domain/Agents/Proposals/` |
| AG-3 agent office page | none | `resources/js/pages/Agents/Office.vue` and its components |
| AG-4 data sources | none | `app/Domain/Agents/Tools/` and the no-AI guard test |
| AG-5 Director quick answers | none | `app/Domain/Agents/Assistant/`, `resources/js/pages/Agents/Assistant.vue` |
| AG-6 Inventory analyst | none | `app/Domain/Agents/Inventory/`, an Artisan command `agents:inventory-review` |
| AG-8 Daily summary and Lina | `2026_11_11_000200` (the team range moved to `2026_11_11_000100` onward on 2026-11-06) | `app/Domain/Agents/DailySummary/`, `resources/js/pages/Agents/DailySummary.vue`, the `agents:daily-run` command and its scheduler entry |
| AG-9 Rizal | none | `app/Domain/Agents/Stocktake/` |
| AG-10 Faris | none | `app/Domain/Agents/Health/` |

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

**Privacy.** Query results are sent to Anthropic as part of the owner's Claude use. The PDPA and legal review of
Ask KPOne is approved (owner-confirmed, verbal, 25 Nov 2026); the owner keeps the written copy and the coordinator records its date and
reference in `docs/BASELINE.md`. The masked `ai_read` schema stays, and small counts can still point at a person.

| Slice | Migration prefix | Owns |
|---|---|---|
| AG-7 Ask KPOne | `2026_11_05_700000` to `700900` | schema `ai_read`, `config/database.php` `ai_reader` connection, `app/Console/Commands/Read*`, `.claude/skills/ask-kpone/` |

## 14. How agents report: one daily summary page, twice a day (owner decisions 2026-11-05)

- Agents run **twice a day** through Laravel's scheduler (the one already used for
  `public-intakes:cleanup`; no job queue), in each branch's time zone, idempotent per branch, run slot and
  local date:
  - **07:00, full run:** Hana (all four checks), Rizal, Lina's report for **yesterday**, Faris.
  - **14:00, update:** Lina's **today so far**, Hana's restock and expiry checks again (an item already
    proposed today is not proposed twice), Faris.
- Results appear on **one page, `/agents/daily-summary`** (permission `ai_daily_summary.view.branch`:
  Director, CA Supervisor, Finance Officer, Administrator), one section per agent per branch:
  - **Lina:** patients registered, waiting time, served, **per doctor (patients seen and average
    consultation time)**, sales and receipts, outstanding, Panel claims due and overdue, stock alerts; each
    figure shown **beside the same weekday last week** with an up or down arrow.
  - **Hana:** restock, expiry, transfer and slow-moving counts with links to the matching inbox items.
  - **Rizal:** stock counts due (no approved count for a SKU and location in the last 30 days), open count
    cases and unresolved variances.
  - **Faris:** one system-health line (all clear, or the counts from `system.health`).
- Section visibility, on top of page access: clinic and per-doctor figures need
  `insights.view.organisation`; money figures need `reports.daily_summary.view.branch`; Panel figures need
  `reports.panel_summary.view.branch`; stock (Hana, Rizal) needs `inventory.view.branch` at that branch;
  Faris needs `ai_agents.view.organisation`. These reuse existing grants, so nobody sees a figure here that
  they cannot already see in KPOne.
- No message, email, push notification or WhatsApp is sent. Hana's items wait in the approval inbox for a
  decision; the summary links to them. Office lights follow section 3.
- The summary keeps no free text; it is rebuilt from stored run results and data sources.
- **Doctor names (owner decision 2026-11-05).** Per-doctor figures in Lina's report and on the daily summary
  show each doctor's display name, as the Insights Provider table does, not an anonymous label.

## 15. Approval that prepares work (owner decision 2026-11-18, agreed with the Inventory team)

Status: **contract only; nothing is built.** Slices follow in this order: this contract change, the proposal changes
and link columns (no Inventory calls), the Inventory entries, then the wiring and inbox.

**Rules for both types**

1. Approve calls an existing Inventory service **as the approver**, inside the same database transaction as the decision, so
   permission, branch, row locks, spare-stock and status checks and the Inventory audit run as if that person pressed the
   button on the Inventory page. If the Inventory call refuses, the whole approval rolls back: the proposal stays pending and
   the inbox shows the Inventory message. There is never "approved but nothing created".
2. **Two modes, chosen by the server's check, never by the screen alone.** The approver sends `mode = prepare` or
   `mode = decision_only`. The server refuses `prepare` unless the approver also holds the Inventory create permission at the
   right branch. A person who can decide but not prepare (for example a Finance Officer or the Director) sees
   "Approve (decision only)" and a plain reason; a person who can prepare also sees "Approve and create the stock request"
   or "Approve and create the purchase request".
3. **Retry-safe:** the Inventory retry key is a UUID version 5 of `ai-proposal:<proposal id>` with one fixed namespace
   constant. A double press returns the same record; the proposal row lock serialises two approvers. Retrying an already
   approved proposal by the same person returns the same link.
4. **Link, not `executed`:** the proposal stays `approved` and records what it prepared (record type, public id, time) in
   nullable link columns, shown in the inbox as "Stock request SR-... created" or "Purchase request PR-... created".
   The link columns can be set only on an approved proposal (check constraint).
5. **Audit:** the existing `ai_proposal.approved` row, plus the existing Inventory row with the approver as actor and
   `source: ai_proposal`. Both carry the proposal id. Ids and codes only; no agent text ever reaches an Inventory record.
6. **Lock order:** the proposal row first, then Inventory rows. Inventory code never locks `ai_proposals`, so there is no cycle.
7. The existing two-person rules are untouched: the decider is never the person who started the run. The Inventory team adds,
   on its side, that a stock request carrying a proposal id must be approved by someone other than its requester (service and
   PostgreSQL); ordinary requests are unchanged.
8. **Feature switch:** `agents.prepare_enabled`, default **off**. While it is off, every approval is decision only and the
   inbox shows no "create" button. The owner turns it on (owner decision, marked for his review). Week one of the pilot is
   Daily summary only, so it stays off then.
9. A proposal's payload and the approver's edits are validated by the agent platform, and checked again by the Inventory
   service under its own locks. Nothing is trusted from the screen.

**Transfer suggestion becomes a stock request**

- Hana runs at the **receiving** branch, but since STK-31 only the **sending** branch can create a cross-branch stock request, from
  its own location. So a transfer proposal's `branch_id` is the **sender**, while its run stays at the receiver, and the
  receiver is in the payload (`to_branch_code`). This is the one exception to "a proposal is made for the run's own branch",
  allowed for this type only: the run's branch must equal the payload's receiver and the proposal's branch its sender.
  Daily counts and de-duplication count transfers by the run's branch, and the receiving branch's Daily summary shows a
  read-only line "Transfer suggested from <branch>, decided there".
- Decided by `inventory.stock_reviews.manage.branch` at the sender. Prepared only by someone who also holds
  `inventory.stock_requests.create.branch` (in practice a CA Supervisor at the sender).
- New payload fields, both required: `source_location_public_id` (the sender's location with the most spare stock) and
  `destination_location_public_id` (the receiving branch's location below its level). The approver may change `quantity`
  (above 0, never above the payload), the source location (active, at the sending branch, not a patient returns location)
  and the destination location (active, at the receiving branch, not a patient returns location).
- If stock changed meanwhile, the Inventory service refuses with its own message (for example "This branch can spare N of
  this item. Lower the quantity."); the approver lowers the quantity or rejects. The inbox shows "Suggested on <date>".
- Entry (Inventory team): `StockRequestService::createFromProposal(User $approver, int $proposalId, array $values)`.

**Restock suggestion becomes a purchase request**

- The proposal is already at the branch with a location. Approve calls the purchase request service as the approver:
  destination = the proposal's location, one line with the suggested quantity, and the fixed note
  "From a restock suggestion" (plain words, not agent text).
- Prepared only by someone who also holds `inventory.purchase_requests.create.branch`.
- Live re-check under lock: if the balance is now above the reorder level, refuse with "Stock is now above the reorder level,
  so there is nothing to order. Reject this suggestion." If an open purchase request or order already holds this item for this
  location, warn before Approve (not a refusal): "An open purchase request or purchase order already includes this item
  for this location."
- Entry (Inventory team): `PurchaseRequestService::sendFromProposal(...)`.

**Interface between the two teams.** The agent platform owns the proposal, the approval, the link columns, the switch and the
inbox. The Inventory team owns one small class that implements the agent platform's `PreparesWork` interface (name and
`prepare` signature settled in the interface slice) and the two entries above, which take the proposal id only as an audit and
link tag. Each Inventory table that records a prepared request gains a nullable `ai_proposal_id` (restrict on delete), for the
trail only. No new permission is needed (existing grants are used).

**Not changed:** expiry and slow-moving suggestions stay decision only; no agent changes clinic data; KPOne still calls no AI
service (section 7; the next-wave design is separate and needs the owner's decision).

## 16. The KPOne connector (CN-1, owner decision 2026-11-21)

Design: [MCP-CONNECTOR-DESIGN.md](MCP-CONNECTOR-DESIGN.md). KPOne exposes one read-only MCP endpoint (`POST /mcp`, Streamable HTTP) that
the Director's AI app can use as a custom connector. **Claude calls KPOne; KPOne never calls an AI service.**

- **Switch:** `agents.connector_enabled` (`AGENTS_CONNECTOR_ENABLED`), default OFF, read on every request. Off: `/mcp`, the OAuth
  discovery routes, `/oauth/register`, `/oauth/token` and `/oauth/authorize` answer 404 and no token works.
- **Sign-in:** OAuth 2.1 with PKCE through `laravel/passport` (pinned `~13.7.6`, the last release that uses phpseclib 3, which
  `laravel/socialite` needs) and the MCP package `laravel/mcp` (1.2.x). Passport's own routes are off; `routes/ai.php` registers
  only the routes above. The consent page (`/oauth/authorize`) is an ordinary KPOne page behind the normal sign-in, for a holder of
  `ai_connector.use.organisation` only. One scope, `mcp:use` (read only; there is no write scope). Access tokens last 1 hour,
  refresh tokens 30 days. A client may register itself only with a redirect origin in `config/mcp.php` (`MCP_REDIRECT_DOMAINS`).
- **Every call:** token valid, the person still active and still holding the permission (`ConnectorAccess`), 30 calls a minute and
  500 a day per person, then one short run of the registered agent `claude_connector` ("(AI agent)", created on first use, disabled
  by the Director on /agents to refuse every call) through `AgentToolGateway`: the agent's allowlist, the tool's permission, the
  person's effective branch, input validation and `AggregatePayloadGuard`. A 10-second statement timeout and a 64 KB result cap
  apply.
- **Audit:** `ai_connector.tool_called` (tool, branch code, outcome, reason, row count; never the figures), `ai_connector.access_refused`
  and `ai_connector.tokens_revoked`. If the audit row cannot be written, nothing is returned.
- **Revoke:** `php artisan agents:connector-revoke {--user=<email>}` (one person, or everyone when left out).
- **Tools:** CN-1: `list_branches` (data source `connector.list_branches`). CN-2 adds seven questions that read existing data
  sources through the gateway, once for each branch the Director works at (or the one named): `clinic_day_summary` (no doctor rows),
  `money_day_summary`, `sales_summary`, `panel_claims_outstanding`, `stock_alerts`, `stock_balances_and_counts` and `system_health`
  (organisation-wide, counts only). Each is a `ConnectorQuery` class that returns an explicit list of keys (no pass-through), at most
  25 rows with a `limits` note, staff-typed names cleaned and cut to 80 characters, dates validated (a range is at most 31 days),
  plus `definitions` and `asOf`. The connector agent's allowlist is the union of their sources. CN-3 adds `visits_by_state`,
  `queue_now` and `panel_claims_made`: three small data sources of their own (`connector.visits_by_state`, `connector.queue_now`,
  `connector.panel_claims_made`, permission `insights.view.organisation`) whose numbers reconcile with the clinic day summary
  and the Panel Claims list in tests. `queue_now` counts patients carried over from earlier days (`carriedOver`).
- **Validation:** a request is checked (dates, range, branch) before anything is counted or recorded; an invalid or refused
  request neither uses the daily limit nor creates a run, and is audited as `invalid_input` or `branch_not_allowed`.
- **Connected apps (CN-4):** `GET /agents/connected-apps` lists the signed-in holder's own live connections (when connected,
  when last asked) and `DELETE /agents/connected-apps/{client}` revokes that person's tokens for that app (audited as
  `ai_connector.tokens_revoked`, scope `one_app`). Nobody sees another person's connections.
- **Operating:** [CONNECTOR-OWNER-GUIDE.md](CONNECTOR-OWNER-GUIDE.md) (what the owner does in the Claude app) and
  [CONNECTOR-TRIAL-CHECKLIST.md](CONNECTOR-TRIAL-CHECKLIST.md) (switching it on and off on the trial server).
- **Data rule:** the PDPA and lawyer review is approved (owner-confirmed, verbal, 25 Nov 2026), but the connector keeps its own switch: it stays off on a server with real patient data until the owner gives a separate written OK at switch-on, naming the server and the date (REL-AG.md, design note section 8).

## 17. The AI chatbox (CHAT-1, owner decision 2026-11-24)

Design: [AI-CHATBOX-DESIGN.md](AI-CHATBOX-DESIGN.md). Slice 1: an "Ask KPOne" drawer on the Director overview page for the Director only.

- **Switch:** `agents.chat_enabled` (`AGENTS_CHAT_ENABLED`), default OFF, read on every request. Off: the chatbox routes answer 404 and the drawer does not appear.
- **Permissions:** `ai_chat.use.organisation` (Director only) and `ai_chat.history.view.organisation` (Director only in slice 1). Not people or access authority; none for Technical Admin.
- **Routes** (JSON, behind the switch and the permission, `no-store`): `GET /agents/chat` (state, examples, own threads), `POST /agents/chat/messages`
  (10 a minute), `GET /agents/chat/threads/{id}`, `POST /agents/chat/threads/{id}/archive`, and for the history permission `GET /agents/chat/others` and `GET /agents/chat/others/{id}`.
- **One message:** switch, permission, the text (1 to 1,000 characters; a value that looks like an identity number, phone number or email is refused and nothing is saved),
  the thread (own, not archived), an optional client `key` (the same key returns the stored answer instead of asking again; the same key while the first is running is refused as busy), the limits of 10 a minute and 30 a day per person,
  and the monthly spend cap (the owner's RM 50): RM 1 is **reserved** under a database lock before the provider is called and **settled** to the real cost from the provider's reported tokens afterwards, also when the answer failed after tokens were spent (`ai_chat_usage`); a reply without a token count is treated as a failure. Then one run of
  the registered agent `ai_chatbox` ("AI chatbox (AI agent)", created on first use, disabled by the Director on /agents to refuse every message). Inside it the model may ask the connector's
  fixed read-only questions (tool names as in section 16 plus `list_branches`) through `ConnectorToolRunner`, with at most 6 tool rounds and 8 tool calls in all, and a 90-second overall deadline. The chatbox adds no data source and no write.
  **Shared with the connector:** these reads use the connector agent and the connector's 500 a day limit, and appear as `ai_connector.tool_called` rows without a client id; they work whether or not `AGENTS_CONNECTOR_ENABLED` is on, and are refused if the Director disabled the "Claude connector" agent.
- **The answer:** plain text (tags removed, 4,000 characters at most) plus a "Figures" list and a report link built by KPOne from the tool result (amounts exact: "Sen" values shown as RM with two decimals). The model's words never supply the figures.
- **History:** `ai_chat_threads` and `ai_chat_messages`; title, text and figures encrypted; messages append-only (PostgreSQL refuses update except the retention purge, delete and truncate); threads are archived, never deleted. Retention 90 days: `php artisan ai-chat:purge` (daily) empties text and figures once, removes a thread's title, and keeps the rows and counts. The others' list includes archived threads (for oversight). A person sees their own history; the history permission opens others' (each open audited first).
- **Audit:** `ai_chat.message` (outcome, tool names, token counts, cost in sen; never text), `ai_chat.history_opened` and `ai_chat.history_list_opened`, `ai_chat.history_purged`.
- **Provider:** `ChatModel` interface; one HTTP adapter in `app/Domain/Agents/Chat/Providers/`; settings in `config/ai_chat.php`. Tests bind a fake and make no network call.

## 18. Ask through Claude (CHAT-2a, owner-approved 2026-11-24)

Design: [CHAT-2-ASK-THROUGH-CLAUDE-DESIGN.md](CHAT-2-ASK-THROUGH-CLAUDE-DESIGN.md). The Director asks in a KPOne panel and answers through their own Claude; KPOne holds no key and calls no AI service.

- **Switch:** `agents.ask_claude_enabled` (`AGENTS_ASK_CLAUDE_ENABLED`, default off), and the connector switch must be on too. Off: the routes answer 404 and `save_answer` is not listed.
- **Routes** (JSON, `ai_chat.use.organisation`, `no-store`): `GET /agents/ask` (own recent requests), `POST /agents/ask` (10 a minute; a client `key` makes a retry return the same request), `GET /agents/ask/{code}` (the poll), `POST /agents/ask/{code}/cancel`.
- **Request:** `ai_ask_requests`; a 10-character code, the question (1 to 1,000 characters; identity numbers, phone numbers and emails refused) and the answer (up to 4,000) encrypted; status `pending`, then once `answered`, `expired` (30 minutes) or `cancelled`; at most 3 pending per person; creating is limited to 10 a minute and 30 a day. PostgreSQL: one transition out of pending, no change to who asked or when, no delete, no truncate; the retention purge (`php artisan ai-ask:purge`, daily, 90 days) only empties the text, once.
- **`save_answer(request_id, answer_text)`:** the connector's only write. Needs the Ask switch, `ai_connector.use.organisation` and `ai_chat.use.organisation` (Director only), and a token with the `mcp:answer` scope (`agents.ask_claude_require_scope`, default true; a connection made before CHAT-2 stays read-only until the Director reconnects once; only the owner may turn the requirement off for a trial). It saves the answer text of the caller's own pending, unexpired request, once (the same text again is accepted; a different text is refused). Unknown, expired, answered, cancelled and other people's ids all get one plain message. Tags are removed; text that looks like an identity number, phone number or email is refused. 10 a minute and 30 a day. Each call is one short run of the connector agent (task `ask_answer`).
- **Discovery:** `GET /.well-known/oauth-authorization-server` and `/.well-known/oauth-protected-resource` (and their nested forms) advertise `mcp:answer` as well as `mcp:use` only while the Ask switch is on; with it off they are the package's own.
- **Audit:** `ai_ask.created`, `ai_ask.answered`, `ai_ask.refused` (reason), `ai_ask.cancelled`, `ai_ask.expired`, `ai_ask.purged`: ids, character counts and reasons, never text.
- **Panel:** the Ask KPOne panel's second path is CHAT-2b (not built yet). The prompt and links are built by the server (`AskPrompt`): the desktop-app link `claude://claude.ai/new?q=…` (only while the prompt is within 12,000 characters) and `https://claude.ai/new` for the copy-and-open way.
