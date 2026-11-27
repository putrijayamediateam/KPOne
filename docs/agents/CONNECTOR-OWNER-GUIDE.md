# KPOne connector: owner guide

For the owner. Plain words. Read this before you add KPOne to your Claude app.

**What it is.** A small, read-only door into KPOne. After you add it once, you can ask Claude things such as "how many
patients today at CHERAS, including the unfinished ones?" or "how many Panel claims were made today across the three
branches?". Claude asks KPOne, KPOne answers with totals only, and Claude writes the reply.

**What it can see:** counts and amounts for the branches you work at: clinic day, money day, sales, Panel claims, stock
alerts and balances, the queue right now, visits by state and a few system-health counts.

**What it can never see:** patient or staff names, contact details, identity numbers, clinical notes, diagnoses, or
anything staff typed about a person. It cannot change anything in KPOne. KPOne sends nothing to Claude by itself and calls
no AI service.

**The data rule.** The answers reach your Claude account, and so Anthropic, as part of your own use of Claude. Small
counts on a quiet day can still point at a person. The PDPA and lawyer review is approved (owner-confirmed, verbal, 25 Nov 2026; you keep the written copy). **On a server with real patient data the switch stays off until you give a separate written OK, naming that server and the date.** This is not legal
advice.

## Who can use it

Only the Director (permission `ai_connector.use.organisation`). Every other role is refused. If the Director's account is
deactivated or loses the permission, the connector stops for that person at once.

## Add it to Claude (once)

1. The person who manages the server turns the switch on (`AGENTS_CONNECTOR_ENABLED=true`) on the **trial** server, and
   tells you the connector address, for example `https://<trial-server>/mcp`.
2. In Claude (claude.ai or the phone app) open Settings, then Connectors (the menu names may differ a little). Choose to
   add a custom connector and enter that address. Whether you can add one depends on your Claude plan, and on a team plan an
   administrator may have to add it.
3. Claude opens the KPOne sign-in page. Sign in as the Director. Read the screen, check you started this yourself, and
   press **Allow**.
4. In a conversation, make sure the KPOne connector is switched on, then ask your question. Claude shows which KPOne
   question it used.

Each sign-in lasts about 1 hour and Claude renews it by itself while you use it. After 30 days without use it stops.

## What KPOne records

Every question is recorded in the audit log: which question, which branch, which dates, the outcome and how many rows. Never
the figures. There are limits of 30 questions a minute and 500 a day. The connector also shows up on the **AI agents**
page as "Claude connector (AI agent)": the Director can switch that agent off there to refuse every question at once.

## How to stop it

- **Connected apps** (KPOne, AI agents, then the **Connected apps** button): shows when Claude was connected and when it
  last asked, and has a **Disconnect** button. Claude may still list KPOne as connected until it next asks.
- Or disconnect KPOne inside the Claude app.
- Or ask the person who manages the server to switch the connector off (`AGENTS_CONNECTOR_ENABLED=false`). Every address
  then answers "not found" and no token works.
- To end every connection at once, that person runs `php artisan agents:connector-revoke` (add `--user=<email>` for one
  person).

## What to check when you try it

- The numbers match the KPOne screen they come from (the team tested each question against its report page; Claude, not
  KPOne, writes the sentence, so read the "definitions" Claude quotes).
- Claude says "not available" or "refused" for a branch you do not work at.
- After **Disconnect**, a new question in Claude fails until you connect again.
