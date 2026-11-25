# REL-AG: release note for the agent platform's data rule

Status: **the owner answered the three open questions on 25 Nov 2026 (section 5). Documentation only. No code, configuration or
switch is changed by this note.** The section 3 wording changes are a separate follow-up docs PR.

## 1. The owner's decision

On 25 November 2026 the owner reviewed the PDPA and lawyer review of Ask KPOne and said "dh approve".

Recorded as: **owner-confirmed, verbal, 25 Nov 2026.** The approval was given verbally in the coordinator's session and relayed
to the agent platform team. The owner keeps the written copy (section 5). This note is not legal advice and does not replace the review itself.

## 2. What it covers

"Ask KPOne" here means every path by which the Director asks a question about KPOne and an AI answers it:

| Path | What it is | Where the notes are today |
|---|---|---|
| Ask KPOne, Option A | The owner asks Claude Code on the server, through the masked read-only `ai_read` schema | CONTRACT section 13 ("Privacy"), TEAM.md |
| The KPOne connector (CN-1) | The owner's own Claude app calls KPOne's read-only totals tools | CONTRACT section 16, MCP-CONNECTOR-DESIGN section 8, CONNECTOR-OWNER-GUIDE, CONNECTOR-TRIAL-CHECKLIST |
| CHAT-1, the Director chatbox | A chat in the Director overview, answered by a provider model | CONTRACT sections 7 (the key and the guard) and 17, AI-CHATBOX-DESIGN sections 1, 8 and the table |
| CHAT-2, Ask through Claude | The Director's own Claude answers and saves the answer back | CONTRACT section 18 |

## 3. The notes that say "synthetic data only" or "needs PDPA review"

These are the lines to change, with what each should become once the owner approves this note. The wording is a proposal.

| Where | Today | Should become |
|---|---|---|
| CONTRACT section 13, "Privacy" | All data is synthetic today; before real patient data is in KPOne, this path needs the PDPA and legal review. | The PDPA and legal review is approved (owner-confirmed, verbal, 25 Nov 2026). Keep the plain statement that query results reach Anthropic as part of the owner's Claude use, and keep the masked `ai_read` schema. |
| TEAM.md, Ask KPOne Option A | Needs PDPA and legal review before real patient data. | Review approved (owner-confirmed, verbal, 25 Nov 2026). Written confirmation still to be filed. |
| CONTRACT section 7 and AI-CHATBOX-DESIGN (chatbox) | Off until the owner turns it on, only on a server with fictional data until the PDPA review; "synthetic data only until the PDPA review". | The review no longer blocks it. It stays off by default (`AGENTS_CHAT_ENABLED=false`) and is switched on only by the owner. The key stays in the server's environment secrets. The "no names, no identity numbers" rule in the question box stays. |
| CONTRACT section 18 and the CHAT-2 panel (Ask through Claude) | Inherits the connector's data rule. | Same as the connector (next row). |
| CONTRACT section 16, MCP-CONNECTOR-DESIGN section 8, CONNECTOR-OWNER-GUIDE, CONNECTOR-TRIAL-CHECKLIST (connector) | Fictional data only until the PDPA review; stays off on any server with real patient data until the owner says so **in writing**. | The review is approved (owner-confirmed, verbal, 25 Nov 2026), **but the connector keeps its own switch**: it stays off on a server with real patient data until the owner gives a written OK, unless the owner says otherwise. See section 4. |

What does not change under any reading of the approval:

- Tools return totals only, never patient names, identity numbers, contact details or clinical text (the aggregate guard stays).
- Answers from Claude are labelled "Answered by Claude. KPOne did not check this answer."
- KPOne calls no AI service from application code (`NoAiServiceGuardTest`); the chatbox provider is the one allowed exception.
- Small counts on a quiet day can still point at a person. The connector guides keep saying so.

## 4. The connector and real data

The owner's approval covers the PDPA and lawyer review. It was given verbally. For the connector the rule stays as written in
MCP-CONNECTOR-DESIGN section 8: **the connector is off by default and is not switched on against real patient data until the
owner gives a separate written OK at switch-on** (owner answer 2, section 5). A written OK means a sentence from the owner on record (for example
in the coordinator's log), naming the server and the date.

Switching it on stays the existing, reversible step: `AGENTS_CONNECTOR_ENABLED=true` on that server, one connector app, scope
`mcp:use` (and `mcp:answer` for Ask through Claude). Switching it off revokes nothing already shown but stops all new calls.

## 5. The owner's answers (25 Nov 2026)

Given in the coordinator's session and relayed here. Recorded in the owner's words, with the meaning:

1. "Ya cukup": the verbal approval is enough for Ask KPOne Option A and the chatbox, with the written copy filed later.
2. "Ya, ayat berasingan": the connector needs a **separate written OK** from the owner at switch-on on a server with real patient
   data. The OK names the server and the date. The verbal approval of 25 Nov does not replace it.
3. "Saya simpan, B.E.R.N.I.S.S rekod": the owner keeps the written copy of the PDPA and lawyer review (the clinic's Google Drive).
   The coordinator records its date and reference in `docs/BASELINE.md`. Teams do not edit `docs/BASELINE.md`.

## 6. Next step

A follow-up documentation PR makes the wording changes in section 3 and nothing else. No migration, permission, route or switch
changes. The coordinator records the approval, the date and the written copy's reference in `docs/BASELINE.md`.
