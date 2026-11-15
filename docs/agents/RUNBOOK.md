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

## Running Hana by hand: the interim rule

**Do not run `agents:inventory-review` by hand in the hour of a slot, 07:00 or 14:00 branch time** (for example 07:00 to
07:59 for a branch whose local clock is at 07:xx).

Why: the manual command takes no lock. If it runs while the daily run is working on the same branch, both can read the
same stock and choose different branches to send a transfer, giving two overlapping suggestions. A repeated identical
suggestion is safe (the same key and payload return the existing one), so the risk is only that case. Outside the hour
of a slot the manual command is safe to run.

The small fix (take the daily run's lock, `DailyRun::lockKey(..., 'hana')`, in the command and skip with a message when it
is held) is planned as its own change. Until it merges, keep the rule above. Source: agent platform TEAM.md, open item 13.

## Who decides agent suggestions at the pilot branch

Owner decision (relayed by the coordinator, 2026-11-15): at the pilot branch the **two named CA Supervisors and the
branch Director** do the two-person steps, including deciding agent suggestions (approve or reject in the Approval inbox).

- The person who started the run that made a suggestion cannot decide it; the inbox says "You started the run that made
  this suggestion, so someone else must decide." This is why a second person is needed.
- A decider must be assigned to that branch now and hold the decide permission for the suggestion; otherwise the inbox
  shows why they cannot decide.
- An approval records the decision only. It changes no stock and sends nothing; the restock, expiry or transfer is still
  done by hand through the normal stock screens.
- **Not decided yet:** whether agents are switched on in week one of the pilot. Until the owner decides, do not tell
  branch staff to rely on them.

## If a section failed

Open the Daily summary page for that date: a failed section says so and is retried by the next scheduled call. To retry
sooner, run `agents:daily-run <slot> --branch=<CODE>` (it skips what is already stored). Faris runs only without `--branch`.
