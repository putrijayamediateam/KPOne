# Agent platform: next wave design (AG-28)

Status: **DESIGN ONLY, for the owner to approve. Nothing here is built or authorised.** It changes the
"KPOne calls no AI" rule of [CONTRACT.md](CONTRACT.md) section 7, so it needs the owner's written approval
(section 1) before any slice starts. Written 2026-11-18 by the Agent platform lead, from the owner's wishes and
decisions relayed by the coordinator.

## 0. In plain English

The owner wants KPOne to become the base that many agents work from: at least two agents for every system
(Clinic, Stock, Billing and Finance, Panel, HR, Marketing, Business Development, Technology), a chatbot that knows
the whole system, the Director giving requests by voice and receiving a notification plus a report or slide to
review, agents that check the agents below them, an assistant in the consultation, and drafted HR letters.

His decisions: AI comes from **one paid provider** with a business data agreement; **only synthetic data** until
the PDPA review; the **API key lives only in the environment's secrets**; agents **prepare** work and **a person
approves every action**, with audit; no-AI items start now in other teams.

What this design adds, in one line each:

- One door to the AI provider (`AiGateway`), one switch to turn it off, and a test that proves nothing else
  in KPOne can reach any AI service (section 1).
- Three data levels, and only the first is ever sent while data is synthetic (section 2).
- A second, checking agent for every system, then a cross-system auditor, then the Director (section 4).
- A chatbot, voice, notifications and reports/slides, each with its own limits (sections 5 to 8).
- Daily and monthly spending caps (section 9).
- The risky parts (consultation, HR letters, real patient data) built **last**, behind their own approvals (section 11).

**Needs the owner (list in section 13):** approve the AGENTS.md clause, choose the provider, decide how voice
is transcribed, set the spending cap, and keep real data out until the PDPA review is recorded.

## 1. The rule change

### 1.1 What is true today

- `docs/agents/CONTRACT.md` section 1.3 and section 7: KPOne calls no AI service; no API key setting; no AI package.
- `tests/Feature/Agents/NoAiServiceGuardTest.php` fails if code, config, routes, frontend or `.env.example`
  name a provider, endpoint or SDK package.
- `docs/BASELINE.md` section 4 lists AI service calls from KPOne as not authorised.
- `AGENTS.md` has no clause about AI. It forbids building listed features until authorised, so a new AI clause is
  the right place for the owner's decision.

### 1.2 Proposed AGENTS.md clause (the owner must approve the wording)

Add under "Non-negotiable boundaries":

> - KPOne may call exactly one AI provider, through `App\Domain\Agents\Ai\AiGateway` only. The provider is named
>   in `docs/agents/NEXT-WAVE-DESIGN.md` once the owner has chosen it and a business data agreement is in place.
>   The API key exists only in the server environment's secrets: never in code, `.env`, `.env.example`, tests,
>   logs, audit records or the database. While the PDPA review is not recorded in `docs/BASELINE.md`, only
>   synthetic data and Level 0 aggregate data (section 2) may be sent. An agent only prepares work (a proposal,
>   a draft, a report); a person with the right permission approves every action that changes data, and the
>   approval is audited. An AI output is never executed, never trusted as a figure, and never stored as clinical
>   or financial record without a person's approval.

Then amend CONTRACT.md sections 1.3 and 7 and add a BASELINE row in the same PRs that need them. Until the
owner approves the clause, `NoAiServiceGuardTest` stays exactly as it is.

### 1.3 What replaces `NoAiServiceGuardTest`

The guard is not deleted; it becomes a **boundary guard** (`AiProviderBoundaryTest`, new, in slice AG-30). The
old test is edited only in the slice that adds the gateway, with the coordinator's approval of the exact lines.

It fails when:

1. A provider hostname, provider SDK package or API-key setting name appears **anywhere except** the gateway
   folder `app/Domain/Agents/Ai/`, `config/ai.php` and the one named package in `composer.json`.
2. A second provider (any host or package not on the allowlist of one) appears anywhere, including the gateway.
3. Any key-shaped literal appears in tracked files (`sk-`-style prefixes, long base64 or hex strings next to a
   key-like name), or `.env.example` gives the AI key setting a **non-empty value**. The setting name may exist
   in `config/ai.php` as `env('AI_API_KEY')`; it is never given a value outside the server's secrets.
4. Any class outside the gateway makes an outgoing HTTP call to a host that is not on the
   gateway's allowlist, or builds a prompt without going through `PromptBuilder` (section 2.3).
5. The kill switch config (`ai.enabled`) is missing or defaults to anything other than `false`.

It still passes when no key is set: the gateway then refuses every call with a clear message. CI and tests use a
`FakeAiDriver` that returns canned text and makes no network call, so tests never need a key.

## 2. Provider and data flow

### 2.1 One door

`AiGateway::complete(AgentRun $run, PreparedPrompt $prompt): AiResult`. The **only** code that can reach the
provider. It, in order:

1. Refuses when `ai.enabled` is false, the agent is disabled, or the run is not started (existing recorder).
2. Checks the data level of every input against the levels currently switched on (section 2.2).
3. Checks the daily and monthly caps (section 9). Refuses, never queues silently.
4. Calls the provider with a fixed timeout, no retries beyond one on a network error, and no streaming to the browser.
5. Records one `ai_agent_run_steps` row (model, token counts, cost estimate, duration, outcome) and, if enabled,
   an encrypted prompt and response for audit (section 2.4).
6. Returns text. The caller treats it as **untrusted** (section 2.5).

The provider name, model and endpoint live in `config/ai.php`; the key is read from the environment's secrets
only. Which provider is a **decision for the owner** (section 13): the design is provider-neutral and the gateway
has one driver. The data agreement must be confirmed in writing before any call carries data that is not
synthetic, including whether the provider keeps or trains on prompts, where it processes data, and who the
sub-processors are.

### 2.2 Three data levels

| Level | What | May be sent | Switch |
|---|---|---|---|
| **0** | Aggregates: counts, totals, catalogue item names, dates, branch codes, existing agent figures | Yes, from day one, even before the PDPA review (it is already shown on agent screens) | on |
| **1** | Masked rows from the `ai_read` views (patient initials, last 4 of IC, no contact data, no clinical free text) | **Synthetic data only** until the PDPA review is recorded | off |
| **2** | Identifiable or clinical content: names, contact details, doctor-typed notes, staff personal data, HR letter fields | **Synthetic data only**; separate switch per use (consultation, HR letters) and each needs its own owner, doctor or HR approval | off |

Rules: the level of a prepared prompt is the highest level of any field in it; a field is tagged by the code
that adds it, not by the model. A level that is switched off refuses. Switching a level on is a database setting
changed by the Director with an audit row, and **for Level 1 and 2 with real data it is refused unless
`BASELINE.md` records the PDPA review** (checked by a test that reads a config flag the owner's approval PR flips).

Never sent at any level: credentials, tokens, sessions, password hashes, encrypted columns, audit metadata,
IC or passport numbers in full, bank or card data, `.env` content, anything from outside KPOne.

### 2.3 Prompts are built by code, not by users

`PromptBuilder` assembles each prompt from a fixed template per agent task, a list of tagged fields, and the
user's question (chatbot only, as a clearly marked quoted block). A person's typed text is data inside a block,
never a system instruction. Templates live in `app/Domain/Agents/Ai/Prompts/` and are reviewed like code.

### 2.4 What is stored

- Always: run, step, model, token counts, cost estimate, outcome, the data level and the field names used (not values).
- Optional, owner switch, default on while synthetic: the prompt and response, **encrypted** with the app key,
  kept 30 days (then purged by a scheduled command like the intake cleanup), so a reviewer can see what the agent
  was given. With real data this stays off for Level 2 until the PDPA review says otherwise.
- Never: the key, the full request headers, voice audio (section 7).

### 2.5 Output is untrusted

- No model output runs as code, SQL, a URL to open or a permission. The model cannot call a tool that writes.
  It can request **read tools** from an allowlist (the existing `AgentToolGateway`, checked against the asker's
  permissions and branch); each call is a logged step.
- **Number grounding.** Any figure in a generated report or answer must equal a value returned by a tool in the
  same run. A check extracts numbers from the text and compares them with the tool results; a figure with no
  source is flagged "not verified" on the screen, and a report with a failed figure cannot be approved until it is
  fixed or the line removed. This is deterministic code, not a model.
- A prompt-injection line inside data (for example a medicine name that says "ignore your instructions") is only
  text: the model has no write tools, outputs are labelled as AI drafts, and a person approves.

## 3. Agent registry per system

The existing registry (`ai_agents`, `ai_agent_tools`, runs, steps, messages) gains three columns by one additive
migration in slice AG-32: `system` (which system the agent belongs to), `layer` (1 prepares, 2 checks, 3 audits;
the Director is the final layer and is a person, not an agent) and `reviews_agent_id` (which agent a checker
looks after). Agents stay labelled "(AI agent)", never deleted.

At least two agents per system: a **preparer** (layer 1) and a **checker** (layer 2). Names below are drafts.

| System | Layer 1 preparer | Layer 2 checker | Data it may read (all through gateway, aggregate first) |
|---|---|---|---|
| Stock | Hana (exists): restock, expiry, transfer, slow-moving | Rizal (exists, stock counts) plus a Stock reviewer that re-derives each suggestion from balances | stock review, balances, dispensing activity, counts |
| Dispensary / OTC | Dispensary analyst: busy-hour and waiting patterns | Dispensary checker: flags suggestions contradicted by stock or queue figures | queue and dispensing aggregates |
| Billing and Finance | Lina (exists, report) and a Finance analyst: outstanding, adjustments, cash closing variance | Finance checker: reconciles every figure to the daily summary and the ledger totals | money day, cash closing, invoice adjustments (aggregate) |
| Panel | Panel analyst: ageing, unclaimed, rejected | Panel checker: re-computes ageing from claim totals | Panel claims summary |
| HR | HR drafter: letters and notices (Level 2, last) | HR checker: checks letter against template, dates, and policy text | staff counts, leave and roster only when HR builds them |
| Marketing | Website content drafter (draft-only, existing website drafts) | Content checker: wording, EN/BM consistency, no clinical claims | website draft content (no patient data) |
| Business Development | BD analyst: Panel and new-patient trends | BD checker | Insights aggregates |
| Technology | Faris (exists, system health) | Health checker: confirms Faris's findings against failed jobs and queue figures | system health |
| Leadership | Dato' Arif (exists, quick answers) and the chatbot | Auditor (layer 3, section 4) | all the above, through the others |

Permissions (additive migration, `PermissionCatalogue`): one **view** permission and one **use** permission per
system, named like `ai_agents.stock.use.organisation`, held by the roles that already own that system's data
(Stock: `director`, `ca_supervisor`, `finance_officer`; Finance and Panel: `director`, `finance_officer`,
`panel_officer`; HR: `director`, `hr_manager`; Marketing: `director`, `marketing`, `administrator`; and so on).
An agent never holds more than the person who asked (contract principle 2, unchanged). A scheduled run keeps
running as "system" on allowlisted aggregates and is filtered per viewer when shown (unchanged). Each system's
team owns its data sources and signs off its agents' allowlists.

## 4. The layered review

```
Layer 1  preparer agent  → draft / proposal / report         (can be wrong)
Layer 2  checker agent   → findings attached to the draft    (finds mistakes below)
Layer 3  auditor agent   → cross-system findings            (finds mistakes between systems and in layer 2)
Final    Director        → reads draft + all findings, approves or rejects     (a person)
```

How a checker works, in order: **deterministic checks first** (recompute the figure from the source, compare
units, look for a duplicate, check the policy rule), then, if the owner enables it, a **model critique** that
reads the draft and the tool results and lists doubts. Findings are append-only rows
(`ai_review_findings`: draft, layer, agent, severity, text, evidence step), never edits to the draft.

Rules:

- A checker **cannot approve, reject, change or execute** anything. It can only add findings and raise severity.
- The draft screen shows every finding above the Approve button; **High** findings need a typed reason from the
  approver to proceed. A draft with no checker run says "not checked".
- Disagreement is shown, not hidden: if layer 2 and layer 3 differ, both are shown.
- The auditor (layer 3) reads layer 1 and 2 outputs of several systems and looks for contradictions (stock says
  low, Finance says supplier unpaid; Panel ageing up, Billing says claims submitted). It uses the same data
  levels as the agents it reads.
- Honest limit: a model checking a model reduces some errors and shares others. The control is the person who
  approves and the grounding check in section 2.5, not the number of layers.
- Cost control: layers 2 and 3 run once per draft, never in a loop, and are skipped when the daily cap is near.

## 5. The chatbot over masked data

"A powerful chatbot that knows the whole system." Two kinds of knowledge:

1. **How KPOne works**: a read-only knowledge pack built from `docs/` (BASELINE, team memory files, runbooks) and the
   schema descriptions of `ai_read`. Retrieval by simple search over a stored index; no patient data in it.
2. **What KPOne holds**: answers from data.

For data, the recommended order is **tools first**, SQL second:

- **v1 (Director only):** the model chooses among the existing read tools (aggregates, already permissioned) and
  answers from their results, with number grounding. Page: `/agents/assistant` (exists for preset questions) gains a free-text
  box; keeps the Malay/English labels of the V2 page.
- **v2 (Director only, Level 1 switch):** an optional text-to-SQL tool over `ai_read` **only**, reusing the Ask KPOne
  guards (a single SELECT or WITH, 500 rows, 10-second timeout, read-only login, audit with a hash of the query,
  never the results). Real patient data is still blocked by the Level 1 rule.
- **Not for other roles yet.** `ai_read` is organisation-wide, not branch-scoped, so a CA Supervisor chatbot would
  need branch-filtered views. Each other role gets the curated tools for their own system first.
- Never answers from outside the data; says "I can't find that" and shows what it looked at (the tool steps).
- A person's chat history is stored per user, encrypted, 30 days, visible only to that user and the Director's audit.

## 6. Notifications

Today KPOne sends nothing, and that stays true for patients. For staff:

- **In-app first:** a bell with a count and a list ("Report ready", "Draft waiting for your approval", "Checker found 2 issues"),
  using the existing proposals and a new `notifications` table scoped to the person. Content-free titles, a link
  to the page; no patient or money detail in the title.
- **Later, per owner choice:** email to the Director with a link only ("A report is ready"); browser push after
  that (needs a service worker). The KPOne HR exception in `AGENTS.md` (2026-11-06) allows staff messages by
  WhatsApp, SMS and email for staff and HR only, each under its HR design note; agent notifications would need their
  own owner decision to use those channels, and any message to patients stays unauthorised.
- A person turns each kind on or off; quiet hours are respected for non-urgent items.

## 7. Voice input

The Director speaks a request; KPOne turns it into text; the chatbot answers or prepares a report.

- Browser records the audio only while a button is held, uploads it over the session, the server transcribes it and
  **deletes the audio immediately**; no audio is kept. The transcript appears on screen for the Director to edit
  or confirm **before anything runs**. Voice never approves or rejects anything.
- **Provider problem to decide:** the owner chose **one** provider. If that provider has no speech-to-text, then
  either (a) use the browser's built-in speech recognition, which on many browsers sends audio to the browser's
  vendor and is **not** covered by the business data agreement, so it may be used only for non-sensitive
  Director requests and the page must say so; (b) accept a second, speech-only provider with its own agreement
  (this breaks decision 1, so it needs the owner's explicit yes); or (c) keep voice for later and start with typed
  requests. **Recommended: (c) first, (a) as a clearly labelled option, (b) only if the owner decides.**
- Malay and English mixed speech needs a real test with the Director's own voice before promising accuracy.
- Capability needed: microphone permission in the browser, a short-lived upload endpoint with a size limit.

## 8. Report and slide output

- A **report** is a structured document (JSON: title, sections, tables, charts of query results) produced by an
  agent run, rendered by KPOne as a V2 page. Figures come from tools and are number-grounded.
- A **slide deck** is the same structure rendered as slides in an in-app viewer (title, bullets, one chart per
  slide). No PowerPoint file generation at first; PDF or print from the browser can come later; PPTX needs a
  library and is a later decision.
- The Director's flow: request (typed or voice) → agent run → notification → opens the draft with the checker
  findings → comments or edits text blocks (figures stay locked to their source) → **Approve**. Approval stores an
  **immutable snapshot with a hash**, who approved and when. Only an approved snapshot can be shared or exported.
- Reports are stored as private records scoped to the organisation and the viewer's permissions; a report only
  contains what its author-run was allowed to read.

## 9. Costs and limits

Prices are the provider's and are not guessed here; slice AG-31 measures real token use on synthetic data and
fills the table before the owner sets caps.

| Limit | Proposed default (owner sets the real numbers) |
|---|---|
| Monthly hard cap (organisation) | a ringgit amount chosen by the owner; reaching it switches `ai.enabled` off for new runs |
| Daily cap per agent and per organisation | tokens and calls; reaching it refuses and shows "daily limit reached" on the draft |
| Per-user chat rate | a small number of questions per minute and per day |
| Prompt size | fixed maximum input and output tokens per task |
| Scheduled runs | the existing 07:00 and 14:00 runs only; no loops |
| Layers 2 and 3 | once per draft; skipped near the cap |

Visible usage page for the Director and Administrator (calls, tokens, estimated cost per agent and system),
an alert in the daily summary at 80% of a cap, a one-click kill switch (the Director sets `ai.enabled` off), and
an automatic circuit breaker after repeated provider errors. Rough call volume to size the first cap: the
scheduled runs are a few dozen calls a day for three branches; chat, layers and reports add to that, so the
first month should run with a low cap and measured numbers.

## 10. Stock agents and execution (to agree with Inventory)

Today an approved proposal **records the decision only**. Owner decision (2): agents prepare, a person approves every
action. So execution, where it exists, must be a normal domain action started by the person's approval.

Proposed contract, to be agreed with Zulkifli (Inventory lead) before AG-36:

- The agent platform owns: the proposal, the approval, the idempotency key and the audit row.
- Inventory owns: the **executor** for each proposal type, a domain service method with the same rules as the manual
  screen (permission, branch scope, transaction, row locks, `lock_version`, audit). The approval calls it inside the
  approver's authority, never the agent's.
- Each executor re-reads the current state and **refuses if it changed** since the suggestion (stale stock), and
  is idempotent on the proposal key.
- What an executor may do for Stock, in order of safety: (1) create a **draft** internal request (no stock effect);
  (2) add a "reviewed" acknowledgement (exists as STK-4); (3) only later, any stock movement, which stays on the
  existing governed paths (transfer, STK-5 count and write-off) with their independent approvals.
- Questions for Zulkifli: is there a draft purchase or transfer request to create (the analysis noted no printable PO
  or supplier return)? Which suggestions should never execute (expiry write-off)? Who may approve each type?
  These need to be answered by the Inventory team, via the coordinator, before the slice is cut.

## 11. Things built last, with their own gates

- **Consultation assistant** (fills the consultation from what the doctor types): doctor-typed clinical text is
  Level 2 and the most sensitive data in KPOne. It only structures what the doctor typed into the existing fields
  as a **draft** the doctor reviews and saves; it suggests no diagnosis or medicine unless the doctor and owner
  approve that separately; it cannot finalise. Needs a doctor's sign-off, the PDPA/legal review, and a position on
  medical-record rules. Synthetic only until then. Clinical team owns the screen; agent platform owns the gateway.
- **HR letters and notices:** drafted from fixed templates with staff fields (Level 2); the HR Manager approves; the
  Director may approve sensitive ones; a letter is never sent by KPOne. Waits for the HR team's roster and leave work.
- **Any real patient data at Level 1 or 2:** blocked until the PDPA review is recorded in `docs/BASELINE.md`.

## 12. Slice order

| Slice | What | Needs |
|---|---|---|
| AG-30 | Owner-approved AGENTS.md clause; `AiGateway` with `FakeAiDriver` only (no provider, no key), `config/ai.php` (disabled), usage table, kill switch, `AiProviderBoundaryTest` replacing the old guard | Owner approves the clause; coordinator approves the exact old-test lines |
| AG-31 | Real provider driver, key from the environment's secrets, a synthetic smoke test, measured tokens and costs, usage page | Owner chooses the provider and cap; business data agreement in writing |
| AG-32 | Registry columns (system, layer, reviews), permissions per system, number-grounding check, `ai_review_findings`, deterministic layer 2 for Hana and Rizal | Teams sign off their allowlists |
| AG-33 | Chatbot v1 for the Director: tools-first, free text, Malay/English | AG-31 |
| AG-34 | Report and slide viewer, Director approval, immutable snapshot | AG-32 |
| AG-35 | In-app notifications | none |
| AG-36 | Second agents per system, one system at a time (Stock first with the Inventory executor, then Finance, Panel, Technology), each with its checker | Each team's data sources and the executor contract |
| AG-37 | Voice: typed first, then the chosen transcription path | Owner's voice decision (section 7) |
| AG-38 | Layer 3 auditor | AG-36 |
| AG-39 | Chatbot v2 (text-to-SQL over `ai_read`), other roles' chat | Level 1 switch, PDPA |
| AG-40 | HR letters | HR team, PDPA |
| AG-41 | Consultation assistant | Doctor sign-off, PDPA/legal, Clinical team |

Each slice is one PR with a report, the usual gate, and no existing assertion changed without the coordinator's
approval of the exact lines. The no-AI items the owner wants started now belong to other teams and do not depend on this order.

## 13. Decisions the owner needs to make

1. Approve (or change) the AGENTS.md clause in section 1.2.
2. Choose the one provider and confirm the business data agreement in writing (retention, training, location,
   sub-processors).
3. Voice: option (a), (b) or (c) in section 7.
4. Monthly and daily caps (section 9), after AG-31 measures them.
5. Whether prompts and responses are stored encrypted for 30 days (section 2.4) and for which levels.
6. Who holds the environment's API key and who rotates it (an IT person or agency, name to record).
7. Which systems get a second agent first after Stock.
8. The PDPA/legal review for Level 1 and 2 with real data, and the doctor's approval for the consultation assistant.

## 14. What does not change

- Agents prepare, people approve, every action is audited.
- Agents are labelled "(AI agent)".
- Agent screens show aggregates only unless a higher level is switched on and approved.
- Permissions and branch scope are checked server-side as the asking person; scheduled runs stay "system".
- No message is sent to patients (patient messaging stays unauthorised); agents send no staff message by WhatsApp, SMS or email until the owner decides.
- Ask KPOne through Claude Code stays as built (contract section 13), under the owner's own plan, and needs the PDPA review before real data.
- The local AI test (a free local model) is a test only and does not conflict with this design; its results can inform the provider choice.
- Synthetic data only; not production-approved.
