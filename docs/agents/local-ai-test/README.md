# Local AI test (Option A): the 2-week free test kit

Status: **a test, not a feature. It authorises nothing.** Owner request relayed 2026-11-15 ("start local AI test").
Background and the questions behind it: [LOCAL-AI-ANALYSIS.md](../LOCAL-AI-ANALYSIS.md) (branch `docs/local-ai-analysis`).

## Limits that hold for the whole test

- **Invented data only.** Nothing from KPOne's database, no real patient, staff or clinic record. The 30 test items in
  `items.json` are invented; the three guide passages are quoted from the draft Malay guides in `docs/guides/ms/`.
- **KPOne calls no AI service.** Nothing goes into KPOne's code, routes, config, `.env` or database. The test runs on a
  separate computer and the only program that talks to the model is `run_test.py` on that same computer.
- **No paid API, no credits, no new dependency in the repository.** The models are free downloads; `run_test.py` uses only
  Python's standard library.
- The result is a **short report to the owner** before anything is built.

## The kit (3 files)

`README.md` (this page), `items.json` (the 30 invented test items), `run_test.py` (the runner).

## Steps for the owner or the IT person (about 2 hours of their time, plus waiting)

| # | Step | Where | Time |
|---|---|---|---|
| 1 | Pick the computer: a **spare** Windows, Mac or Linux PC or laptop, not a clinic server and not a staff PC. At least 16 GB RAM (32 GB is better), 30 GB free disk. Note its make, CPU, RAM and graphics card (if any). | the test computer | 10 min |
| 2 | Install **Ollama** (free, from ollama.com; the Windows, Mac or Linux installer). | the test computer | 10 min |
| 3 | Download the models. In a terminal or command window: `ollama pull qwen3:8b`, `ollama pull qwen3:14b`, `ollama pull sailor2:8b` (about 5 to 10 GB each; skip `qwen3:14b` if the computer has less than 24 GB RAM). Read each model's page on ollama.com for its licence before the report (Qwen3 and Sailor2 are reported as Apache 2.0; check). | the test computer | 30 to 90 min (download) |
| 4 | Check Python 3 is installed (`python --version`); if not, install it from python.org. | the test computer | 5 min |
| 5 | Copy the three kit files into one folder, open a terminal in it. | the test computer | 5 min |
| 6 | Quick check: `python run_test.py --quick --models qwen3:8b` (6 items). It should print six lines with seconds and tok/s. | the test computer | 5 to 15 min |
| 7 | Full run: `python run_test.py`. It runs all 30 items on each model and writes `results.jsonl` and `results.csv`. Leave the computer alone; do not use it for real work meanwhile. | the test computer | 10 to 40 min per model with a graphics card, 1 to 3 hours per model without one |
| 8 | Send back `results.jsonl` and `results.csv` (invented data only) and the computer's specs. | any | 5 min |

If a step fails, send the error text. Do not try other tools.

## What is measured

**Quality** (30 items per model; English and Bahasa Melayu both):
- A, 8 items: plain-words paragraph from daily numbers. The runner checks that the key numbers appear and lists any number
  that is not in the data (a derived figure such as "7 more" is allowed; an invented one is a fail). Two items have a missing
  figure and one has a zero day: the model must say "not available" and not guess.
- B, 8 items: read a small table and answer (highest branch, totals, a difference). Checked automatically.
- C, 8 items: answer from a passage of the Malay guide and quote it; two have no answer in the passage, so the model must say
  it could not find it. Checked automatically for the key phrase; a person checks the quote.
- D, 6 items: draft a short staff memo from notes (no names). A person scores it.

A person who reads Malay and English fills the three score columns in `results.csv` (0 = wrong, 1 = partly right, 2 = right):
`human_faithful` (nothing invented, matches the data or notes), `human_language` (natural, correct language) and
`human_useful` (something staff could use after light editing). Expect about 2 to 3 hours for 90 answers.

**Speed:** seconds per answer, the model's load time and tokens per second (a token is about three quarters of a word;
about 10 tokens a second reads at a comfortable pace).

**Memory:** the output of `ollama ps` while a model is loaded (the runner records it in `results.jsonl`), plus the computer's
RAM and graphics card memory.

**Reliability:** errors and timeouts in the run.

## Decision rule, agreed in advance

A model is **good enough to try Option B** (one box, the Daily summary paragraph only) if, on its own scores:
- no invented number in at least 7 of the 8 summaries, and the two missing-figure items say "not available";
- at least 7 of 8 table answers right;
- at least 6 of 8 guide answers right, with both "not in the passage" items refused honestly;
- an average of at least 1.5 out of 2 on the Malay memos and Malay summaries;
- at least 5 tokens a second on the test computer (the work runs in the background, so slower is acceptable).

If no model passes, the report says so and the recommendation is **not to build anything**. A pass means "worth a pilot",
never "approved": the owner's decision comes first.

## Timeline

| When | What |
|---|---|
| By Wed 2026-11-18 | Kit available; IT person does steps 1 to 6 |
| By Sun 2026-11-22 | Steps 7 and 8 done; results sent back |
| Mon 2026-11-23 to Fri 2026-11-27 | Scoring by a Malay and English reader; analysis |
| **Sun 2026-11-29** | Short report to the owner (two pages: scores, speed, memory, licence notes, recommendation) |

The two weeks start when the IT person begins step 1; if that starts later than 2026-11-18, every date moves by the same
number of days.

## Privacy and safety notes

- `results.csv` and `results.jsonl` hold the invented inputs' answers only; safe to send.
- Do not paste anything real into the kit files or into the model. If someone wants to try their own question, use invented text.
- The model can be wrong or invent things; that is what the test measures. Nothing here is advice.
