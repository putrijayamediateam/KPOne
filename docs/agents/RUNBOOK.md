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

## If a section failed

Open the Daily summary page for that date: a failed section says so and is retried by the next scheduled call. To retry
sooner, run `agents:daily-run <slot> --branch=<CODE>` (it skips what is already stored). Faris runs only without `--branch`.
