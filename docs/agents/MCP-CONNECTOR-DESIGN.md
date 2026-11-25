# KPOne connector (MCP server): design note

Status: **design note, now built as slices CN-1 to CN-4 and documented in [CONTRACT.md](CONTRACT.md) section 16.** Written by the
Agent platform team (Irfan, Shalini) on 2026-11-21 for the owner's answers. The owner said "guna mcp" (21 Nov) in answer to
the coordinator's proposal. Where this note and the contract differ, the contract wins. The connector is off by default and
nothing here authorises real patient data: see section 8. For operating it, see
[CONNECTOR-OWNER-GUIDE.md](CONNECTOR-OWNER-GUIDE.md) and [CONNECTOR-TRIAL-CHECKLIST.md](CONNECTOR-TRIAL-CHECKLIST.md).

## 1. What it is, in plain words

A small, read-only door into KPOne that the Director's Claude app can use. The Director adds KPOne once as a **custom
connector** in the Claude app (claude.ai or the phone app). After that the Director can ask Claude things such as:

- "How many patients today at CHERAS, including the unfinished ones?"
- "How many Panel claims were made today across the three branches?"
- "What is waiting at the dispensary stock-wise, and what is about to expire?"

Claude calls the connector, the connector reads KPOne's **live** totals, and Claude writes the answer.

**The rule stays as it is: KPOne calls no AI service.** Claude calls KPOne; KPOne never calls Claude, holds no AI key and
adds no AI library. (The no-AI guard test, `NoAiServiceGuardTest`, must keep passing: see section 9.)

**Not authorised and not designed here:** an in-app chatbot that talks to Claude from inside KPOne. That would need
KPOne to call an AI service, which the owner's standing rule forbids. The tools below are written as plain, separate
classes so a future chatbot could reuse the same ones and the answers would match everywhere, but that is a separate
owner decision.

## 2. Proposed shape

| Part | Proposal |
|---|---|
| Transport | Remote MCP over HTTPS (Streamable HTTP) at one address on the trial server, for example `https://kpjaya.com/mcp`. HTTPS only. |
| Sign-in | OAuth 2.1 with PKCE, using KPOne's **existing** sign-in (the same password and Google sign-in people already use). Only an existing, active KPOne user can connect; an unknown identity never creates a user. |
| Who | The **Director only** at first, by a new narrow permission (section 5). |
| What | About 11 read-only tools that return totals, never people (section 6). |
| Safety | Every call re-checks the user, permission and branch; every result is scanned for personal data; every call is audited without its results; rate limits, timeouts, a size cap and a kill switch that is **off by default** (section 7). |
| Data | PDPA review approved (owner-confirmed, verbal, 25 Nov 2026); real patient data needs a separate written OK at switch-on (section 8). |

### How the pieces fit with what exists

KPOne already has the right shape for this. Agents read data only through `AgentToolGateway`, which checks the agent's
allowlist, the person's permission and effective branch, validates the input and scans the result with
`AggregatePayloadGuard`. The existing data sources (`clinic.day_summary`, `money.day_summary`, `insights.sales_summary`,
`panel.claims_summary`, `inventory.stock_review`, `inventory.branch_balances`, `inventory.stock_counts`,
`system.health`) are already aggregate-only. **The connector reuses them and adds only a few new tools where a question
cannot be answered today.** Ask KPOne (CONTRACT section 13) is a separate path (Claude Code on the server reading masked
views); this connector does not replace it and does not read the `ai_read` views.

**Recommended wiring:** each connector call runs as one very short agent run of a registered agent "Claude connector"
(external, labelled as such), started for the signed-in Director and finished at once. The gateway then does all the
checks, the existing run and step log (`/agents` activity) shows the calls, and no new log table is needed. The
alternative, calling the tool classes directly and writing a separate audit row, is simpler but repeats the gateway's
rules; the team recommends the run-per-call way.

## 3. Transport and server notes

- One route group for the MCP endpoint, outside the normal staff session: no cookies, no CSRF token (it is not a browser
  form), bearer token only. The OAuth sign-in page itself is an ordinary KPOne page behind the normal login.
- The endpoint checks the `Origin` header and rejects browser requests from other sites (the MCP transport guidance on
  DNS-rebinding), answers only to the trusted proxy settings KPOne already has, and sends `no-store`.
- The endpoint and the OAuth routes return **404 while the kill switch is off**, so the trial server shows nothing until
  the owner turns it on.
- Tool descriptions and field names are fixed text in the code; nothing a patient or staff member typed is ever put into a
  tool description.

## 4. Sign-in (OAuth) and tokens

- OAuth 2.1 authorisation-code flow with PKCE. The person is sent to a KPOne page, signs in as usual, and sees one
  plain consent screen: "Allow Claude to read KPOne totals (no patient names, read only)?" with Allow and Cancel.
- **Only an existing active user can finish the flow.** The permission in section 5 is checked on the consent screen and
  again on every call, so a Director who is deactivated, loses the role or is no longer active stops working at the next
  call even if the token is still valid.
- Scope: one fixed scope, `kpone:read`. No write scope exists.
- Tokens: short-lived access token (proposed 1 hour) and a refresh token (proposed 30 days, rotated on use); stored hashed
  in the database; revoked when the user is deactivated, when the permission is removed, from an artisan command, and when
  the owner turns the kill switch off.
- Redirect addresses are an **allowlist** (Claude's own callback address), never typed freely. The exact address and
  whether Claude registers itself or needs a fixed client ID is checked when the build starts, against Claude's current
  connector documentation (these details change; this note does not rely on memory for them).
- Secrets: OAuth signing keys and any client secret live in the server's environment or private storage only, never in
  code, never in `.env.example` as real values, never in the repository.
- **Dependency question (owner decision, section 10).** The team's recommendation is the two first-party Laravel
  packages for this: `laravel/mcp` (the MCP server) and `laravel/passport` (the OAuth server). They are maintained by the
  Laravel team and fit the framework, but `AGENTS.md` asks us to add a dependency only when its value and security
  posture are clear, and neither exists in `composer.json` today. The alternative is to build a minimal MCP endpoint and
  OAuth flow ourselves, which avoids two packages but means more security-sensitive code of our own. The team would check
  each package's current release, advisories and OAuth support before the build and report; the owner decides.

## 5. Authorisation

- **New permission (needs the owner's approval): `ai_connector.use.organisation`**, granted to `director` only at first.
  It is not in `AUTHORITY_OVER_PEOPLE_AND_ACCESS` and gives no people or access authority. Added by one additive
  migration (firstOrCreate, give only what is missing, a missing role logged and skipped, `down()` a no-op) and mirrored in
  `PermissionCatalogue`. Later, a Finance Officer or CA Supervisor could be added by a separate owner decision with their
  own tool subsets.
- On every call, in order: kill switch on, token valid, user active and in the organisation, holds
  `ai_connector.use.organisation`, then the **tool's own permission** (for example `insights.view.organisation`) and, for
  a `.branch` tool, an effective assignment at that branch. A tool that is refused returns a plain "not allowed"
  message and is audited as refused.
- Branch scope: "all branches" means each branch the Director has an effective assignment at. The three clinic branches
  today. A branch code the Director does not work at is refused.
- Technical Administrator and every other role: no connector access.

## 6. Tools (v1 proposal: 11, all read-only)

Every tool returns totals and fixed labels only, plus a short `definitions` text so Claude can explain what was counted,
the `asOf` time (branch-local) and, where relevant, `limits` (for example "showing the first 25"). Dates are branch-local
(`YYYY-MM-DD`), default today. A date range is at most 31 days.

| # | Tool | Answers | Built from |
|---|---|---|---|
| 1 | `list_branches` | The branch codes and names the Director may ask about | New, tiny (the Director's assigned active branches) |
| 2 | `visits_by_state` | Visits registered on a date per branch and state: **unfinished** (registered, not yet completed), completed, cancelled; and the total including unfinished | **New** (the figure behind "patients today including unfinished") |
| 3 | `queue_now` | Right now per branch: waiting, serving, on hold, not ready to call (counts) | New small read over the queue tables, or `clinic.day_summary` `waitingNow` extended |
| 4 | `clinic_day_summary` | Registered, seen, average wait and consultation minutes per branch | Existing `clinic.day_summary`, **without per-doctor rows** in v1 |
| 5 | `money_day_summary` | Net sales, receipts by method, discounts, outstanding for a date | Existing `money.day_summary` |
| 6 | `sales_summary` | Sales for a period against the previous period | Existing `insights.sales_summary` |
| 7 | `panel_claims_made` | Panel claims **created** on a date or range, per branch and per Panel: count, amount, status | **New** (the figure behind "how many Panel claims today across the 3 branches") |
| 8 | `panel_claims_outstanding` | Approved invoices not yet claimed, overdue, accepted but unpaid, per Panel | Existing `panel.claims_summary` |
| 9 | `stock_alerts` | Low stock, expiring soon (each item's alert window, up to 90 days), expired on hand: counts and the first 25 items | Existing `inventory.stock_review` |
| 10 | `stock_balances_and_counts` | Quantity against reorder level per branch, and counts due | Existing `inventory.branch_balances` and `inventory.stock_counts` |
| 11 | `system_health` | Counts only: errors since a time, failed scheduled runs, pending migrations | Existing `system.health` |

**What no tool returns in v1:** patient names or identifiers (including initials), any clinical text, diagnosis, medicine
given to a person, staff personal data (names, pay, leave, files), contact details, free text typed by staff, and invoice
or receipt numbers. Panel names and medicine names are organisation reference data and are included; they are
length-limited and stripped of control characters (a small prompt-injection surface that the team accepts and notes).
**Doctor names are left out of v1** (the existing per-doctor figures show display names; sending staff names to an
outside AI service is a different decision than showing them on a KPOne screen: owner question 4).

Two new tools (2, 7) and one small one (1, 3) are the only new queries. Each is written the way the existing data
sources are (a class implementing `AgentTool`, its definition of terms documented in the contract, a test that
reconciles it to the matching KPOne report page).

## 7. Safety

**Every call is audited, without the results.** One audit row per call: tool, the branch code or codes, the date
parameters, the user, the outcome (`ok`, `refused` with a code, `limited`), the number of rows returned and whether the
size cap cut it. Never the returned figures, never the question text (the connector does not receive the question).
OAuth events are audited too: authorised, refreshed, revoked.

| Control | Proposal |
|---|---|
| Kill switch | `agents.connector_enabled` (environment `AGENTS_CONNECTOR_ENABLED`), **default off**, read on every request. Off: the MCP and OAuth routes return 404 and existing tokens stop working at once. The person who manages the server environment turns it on. |
| Revoke | `php artisan agents:connector-revoke {--user=}` revokes tokens at once. Deactivating a user or removing the permission also stops them. |
| Rate limits | 30 calls a minute and 500 a day per user (numeric, isolated per route as in THROTTLE-1). A refused call says how long to wait. |
| Timeout | Each tool runs in a read-only transaction with a 10-second statement timeout. |
| Size cap | A result is capped at 64 KB and 25 rows per list; a cut result says so in `limits`. |
| Aggregate guard | `AggregatePayloadGuard` on every result, as for the agents. A result that fails the guard is not sent and is audited as a refusal. |
| No writes | The connector exposes no tool that changes data, and the code path has no write permission to call. **One exception, owner-approved 2026-11-24 (CHAT-2, [CHAT-2-ASK-THROUGH-CLAUDE-DESIGN.md](CHAT-2-ASK-THROUGH-CLAUDE-DESIGN.md)):** `save_answer`, off by default behind `AGENTS_ASK_CLAUDE_ENABLED`, saves the answer text of one pending "Ask through Claude" request of the signed-in Director, once, and needs the `mcp:answer` token scope. It writes nothing else. |
| No AI service | The code names no AI provider, holds no AI key and adds no AI SDK. |

## 8. PDPA note and data rule

Results reach the owner's Claude account and so reach Anthropic as part of the owner's own use of Claude (the same
position as Ask KPOne, CONTRACT section 13). Even though the tools return totals only, small counts can still point at a
person on a quiet day. The PDPA and lawyer review is approved (owner-confirmed, verbal, 25 Nov 2026; the owner keeps the written copy). **The connector still stays off (default) on any
server holding real patient data until the owner gives a separate written OK at switch-on, naming the server and the date.** This note is not legal
advice.

## 9. Tests (for the build)

Permission and role matrix (only the Director; other roles refused; refused again after the role is removed); inactive
user refused; branch not assigned refused; kill switch off gives 404 and revokes access; token revoked on deactivation;
rate limit and timeout; result guard refuses personal-data-like values; **audit rows contain no results**; each new tool
reconciles with its KPOne report page (`visits_by_state` with Insights In-clinic and the Registration board,
`panel_claims_made` with the Panel Claims list); a source test that no tool class touches a write method; and
`NoAiServiceGuardTest` still green. That test scans code, config and packages for AI provider names and settings, so
the code and config must **not** contain provider names (the Claude app's callback address is written as the
configured allowlist value, not as a literal of a forbidden word); the build checks this first.

## 10. Questions for the owner (plain words)

1. **Dependencies.** May the team use the two first-party Laravel packages, `laravel/mcp` and `laravel/passport`
   (recommended), or should we build a minimal version ourselves? The team will check the packages' current security
   record and report before using them.
2. **New permission.** `ai_connector.use.organisation`, given to the **Director only**. Yes or no? (Nothing else is added.)
3. **The tools.** Is the list of 11 in section 6 right, anything to remove, or any question you want answered that it
   does not cover?
4. **Doctor names.** Leave them out of v1 (recommended, because the answers go to an outside AI service), or include the
   per-doctor "patients seen" figures with display names as the KPOne screens do?
5. **Limits.** 30 calls a minute and 500 a day for the Director: enough?
6. **Switch.** The connector is off until you say. Who will turn it on and off on the trial server (the person who
   manages the server environment)? The team needs a name to record, not a login.
7. **Sign-in.** Use the same sign-in as KPOne (password or Google) for connecting, or Google only?
8. **Connected apps page.** Do you want a small KPOne page that shows "Claude is connected, last used at …" with a
   Disconnect button? (Optional, a later slice; the artisan revoke exists either way.)
9. **Data.** Decided: the PDPA review is approved (owner-confirmed, verbal, 25 Nov 2026); the trial server may hold real patient data only after the owner's separate written OK naming that server and the date.
10. **Later chatbot.** The tools are built so an in-app chatbot could reuse them. That needs KPOne to call an AI service
    and is **not** part of this; just confirm you do not want it designed now.

## 11. What the owner does in the Claude app (after the build, switch on)

1. Open Claude (claude.ai in a browser, or the phone app) and go to Settings, then Connectors (the exact menu names may
   differ; the team checks them against Claude's current help page when the build is ready).
2. Choose to add a custom connector and enter the KPOne connector address (for example `https://kpjaya.com/mcp`).
3. Claude opens the KPOne sign-in page. Sign in as the Director and press Allow on the consent screen.
4. In a conversation, make sure the KPOne connector is switched on, then ask a question. Claude shows which KPOne tool it
   is using.
5. To stop: Disconnect in Claude, or ask the person who manages the server environment to turn the switch off (or run the
   revoke command).

Whether a custom connector can be added depends on the Claude plan and on who is allowed to add connectors (on a team
plan an administrator may have to add it). The owner checks this in the app; the team cannot see the owner's plan.

## 12. Build slices (after the owner's answers)

| Slice | Content | Needs |
|---|---|---|
| CN-1 | Endpoint, OAuth, the permission and migration, kill switch (off), audit, rate limits, revoke command, tool 1 `list_branches` and the guard tests | Owner answers 1, 2, 5, 6, 7, 9 |
| CN-2 | Tools 4, 5, 6, 8, 9, 10, 11 (existing data sources, with the connector's own allowlist) | CN-1 |
| CN-3 | New tools 2, 3, 7 with their reconciliation tests | CN-1; owner answer 3 |
| CN-4 | Optional Connected apps page | Owner answer 8 |
| CN-5 | Contract section 16, owner guide and the trial-server checklist | After CN-1 |

Each slice follows the usual path: the Lead UI/UX Designer's look for any screen (only CN-1's consent screen and CN-4),
independent review, then the owner's ship-it. CONTRACT.md gains a section 16 and a note under section 7 when CN-1 is
built; this note changes nothing in the contract until then.

## 13. Known limits (stated up front)

- Small counts on a quiet day can point at a person; the answers still reach an outside AI service. Hence the data rule.
- Panel and medicine names are typed by staff; they are length-limited but not otherwise checked.
- The connector answers with the totals KPOne holds at the moment of the call, so it can differ by seconds from a report
  page open on another screen.
- Claude, not KPOne, writes the answer from the numbers; KPOne cannot check what Claude says. The `definitions` text in
  each result is there to keep the wording honest.
- The exact OAuth registration and callback details of the Claude app are confirmed at build time.
