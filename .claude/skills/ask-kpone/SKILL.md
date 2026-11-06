---
name: ask-kpone
description: Answer the owner's questions about the data in KPOne (patients, visits, queue, consultations, dispensary, stock, billing, payments, Panel claims, staff) by reading the masked ai_read views through `php artisan kpone:read`. Use when the owner asks "how many", "which", "show me" or any other question about what is in KPOne.
---

# Ask KPOne

You answer the owner's questions about KPOne's data. You read it only through two read-only Artisan commands
over the masked schema `ai_read` (agents contract section 13).

## Rules

- Use only `php artisan kpone:read-schema` and `php artisan kpone:read "<one SELECT>"`. Never open, print or
  edit `.env`, never connect to the database any other way (no `psql`, `tinker`, `DB::`, other Artisan
  commands or files under `storage/`), and never change any data.
- Start with `php artisan kpone:read-schema` to see the views and columns. Write one `SELECT` or `WITH`
  statement per call, using only those views. Comments, semicolons between statements, double quotes,
  backslashes and system functions are refused. Give every result column its own name (`b.id AS branch_id`);
  two columns with the same name are refused.
- Output is capped at 500 rows. Prefer counts, sums and `GROUP BY` over listing rows. If the command says more
  rows exist, narrow the query rather than paging through people.
- Take every number in your answer from the command output. Do not estimate or fill gaps; if the views cannot
  answer the question, say so and say what is missing.
- Amounts ending in `_sen` are in sen: divide by 100 and show RM with two decimals. Timestamps are UTC; dates
  such as `operational_date` are the branch's local day (Asia/Kuala_Lumpur).
- Patients appear only as KP number, initials and the last 4 characters of their IC or passport. Do not try to
  work out who a patient is. If the owner needs the real details, give the KP number and tell them to open the
  patient in KPOne, where the normal permissions and audit apply.
- Staff appear by display name and roles only.
- KPOne holds synthetic data today; say so when you answer. Every query is audited (a hash of the query and the
  row count).

## Answering

Explain the answer in plain English first, then the figures (a short table is fine), then the query you ran so
the owner can repeat it. Keep patient-level detail to what the question needs.

Stock cost (`ai_read.stock_movements`): call a cost actual only when `cost_source` is `actual`. When it is
`estimate`, say it is the purchase order's estimate. When it is empty, say so plainly: on a purchase receipt it was
recorded before costs were labelled and its origin is unknown; on any other movement (for example an opening balance)
it is the unit cost staff entered. `cost_total_sen` is a whole lot's total, not a unit cost: never multiply it by the
quantity.
