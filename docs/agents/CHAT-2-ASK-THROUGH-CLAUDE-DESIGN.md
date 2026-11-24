# CHAT-2 "Ask through Claude": design note

Written by the Agent platform team (Irfan, Shalini) on 2026-11-24 for the owner's decision, at the coordinator's request
relaying the owner: "Tanya Claude + save_answer ... ni mcm nice je, cuma kalau dia boleh tanya claude dari panel kpone
fuh memang cantik". **Docs only. Nothing is built, and nothing here is authorised until the owner answers section 11.**

## 1. What it is, in plain words

The Director types a question in the Ask KPOne panel, as in CHAT-1, but the answer comes from the **Director's own Claude
account** (their subscription), not from a paid API. KPOne opens Claude with the question already written in. Claude
answers using the KPOne connector's existing read-only tools (MCP_KPJaya), then calls one **new** connector tool,
`save_answer`, which writes the answer text back to the question. The panel notices and shows the answer under the
question.

The Director still presses Enter in Claude themselves. KPOne never sends anything to Claude by itself and holds no key.

## 2. The flow

1. The Director types a question in the panel and presses **Ask through Claude**.
2. KPOne creates a **pending request** (a short id, the question, an expiry) and opens Claude (section 3) with a prompt
   that carries the question, the request id and one instruction: "answer using the KPOne tools, then call save_answer
   with this request id".
3. The Director reads the prompt in Claude and presses Enter.
4. Claude answers in its own window, then calls `save_answer(request_id, answer_text)`.
5. The panel polls (every 3 seconds while a request is pending and the tab is visible; the same polling the CHAT-1
   drawer uses) and shows the answer under the question, labelled **"Answered by Claude. KPOne did not check this
   answer."** If nothing arrives within 30 minutes the request expires and the panel says "No answer came back. You can
   ask again."

## 3. Can a link prefill Claude? (what the documents say)

Checked on 2026-11-24 from Anthropic's help pages and documentation, through web search summaries (the pages themselves
should be read again by whoever builds this):

| Link | Documented? | What it does |
|---|---|---|
| `claude://claude.ai/new?q=<text>` | **Yes**, Anthropic help centre, "Open Claude Desktop with a link" | Opens a new chat in the **Claude desktop app** with the prompt box filled in. The person reviews and presses send. Text must be URL-encoded; **about 14,000 characters** are kept and the rest is cut. |
| `https://claude.ai/new?q=<text>` (the web chat) | **Not documented** | One GitHub issue reports that it worked until recently and then stopped; a third-party browser extension uses `?prompt=` instead. Treat it as unreliable. |
| `https://claude.ai/code?prompt=...` | Yes, but for **Claude Code on the web** only | Not a chat with connectors; not what we need. |
| `claude://code/new?q=` (phone) | Yes, for Claude Code only | Not what we need. |

**Design consequence.** The panel offers, in this order:

1. **Open in Claude desktop**: a `claude://claude.ai/new?q=...` link. If the Director has the desktop app, it opens with
   the prompt filled in. A browser cannot tell us whether the app opened, so the panel always also shows step 2.
2. **Copy the question and open Claude**: copies the full prompt to the clipboard (the browser's clipboard call, inside
   the click) and opens `https://claude.ai/new` in a new tab; the Director pastes and presses Enter. This always works.
   If the clipboard is refused, the prompt is shown in a text box with Select all.

If the prompt is longer than 12,000 characters (it will not be: the question is capped at 1,000 characters, as in
CHAT-1) the deep link is not offered. **A real try is needed before building**: the owner or Daniel presses the link on
the Director's own computer and phone and reports which of the two ways works (section 12, step 1). We do not promise
that the web link prefills.

The prompt itself is plain text: the question inside a clearly marked block (so a question cannot be mistaken for
instructions), the request id, and the instruction. The Director sees all of it before pressing Enter.

## 4. `save_answer`: the connector's first write tool

**Plainly: this changes the connector's read-only contract.** MCP-CONNECTOR-DESIGN section 7 says "No writes: the
connector exposes no tool that changes data, and the code path has no write permission to call." `save_answer` is the
first exception. It must not go in without the owner's explicit approval, and the contract and design note are updated
in the same slice (the owner's approval is the one thing that makes that change legitimate; compare the CHAT-1 reversal
of "KPOne calls no AI service").

Scope, as tight as it can be made:

| Rule | How |
|---|---|
| **Who** | The Director only. The caller must hold `ai_connector.use.organisation` **and** `ai_chat.use.organisation` (both Director only today), be active, in the organisation, and the kill switches must be on. |
| **Which requests** | Only the caller's **own** request, which is **pending** and **not expired**. An unknown, expired, already answered, cancelled or someone else's id is refused with one plain message that does not say which of those it was (so ids cannot be probed). |
| **What it writes** | Only `answer_text` (and `answered_at` and the status) on that one request row, once. The tool takes two arguments and returns "saved" or "refused". **It cannot write to any clinical, patient, money, stock, staff or settings table**, and its code path has no handle on them. |
| **Write once** | A second call with the same text returns "saved" (a retry is safe); a different text after the first answer is refused. No edit, no delete. |
| **Length** | 1 to 4,000 characters (the CHAT-1 cap). Tags are removed with the same rule CHAT-1 uses; text that looks like an identity number, phone number or email is **refused** (the CHAT-1 `PersonalText` check), because the answer is free text from a model and `AggregatePayloadGuard` only checks structured results. |
| **Encrypted** | The question and the answer are encrypted at rest (as CHAT-1 messages are). |
| **Audit** | `ai_ask.created`, `ai_ask.answered`, `ai_ask.refused` (with a code) and `ai_ask.expired`: ids, lengths, outcome. **Never the question or the answer text.** The tool call is also a step in the connector agent's run log, as the read tools are. |
| **Retention** | 90 days, then the daily purge empties the question and the answer (the same purge shape as `ai-chat:purge`: only the purge may null the text, once; a row is never deleted). |
| **Rate limit** | `save_answer`: 10 a minute and 30 a day per person (separate counters; the 500 reads a day is unchanged). Creating a request: 10 a minute and 30 a day, and at most **3 pending requests** at once per person. |
| **Token scope** | A **new** token scope, `mcp:answer`, is needed for `save_answer`. Connections made before this change carry only `mcp:use` and stay read-only until the Director reconnects the connector once (Connected apps page). This keeps today's trial connections exactly as safe as they are. |
| **Kill switch** | New `agents.ask_claude_enabled` (`AGENTS_ASK_CLAUDE_ENABLED`), **default off**, on top of the connector switch. Off: the panel button is hidden, the create route answers 404 and `save_answer` is not listed. |
| **Database** | PostgreSQL enforces the same shape as CHAT-1: an answer is set once, only while pending, only by the request's own user; the text is nulled only by the purge; no delete or truncate. |

What `save_answer` does **not** protect against, said plainly:

- **KPOne cannot verify that the answer is true or that Claude used the tools.** Anyone who can call the tool as the
  Director with the `mcp:answer` scope could save any text (up to the cap) on a pending request. That is why the panel
  labels it "Answered by Claude. KPOne did not check this answer.", shows **no Figures block** (CHAT-1's Figures are built
  by KPOne from a tool result; here there is no such result), and why the length and personal-data checks exist.
- A question is the Director's own text, but it travels inside a prompt. The block markers reduce, and do not remove,
  the chance of Claude treating the question as instructions; the Director reads the prompt before sending it.

## 5. Where the question and answer go (PDPA note)

The question text is placed in a link or the clipboard and then typed into the Director's own Claude account, so it is
held by Anthropic under the Director's account and chat history, **outside** KPOne's 90-day retention and outside
KPOne's control. The answer text comes back the same way. Both the existing connector note (MCP-CONNECTOR-DESIGN
section 8) and this one say: **fictional data only until the PDPA review**. The panel shows a one-line reminder: "Do not
type patient names or identity numbers." (the CHAT-1 prompt check also refuses identity numbers, phone numbers and
emails before a request is created). The URL carries the question text, so it can appear in the browser history of the
Director's computer; the request id alone is not a secret and is useless without the Director's session and token.

## 6. How it fits with the API chatbox (CHAT-1, #1087, off by default)

Two ways to answer the same typed question:

| Path | Paid by | Needs | Figures block | Status |
|---|---|---|---|---|
| **API** (CHAT-1) | The owner's API key, RM 50 a month cap | `AI_CHAT_API_KEY` in server secrets, `AGENTS_CHAT_ENABLED` | Yes, built by KPOne from the tool results | Built, off by default, no key exists |
| **Through Claude** (CHAT-2) | The Director's Claude subscription | The connector switched on and added in Claude, `AGENTS_ASK_CLAUDE_ENABLED` | No (text only, labelled unchecked) | This note |

**Recommendation (the owner decides): one panel, two paths, each behind its own switch.** The drawer shows only the
paths that are switched on: if only CHAT-2 is on, the button reads **Ask through Claude**; if both are on, the Director
picks one with a small selector ("Ask here" or "Ask through Claude"), and the choice is remembered in the browser only.
History is one list, newest first, each item marked with its path. The two use **separate tables**: CHAT-2 adds
`ai_ask_requests` and does **not** touch #1087's `ai_chat_threads` and `ai_chat_messages`, so #1087 can merge as it is
and the API path can be removed later without a data migration. If the owner never wants the API path, nothing in
CHAT-2 depends on it and it can be deleted in its own change.

The alternative, **replace** the API path, would mean deleting the CHAT-1 chat service, provider adapter and tables
(they are not live yet, so nothing is lost but the work). The team does not recommend deleting before the owner has
tried both, because only the API path can show a checked Figures block and answer without opening another window.

## 7. Permissions, migration and tests (for the build)

**Permissions.** No new permission is needed: asking in the panel uses `ai_chat.use.organisation`, reading history uses
`ai_chat.history.view.organisation`, and `save_answer` requires `ai_connector.use.organisation` plus the new token
scope. None is people or access authority; Technical Administrator and every other role get nothing. (If the owner
prefers a separate permission so the two features can be granted separately, one narrow `ai_ask.use.organisation` for
the Director is a small addition: say so in section 11.)

**Migration.** One additive migration, `2026_11_25_100000_create_ai_ask_requests.php` (to be checked against the agent
platform's range in BASELINE section 6, item 23): `ai_ask_requests` (id, organisation, user, short request id with a
unique index, question encrypted, answer encrypted nullable, status `pending`, `answered`, `expired` or `cancelled`,
expiry, answered time, purged time, timestamps) with the PostgreSQL rules in section 4; `down()` refuses once rows
exist. The run-step type may need one new value for a write step; the build checks `ai_agent_run_steps` before
deciding. Existing databases need `php artisan migrate`; the scheduler must run for the purge.

**Tests.**
- `save_answer` happy path: the answer appears on the caller's own pending request and nowhere else; a repeat with the
  same text is "saved"; a different text after it is refused.
- Refusals: unknown, expired, answered, cancelled and **another Director's** id (same message, no row change); a missing
  `mcp:answer` scope (an old `mcp:use` token cannot write, and the tool is not listed); a non-Director; an inactive
  user; the switches off; text over the cap; an identity number, phone number or email in the answer; tags removed.
- **Invariance:** a before and after comparison of every table except `ai_ask_requests`, audit rows and run logs shows
  no other change after `save_answer`, as the connector and BIL tests already do.
- Rate limits (10 a minute, 30 a day, 3 pending); audit rows hold no question or answer text (a grep over the audit
  payload for a sentinel string); purge nulls text once and never deletes; PostgreSQL refuses an update after
  answered, a delete and a truncate.
- Panel: renders nothing when the switch is off or the person is not the Director; polling shows the answer, expiry
  says so, copy fallback works when the clipboard is refused; the deep link is built with correct encoding and is not
  offered over the length limit; no `v-html`; 44 px targets on a phone.
- `NoAiServiceGuardTest` stays as it is: CHAT-2 names no AI provider and adds no package.

## 8. Build slices (after the owner's answers)

1. **CHAT-2-0 (a try, no code):** the owner or Daniel tries the two links on the Director's computer and phone and
   reports what happens (checklist below). No build starts before this.
2. **CHAT-2a (backend):** table, create and poll routes, `save_answer` with the new scope, switches, audit, purge, the
   contract and connector design note updates, tests.
3. **CHAT-2b (panel):** the drawer's second path, the selector, history list, copy and deep link, Daniel's look at 390
   and 1440 px in light and dark.
4. **CHAT-2c (owner guide):** a page in plain words for the Director: reconnect the connector once, switch it on, what
   to do if Claude does not call `save_answer`.

**Try checklist for CHAT-2-0.** (a) On the computer with the Claude desktop app: paste `claude://claude.ai/new?q=Hello%20test`
into the browser address bar; does the app open with "Hello test" in the box? (b) On the same computer: open
`https://claude.ai/new?q=Hello%20test` in a browser; is the box filled? (c) On a phone with the Claude app: open each
link from a message; what happens? (d) In a Claude chat where the KPOne connector is switched on, ask it to list its
tools; is the connector listed? Write the results in the team session.

## 9. Known limits (stated up front)

- Prefill is **not guaranteed**: only the desktop-app link is documented; the web link may not fill the box, so the
  copy fallback is always shown. The question must be pasted by hand in that case.
- The Director must press Enter, and in the right chat (one with the KPOne connector switched on). If Claude does not
  call `save_answer`, the request expires after 30 minutes and nothing is saved.
- The answer is **unchecked text**: no Figures block, no link back to a report, and a model can be wrong or ignore the
  instruction. The label says so.
- Anthropic holds the chat in the Director's account; KPOne's 90-day purge does not reach it.
- One answer per request; a follow-up is a new request (no threads in v1).
- Only the Director; Finance Officer and others get nothing, as in CHAT-1.
- The write tool is one more thing the owner must trust the token scope to limit; revoking the connector (the existing
  Connected apps page) stops it at once.

## 10. What is not changed

CHAT-1 (#1087) and its tables; the eleven read tools and their permissions; the connector's read-only transaction for
every existing tool (`save_answer` uses its own write path, never the read runner); `AggregatePayloadGuard`;
`NoAiServiceGuardTest`; any clinical, patient, money, stock, staff or settings data. Synthetic data only; nothing is
production-approved.

## 11. Questions for the owner (plain words)

1. **May the connector get its first write tool, `save_answer`,** limited exactly as in section 4 (Director only, own
   pending requests only, answer text only, once, 4,000 characters, encrypted, audited without text, 90 days)? This
   changes "the connector never changes data".
2. **Token scope.** Is it acceptable that the Director reconnects the connector once to get the new `mcp:answer` scope
   (recommended: today's connection stays read-only), or should `save_answer` work on today's connection?
3. **One panel with two paths** (recommended), or replace the API path?
4. **Permission.** Reuse `ai_chat.use.organisation` (recommended) or add a separate `ai_ask.use.organisation`?
5. **Expiry and limits.** 30 minutes to answer, 3 pending requests, 10 a minute and 30 a day: keep?
6. **The try first.** Will the owner or Daniel run the section 8 checklist (about five minutes) before any build?
