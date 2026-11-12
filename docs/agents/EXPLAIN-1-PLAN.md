# EXPLAIN-1 plan: Queue and Front desk explainers

Status: **plan only, nothing built.** Owner-authorised direction (2026-11-12: "yes explainers, start with queue front desk"). No AI service: fixed rules and sentence templates, like Hana and Rizal. Written by the Agent platform team for review by Queue & TV (Amirul, owner of the queue data) and Staff Experience (Kavitha, owner of the Front desk space and Ask panel).

## 1. Where it lives

The Ask panel's existing path: `SpaceQuestions::REGISTRY` plus `SpaceAskService` (`POST /spaces/front_desk/ask`, throttled 60 a minute, structural audit row `space_ask.answered`). Not `AgentToolGateway`: that path needs an agent run, and these questions are asked by a person in the Front desk space. If Lina later wants the same figures in the daily summary, the calculator below can be wrapped as a data source then.

Files (all new unless named):
- `app/Domain/DepartmentSpace/Explain/QueueWaitData.php`: one read-only fetch (permission and branch checked by the caller).
- `app/Domain/DepartmentSpace/Explain/WaitAnalysis.php`: pure functions on arrays (no database), where every rule below lives.
- `app/Domain/DepartmentSpace/Explain/FrontDeskExplainers.php`: the four builders and the sentence templates.
- **Edits to existing files, needing the owners' OK:** `SpaceQuestions.php` (four registry rows, Kavitha), `SpaceAskService.php` (four builder dispatches, the `FULL_LIST` href and the `allowed()` match, Kavitha). No Vue change: the panel already renders a sentence and a small table.
- **One existing assertion changes:** `SpaceQuestionsRegistryTest::test_the_registry_is_exactly_the_agreed_first_set` lists every key, so it gains four rows. Needs the owner's approval. All four keep `date: now` and `patientLevel: false`, so the second registry test is unchanged.

## 2. Access and privacy

- Permission: `visits.view.branch` (the other Front desk questions) **and** `queue.view.branch`, both checked on every call; the active branch comes from `BranchAccessService::activeBranch` and is the only branch read. A question is absent from the panel for anyone without both, and a direct call returns 403. No new permission.
- Aggregates only. The query selects times, `priority`, the assigned doctor's id and the queue status. It never selects names, IC numbers, queue numbers or visit ids into the answer. Doctors are counted, never named.
- The answer table holds only day and part-of-day labels and numbers.

## 3. Common rules

- Time zone: the branch's own. Day parts: morning (before 12:00), afternoon (12:00 to 16:59), evening (17:00 onward).
- Wait = `called_at - queued_at` for consultation queue entries that were called. Entries carried over from a previous day (queued on an earlier local date than their operational date) are left out and counted in a footnote, because their wait is not comparable.
- Averages are plain means in whole minutes, said as "average". Every sentence states its periods and sample sizes.
- **Minimum samples (constants, one place):** a period needs at least 20 called entries; a day-part cell needs at least 8 on each side to be named; fewer: "Not enough data to explain this (N patients this week, M last week)." A question never guesses.
- Drivers are worded "coincided with", never "caused by". Only two facts are checked per cell: arrivals per day and doctors who called at least one patient (per day, in that day part). A fact is stated only if it differs by 20% or more (arrivals) or by one doctor or more (doctors). If no fact differs, the sentence says "No change in arrivals or doctors coincided with this."
- Entries used only if status is waiting, serving or removed with a recorded call; the status "removed without a call" is the "left before being called" group (section 4, question 4).

## 4. The four questions (phase 1)

A fifth candidate, "Which visit types take longest to be called?", waits: the consultation queue has only normal and urgent, and OTC has its own list. It needs an owner choice of grouping.

### Q1 `front_desk.waits_week` "What changed in waits this week?"
- **Periods:** this week so far (Monday 00:00 to now) against last week over the same weekdays and clock time (so on Wednesday 15:00 both sides cover Monday to Wednesday 15:00).
- **Logic:** overall average wait per period. Then, per (weekday, day part) cell, a contribution to the change: `share_this * wait_this - share_last * wait_last`, where share is the cell's called entries divided by the period total (the contributions add up to the overall change, exactly). Rank cells; name the top two whose contribution is at least 25% of the total change and which meet the cell minimum. Attach the two coinciding facts for each.
- **Sentence:** "Average wait was 14 min longer this week (31 min, 212 patients) than last week (17 min, 198 patients, same days). Biggest changes: Tue and Thu afternoons (+22 min and +19 min); these coincided with 2 doctors calling patients instead of 3 and 30% more arrivals. Mornings were about the same." If the change is under 3 minutes: "Waits were about the same (X min this week, Y min last week)."
- **Table:** Day and time, This week, Last week, Change, Doctors (this / last), Arrivals per day (this / last), at most 6 rows.

### Q2 `front_desk.waits_today` "Why is today slower or faster than usual?"
- **Periods:** today so far (opening to now) against the same weekday over the previous four weeks, cut at the same clock time (need at least 3 of those four weekdays with data, and at least 20 called entries in the baseline and 8 today).
- **Logic:** same decomposition by day part only (a single day has no weekday split). Facts: arrivals so far against the baseline average, doctors calling so far against the baseline average.
- **Sentence:** "Today's average wait is 12 min longer than a usual Tuesday at this time (28 min, 41 patients, against 16 min over the last 4 Tuesdays). The afternoon accounts for most of it, and 1 doctor is calling instead of the usual 2."

### Q3 `front_desk.busiest_hours` "When are our busiest hours, and are we staffed for them?"
- **Period:** the last 28 branch days before today; needs at least 5 days with arrivals and 50 entries.
- **Logic:** arrivals (`queued_at`) per local hour, averaged over days that had any arrivals. Top three hours. For each: average wait of entries queued in that hour and the average number of doctors who called at least one patient in that hour, and arrivals per doctor. "Staffed" is shown as facts, not a verdict: "Arrivals per doctor" next to the branch's overall figure.
- **Sentence:** "Busiest hours over the last 4 weeks: 10:00 (18 arrivals a day), 11:00 (16) and 15:00 (14). At 10:00 about 2 doctors called patients, 9 arrivals per doctor, against 6 across the day, and the average wait was 24 min."

### Q4 `front_desk.left_waiting` "How many patients left the queue before being called this week?"
- **Meaning:** queue entries whose status is removed, with no call recorded, so cancelled visits and removals while waiting. KPOne does not record why a person left, so the sentence says "removed or cancelled while waiting".
- **Periods:** this week so far against last week, same weekdays and clock time.
- **Logic:** counts, share of that period's entries, the median time they waited before removal, and the day part with the most. Name a peak only when it has at least 5 removals.
- **Sentence:** "11 patients were removed or cancelled while waiting this week (5% of 208) against 6 last week (3% of 196). Most (7) were in the afternoon. Half had waited over 24 min before they left." Fewer than 5 either side: counts only, with "too few to show a pattern".

## 5. Performance estimate

One query per question, `queue_entries` joined to `visits` for the branch and an operational date range (14 days for Q1, 28 for Q3, 5 weekdays for Q2): about 200 entries a day, so 3,000 to 6,000 rows, selecting about 6 narrow columns, then plain PHP grouping. Expected under 100 ms. Nothing is cached or stored; no new table, index or migration. A test asserts at most 3 queries per answer and runs one answer over a 6,000-row fixture. If measured slow, a day-level index on `(branch_id, operational_date)` is the first thing to propose, as its own change.

## 6. Tests

- **Unit, `WaitAnalysis`:** the decomposition adds up exactly to the overall change; the minimum-sample rules (each threshold at, one below); "about the same"; carry-over excluded; a driver stated only above its threshold and never as a cause; rounding; a branch midnight boundary (Kuala Lumpur 00:00 to 08:00) puts entries on the right local day; weeks start on Monday.
- **Feature, per question:** a 403 for every role in `PermissionCatalogue::roles()` without both permissions (matrix, like `AgentOfficePageTest`), a 200 for roles with both; 404 for an unknown key or the wrong space; only the active branch's entries count (another branch's entries change nothing); answers are identical for two permitted roles; one `space_ask.answered` audit row with the question key only.
- **Privacy:** fixtures use distinctive names and queue numbers; the whole JSON answer must contain none of them, no IC-like or phone-like text, and no patient id.
- **Registry:** the pinned registry test gains the four rows; a new test pins their labels, permissions and `patientLevel: false`.
- **Frontend:** the Ask panel offers the four questions to a CA and none to a role without the permissions; no existing assertion changed besides the registry test above.
- **Gate:** the full backend and frontend gate plus Pint, PHPStan, `git diff --check`.

## 7. Decisions needed

1. Which of the four to build in phase 1 (recommended: all four).
2. Whether both permissions are the right gate (recommended), or `visits.view.branch` alone.
3. The thresholds in section 3 (20 and 8 called entries, 25% contribution, 20% arrivals, one doctor) and the day-part boundaries.
4. Kavitha's and Amirul's OK to add the registry rows, the three new files and the one test update.
5. Whether to wait for the grouping of "visit types" (the fifth question).
