# KPOne AI chatbox: design note (slice 1)

Status: **design only. Nothing here is built or approved to build.** Written by the Agent platform team (Irfan, Shalini) on
2026-11-24 from the owner's request and answers of 24 Nov. The build starts only after the owner's ship-it. Where this note
and [CONTRACT.md](CONTRACT.md) differ, the contract wins until the owner approves the changes listed in section 9.

## 1. What the owner asked for

An AI chatbox **inside KPOne**, for now only on (a) the Director's overview and (b) the doctor's consultation page. It should
later use functions like the ones KPOne already has: suggest medicines from the illness, fill fields from the clinical note,
accept files and images, take voice, and show the history of questions and answers; image generation last. The owner approved
this slice order (24 Nov): **(1) chatbox and history on the Director overview using the existing read-only questions**, (2)
consultation chatbox (note to fields, medicine suggestions, doctor confirms), (3) files and images, (4) voice, (5) image
generation with patient-image rules. **This note covers slice 1 only.**

## 2. Owner decisions recorded (24 Nov)

1. The rule "KPOne calls no AI service" (2026-11-05) is **reversed** for the chatbox ("batalkan"). The wording and the
   guard-test change are owner-approval items in section 9.
2. Data: the Director's chatbox may use everything the Director may already see; **synthetic data only until the PDPA review**.
   (The consultation sends clinical text without patient name or IC: slice 2.)
3. History is encrypted. The Director and "management" see everyone's history; everyone else sees only their own. **Which roles
   count as management is still to be answered** (section 10).
4. Clinical safety (slice 2 onwards): the AI suggests, the doctor reviews and confirms, nothing is ordered or saved without the
   doctor's own action, with the label "AI suggestion, not advice". New permissions for the Director and the Resident Doctor,
   none for Technical Admin.
5. The owner said "if MCP is used there is no need for an API". See section 3: this is true only for the connector, and it
   decides whether slice 1 can be built at all.

## 3. The one decision that blocks the build: where the AI runs

Plain words. The **connector** (merged) lets the owner's own Claude app call KPOne. There is no API fee because the owner's
Claude plan does the thinking, **but the conversation happens in the Claude app, not inside KPOne.** KPOne cannot show a
chatbox that talks to the owner's Claude plan: there is no way to embed it or borrow the plan's login. A chatbox **inside
KPOne** means KPOne sends the question to an AI provider itself, which needs an **API key and costs money per use**.

| Option | What the owner gets | Cost | Notes |
|---|---|---|---|
| A. In-KPOne chatbox through a provider API | The chatbox on the Director overview, with KPOne's history, labels and look | Pay per use; a monthly cap is built in | Needs a provider, a key in the server environment secrets (never in code), and the owner's cap |
| B. Connector only (no chatbox in KPOne) | Ask in the Claude app; KPOne answers with totals | No API fee (plan limits apply) | Already built; no history inside KPOne; cannot be placed in the consultation page |
| C. Both | A for the screens, B as the owner's own tool | As A | Same code for the questions; the connector stays |

Slices 2 to 5 (consultation, files, voice, images) **cannot be done with option B at all**, because they live inside KPOne
screens. The note below assumes A or C. **The provider is left open on purpose**; the build is written so that the provider is
one small class behind an interface and can be changed.

## 4. Slice 1 scope

A chat panel on the Director's overview page (which page this is: section 10). Typed questions only. It answers with the
same read-only questions the connector has (clinic day, money day, sales, Panel claims, stock, queue, visits by state,
system health, branches), shown as the model's sentence plus a "Figures" block built from the tool result (label, exact amount, as-of time), the "definitions", and a link to the report page. A history list of earlier
questions and answers. **No writing of any kind**, no patient-level data, no files, no voice, no images, no consultation.

Not in slice 1: the consultation chatbox, medicine suggestions, note-to-fields, files, images, voice, image generation.

## 5. How it works

New domain folder `app/Domain/Agents/Chat` (inside the existing agent platform):

- **`ChatModel` interface** with one method that takes the conversation and the tool list and returns the model's reply. One
  adapter class for the chosen provider lives in `app/Domain/Agents/Chat/Providers/`; nothing else names a provider. It uses
  Laravel's HTTP client (no AI SDK package). Timeouts, a response size cap and no streaming in slice 1.
- **Tools** are the connector's `ConnectorQuery` classes through `AgentToolGateway`, exactly as the connector uses them, so a
  question gets the same allowlist, permission check, branch check, validation and `AggregatePayloadGuard`, and the answer matches
  the KPOne report page. The chatbox adds no new data source in slice 1. (The owner's "everything the Director may access"
  is read as: everything the Director may already see through those fixed questions; wider access needs a new, reviewed tool,
  not free SQL. To confirm in section 10.)
- **Every message is one run** of a registered agent "AI chatbox (AI agent)" (`ai_agent_runs`, steps for each tool read,
  as for the connector). The Director can switch that agent off on /agents to refuse every message at once.
- **Safety wrapper.** The system prompt says: answer only from tool results, quote the definitions, never guess a figure,
  say when something is unavailable, never claim to have changed anything. A prompt that contains an IC-number-, phone- or
  email-like value is refused before it is sent (best effort, like the connector). The model's reply is shown as text only
  (no HTML), cut to a length cap, and labelled "AI answer. Check the figures on the report page."
- **Limits.** Messages per person per minute and per day, a **monthly spend cap** counted from the provider's reported token use
  (the chatbox refuses with a clear message when the cap is reached), a 10-second tool timeout, 25-row and 64 KB tool result
  caps (same as the connector) and a maximum number of tool calls per message.
- **Switch.** `agents.chat_enabled` (`AGENTS_CHAT_ENABLED`), default **off**, read on every request. Off: the panel is not
  shown and the routes answer 404.

## 6. History (encrypted)

Two tables, append-only, in the agent platform's migration range: `ai_chat_threads` (organisation, user, surface
`director_overview`, title, created and last-used times) and `ai_chat_messages` (thread, role user or assistant, **encrypted**
body, tool names used, token counts, created time). PostgreSQL refuses update and delete of messages; a thread can be hidden
(archived) by its owner and is never deleted by the app. **Audit rows hold ids, counts and tool names only, never the text.**
Who sees what: a person sees their own threads; the Director and the roles the owner names as management see everyone's, through
a separate, audited "view others' history" action (each open is recorded). Retention period: section 10; a daily purge command
removes message bodies after it (like the QR intake cleanup), keeping the row.

## 7. Permissions

New, explicit, scoped names (AGENTS.md): `ai_chat.use.organisation` (Director) and `ai_chat.history.view.organisation` (Director
and the roles the owner names as management). Not held by Technical Admin or any other role by implication. Slice 2 will add
`ai_chat.consultation.use.own` for the Resident Doctor. Additive migration with `firstOrCreate` and a missing role logged and
skipped (the usual pattern); any later grant to `ca` also goes to `medical_assistant` (team rule).

## 8. Screens and the clean look (Lead UI/UX Designer's note, 24 Nov)

Classic layout, theme tokens, light and dark, shared components. English wording for staff. The Lead UI/UX Designer reads the note
as OK with these changes and will do a full look on the screenshots (a new kind of screen with an AI answer beside company money).

- **Drawer, not inline.** A right-hand drawer opened from an **"Ask KPOne"** button (small chat icon) in the page header, at every width
  (a full-width sheet at 390 px): one design, and slice 2 needs a drawer anyway. It closes with an X, Escape and a button and returns
  focus to the "Ask KPOne" button. It is **not a blocking modal**: the figures stay visible while asking. Title "Ask KPOne"; first line
  "Ask about your branches. Answers come from KPOne's reports." No "AI assistant", "Claude" or provider names on screen.
- **History** is a "History" button in the drawer header that swaps the body to the list (back arrow returns). Each item: the first
  question (one line), the date; it opens that conversation read-only with "Ask a new question" at the top.
- **Bubbles:** user messages right in a neutral filled bubble, answers left on the plain card surface; no bright colours, no avatar; while
  working, one small line naming the tool ("Reading the money day..."); no animation under reduced motion.
- **Answers show the real figures.** The model's sentence is above a small **"Figures" block** (label, amount, as-of time) built from the
  tool result by KPOne, so the numbers on screen come from KPOne and never from the model's wording; amounts are exact ("RM 1,234.50"),
  never rounded by the model. Each answer links "Open the report" to the page that holds the figures. Under every answer a small muted line
  with an outline "AI" chip (as on the Agents page): "AI answer. Check the figures on the report page."
- **Input:** a labelled multi-line field ("Ask a question about your branches"), one filled send button at 44 px, Enter sends, Shift+Enter
  new line, a visible character limit. A refused prompt says exactly why, "Please don't type identity numbers, phone numbers or emails.",
  and keeps the text in the box.
- **States:** empty (three example questions as outline chips that fill the box and do not send by themselves); loading; error ("Couldn't
  answer. Nothing was changed. Try again." with a Try again button); **monthly limit reached** (a muted info block, not an error: "The monthly
  AI limit is reached. It resets on 1 Dec.", box disabled and out of the tab order); **switched off** (a muted line, no input); a long answer
  (a closed "Show more" after about 12 lines; never a sideways-scrolling table; any table is a card list at 390 px).
- **"View other people's history"** is a separate item in the History header menu, shown only to the permitted roles, with the banner "You are
  viewing someone else's questions. Every view is recorded." The list shows the person's name (staff-visibility rule) and the date, never the
  text, until a conversation is opened.
- **Future room:** a slot in the input row for a mic and an attachment button, not drawn now and no empty space reserved.
- **Design flag from the Lead UI/UX Designer:** the owner should see the wording of what leaves the server before the build, and name the
  "management" roles, because the "view others" list depends on it.

## 9. Items that need the owner's written approval (kept out of the build until then)

1. **`AGENTS.md`** (non-negotiable boundaries) proposed wording: "The owner-authorised AI chatbox (2026-11-24, docs/agents/AI-CHATBOX-DESIGN.md)
   permits KPOne to send questions to one configured AI provider for staff features slice by slice. It never sends patient names,
   identity numbers or contact details; data stays synthetic until the PDPA review; the provider key lives only in environment
   secrets; every AI feature has a kill switch, a spending cap and an audit trail without the text; and the AI never saves, orders
   or changes clinic data without a person's own action. The connector (Claude calls KPOne) is separate."
2. **`docs/BASELINE.md`**: the AGENT-PLATFORM row's "KPOne calls no AI service" and the "Not authorised" list are updated by the coordinator
   in the docs PR that records this approval.
3. **`CONTRACT.md` section 7 and a new section**: the no-AI rule is rewritten as above; the chatbox tables, permissions, switch and tools are
   documented there when slice 1 is built.
4. **`NoAiServiceGuardTest`** is changed on purpose and narrowly: provider names and endpoints stay forbidden **everywhere except
   `app/Domain/Agents/Chat/Providers/` and `config/ai_chat.php`**; AI SDK packages stay forbidden (the adapter uses the HTTP
   client); and a new check proves nothing outside those paths can call the provider and that no clinical, patient, billing or
   inventory code imports the chat classes. The test therefore keeps its meaning instead of being deleted. This is an existing-assertion
   change that needs the owner's explicit approval.

## 10. Questions for the owner

1. **Option A, B or C** (section 3), and **which provider** (the team does not choose; it only needs a name and a key in the server secrets).
2. **Monthly cap** in RM (suggest a small number for the trial, for example RM 50) and messages per day per person.
3. **"Management":** which roles may see everyone's chat history? (Suggest Director only for slice 1; adding HR Manager, Finance Officer
   or Administrator later is one permission grant.)
4. **History retention:** how long to keep message text (suggest 90 days, then the text is removed and the row kept).
5. **Which page is "Director overview"?** Suggest Insights, Today (the Director's first figures). Alternatives: the Daily summary page or the
   home dashboard.
6. **"Everything the Director may access":** confirm it means the existing fixed read-only questions for now, and that any wider source is added as
   a reviewed tool later (not free database queries).
7. **Trial data:** confirm synthetic data only on the server where this is switched on.

## 11. Tests (for the build)

Permission and role matrix (Director only; others and Technical Admin refused; refused again after the role is removed); kill switch
gives 404; spend cap and rate limits refuse; a prompt with an IC-, phone- or email-like value is refused before it reaches the model;
answers reconcile with the report page through the same tool (a fake `ChatModel` that calls a tool and replies); the model's reply
is escaped and length-capped; history is encrypted at rest, append-only (update and delete refused), own-only for non-management,
"view others" audited; audit rows hold no text; the guard test's new allowlist; no tool class touches a write method; and the
existing connector tests stay green.

## 12. Slices after this one (for planning only)

2. Consultation chatbox (Resident Doctor, `.own` permission): clinical text without name or IC, note to fields and medicine suggestions as
   proposals the doctor edits and confirms, using the existing Treatment Plan permissions, so the AI never writes anything itself.
3. Files and images (private disk, sniffed, size-limited, re-encoded like the HR files). 4. Voice (browser speech first). 5. Image generation,
with patient-image rules. Each has its own design note, Daniel's look and the owner's ship-it.
