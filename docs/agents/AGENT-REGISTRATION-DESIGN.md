# Registering the agents on a real server: design note (AG-36)

Status: **design only. Nothing here is built or authorised.** Written by the Agent platform team (Irfan, Shalini) on 2026-11-21 for
the owner's answers, after the owner said "yes" to a small feature so the agents can be registered on the live server.
No code, migration, permission or dependency is added by this note.

## 1. The problem, in plain words

The six agents of the contract (CONTRACT section 8) are created only by `KPOneAgentSeeder`, and that seeder refuses to run
outside `local` and `testing` (it uses fictional names and was never meant for a real server). So on the trial server the
agent office, the approval inbox, the daily summary and the Director's quick answers are empty: there is no agent row for
them to show or for a run to belong to. A migration cannot do it either, because on a fresh database the organisation does
not exist yet when migrations run.

## 2. Proposal: one Artisan command

`php artisan kpone:register-agents` (name for the owner to confirm), run by the person who looks after the server.

- **Idempotent.** It fills in what is missing and never changes what exists. Run twice, the second run says "nothing to do".
- **Registers** the agents of the contract with the same names, titles, departments, kinds and **tool allowlists** that the
  seeder uses (one list, shared, so the two cannot drift: the seeder is changed to read it).
- **Safe in production.** It needs no `.env` change, writes no demo data, touches no other table than `ai_agents` and
  `ai_agent_tools`, and runs in one transaction with a row lock on the organisation.
- **Never changes an existing agent**: its code, name, title, kind, enabled state and lock version stay as they are. A tool
  missing from an existing agent's allowlist is added only with `--add-missing-tools` (shown in the dry run first); a tool is
  never removed by this command.
- **`--dry-run`** prints what it would create and changes nothing. It is the default shown in the help text; the real run needs
  `--force` in production so it cannot be run by accident.
- **Audited.** One structural audit row, `ai_agents.registered`, holds the agent codes and counts, never a person's data.
- **Adds no permission, no route and no screen, and makes no AI service call.** KPOne still calls nothing.
- **Enabled state:** every new agent is created **disabled** unless the run is given `--enable`. The Director then turns each
  agent on from `/agents` (that page, the lock version and its audit row already exist). Question 1 below.

## 3. Which agents

The contract's six: Dato' Arif (director assistant, quick answers), Hana (inventory analyst), Rizal (stocktake officer),
Lina (report assistant), Faris (technical admin agent, system health) and Nadia (QA reviewer, a `build` agent with no tools).
The "Claude connector" agent (CN-1) registers itself on first use and is not part of this command.

**Names.** The seeder's names are fictional names chosen for the contract. Question 3 asks whether the owner wants them on the
live server as they are, or different names.

## 4. What stays the same

- An agent that is disabled refuses every run (existing rule), so registering them changes nothing until someone enables one.
- The daily 07:00 and 14:00 runs, the proposals and "approve prepares work" (`agents.prepare_enabled`, off) are unchanged.
- No agent changes clinic data; approvals record a decision only unless the owner turns the prepare switch on.

## 5. Tests (for the build)

Creates exactly the contract's agents and allowlists on an empty organisation; second run changes nothing; an existing agent
keeps its code, title, enabled state and lock version; new agents are disabled unless `--enable`; `--dry-run` writes nothing;
production needs `--force`; one audit row, no personal data; refuses when the organisation is missing or ambiguous; the seeder
and the command share one list (a test fails if they differ); the allowlists only name known data sources.

## 6. Questions for the owner (plain words)

1. **Enabled or disabled after registering?** Recommended: **disabled**, and the Director switches each one on from the Agents
   page one at a time. Or enabled at once with `--enable`.
2. **Which agents?** Recommended: all six. Or only some (for example, only Dato' Arif and Lina first).
3. **Names.** Keep the contract names (Dato' Arif, Hana, Rizal, Lina, Faris, Nadia) or choose different ones? Titles always end
   in "(AI agent)".
4. **Who runs it?** The person who manages the trial server, once, after deploying. Who is that (a name for the record)?
5. **Adding tools later.** When a later slice adds a tool to an agent, may the same command add it with `--add-missing-tools`
   (recommended), or should the Director do that from the Agents page?
6. **Data.** Confirm: the trial server holds fictional data only, so agents may be enabled there; nothing here approves real
   patient data (the privacy review still comes first).

## 7. Build (after the owner's answers)

One small slice: the command, the shared list (the seeder reads it), tests, a short owner guide, CONTRACT section 8 note. No
screen, so no design look is needed. Independent review, then the owner's ship-it.
