# AI agents: operator runbook

Short rules for whoever runs KPOne's agent commands. KPOne calls no AI service; every command below reads aggregate
data and writes only agent runs, suggestions and daily summary sections. Nothing here changes clinic data or sends a message.

## The scheduled runs

- `agents:daily-run 0700` (full: Lina's report for yesterday, Hana's four checks, Rizal's stock count check, Faris's
  system health) and `agents:daily-run 1400` (update: Lina's today so far, Hana's restock and expiry checks, Faris).
- Each branch runs in its own time zone, only once its local clock has reached the slot, so the scheduler may call it
  every hour. A section already stored is skipped; a failed attempt is retried on the next call, up to three runs.
- It needs a **direct or session-pooled database connection** (a transaction-pooled connection drops the session locks it
  uses) and must not be called inside an outer transaction.
- The daily run holds a PostgreSQL advisory lock per organisation, branch, local date and section, so two scheduled
  processes cannot store the same section at once.

## Running Hana by hand

`agents:inventory-review <BRANCH>` takes the same advisory lock the daily run uses for Hana (organisation, branch and
local date). If a daily run, or another manual run, is working on that branch and date, the command prints "Another run
is working on Hana for <BRANCH> on <date>. Nothing was changed; try again in a few minutes." and exits with a failure
code. Nothing is stored. Run it again a few minutes later.

It needs the same direct or session-pooled database connection and no outer transaction as the daily run. An identical
suggestion is still safe to repeat (the same key and payload return the existing one).

The earlier interim rule (no manual run in the hour of 07:00 or 14:00 branch time) is retired by this change.

## Who decides agent suggestions at the pilot branch

Owner decision (relayed by the coordinator, 2026-11-15): at the pilot branch the **two named CA Supervisors and the
branch Director** do the two-person steps, including deciding agent suggestions (approve or reject in the Approval inbox).

- The person who started the run that made a suggestion cannot decide it; the inbox says "You started the run that made
  this suggestion, so someone else must decide." This is why a second person is needed.
- A decider must be assigned to that branch now and hold the decide permission for the suggestion; otherwise the inbox
  shows why they cannot decide.
- An approval records the decision only. It changes no stock and sends nothing; the restock, expiry or transfer is still
  done by hand through the normal stock screens. (CONTRACT section 15 plans approval that can also prepare a stock
  request or purchase request, behind the switch `agents.prepare_enabled`, which stays off until the owner turns it on.)
- **Week one of the pilot (owner decision, relayed by the coordinator, 2026-11-15):** agents are on, with the **Daily
  summary only**, so staff learn it without relying on it. Do not tell branch staff to base stock or money decisions on
  it. The scheduled run proof (the 07:00 and 14:00 runs on the pilot server) is a pilot item.

## If a section failed

Open the Daily summary page for that date: a failed section says so and is retried by the next scheduled call. To retry
sooner, run `agents:daily-run <slot> --branch=<CODE>` (it skips what is already stored). Faris runs only without `--branch`.
