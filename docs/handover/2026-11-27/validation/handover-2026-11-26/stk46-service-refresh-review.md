# Independent STK46 service refresh review

Read-only review of /workspace/KPOne-stk46 staged normal-main merge,26November2026. Main-relative scope remains17 STK46 files. Compared all17 against the pre-refresh HEAD: every file except phpunit.xml remains byte-identical, including approved schema/grant migrations, HandCostService/model, permission additions and tests. No conflict markers/unmerged entries.

Parsed phpunit.xml from main and candidate. Removing only PostgresHandCostRaceTest from candidate reproduces every upstream suite's ordered entries exactly. Candidate adds precisely the Feature exclusion plus Contention and Contention3 registration. No upstream race registration was dropped, replaced or duplicated by this merge.

No bounded source-parity blocker. This is an independent merge review, not a rerun of the author's focused/static/contention gates and not merge authorization. Author remains responsible for exact final tests and normal push; coordinator owns release.
