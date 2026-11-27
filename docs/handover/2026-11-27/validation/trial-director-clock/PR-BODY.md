The TrialDataCommandTest clock cases can travel to the previous Kuala Lumpur day after setUp provisions the Director. The primary Cheras fixture began today, so Phase1 correctly refused before the Phase5 test reached its target: “The Director has no current assignment at CHERAS.” Secondary branch fixtures already began yesterday.

Align the synthetic primary assignment's start date with those fixtures. Production bootstrap, assignment eligibility and trial-data guards are unchanged; all existing assertions remain intact. Add regressions proving Phase1 succeeds on the prior branch day with valid fixture coverage and still refuses a future-dated assignment without writing data.

Validation: actual before-fix command refusal captured on an isolated synthetic database; focused 3 tests/48 assertions, PHP syntax and Pint pass. Full TrialDataCommandTest passes all 24 tests/564 assertions, including all four midnight provider cases and the original Phase5 success case.
