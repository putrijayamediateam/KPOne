Staff who already hold the approved treatment-call permission can announce a held Serving patient to an active treatment room from the existing Queue action. The consultation remains with its doctor and the clinical hold stays intact. Existing doctor Call In/Recall authority does not gain this held exception.

This is a main-based extraction of the reviewed Treatment-call adapter, dedicated canCallTreatment projection, existing dialog consumer and local36/44px controls. It preserves actor/branch checks, Visit/queue locks and versions, announcement cooldown, retry behavior and structural audit. No new permission grant, schema or existing assertion change. Archive #1142 is not an ancestor.

Validation on refreshed main eec5d5444: focused Feature/Unit15 tests/230 assertions; registered real PostgreSQL Resume-vs-call race1/14 covering both observed lock orders; all2,207 frontend tests; static/lint/format/types/build passed. The race is in Contention and shard3 with Feature exclusion, preserving all existing suite entries.

Actual synthetic MA browser proof:9 checks/6captures at390/768/1440 light/dark,44/44/36px controls, Cancel without request and one authorized announcement. Database proof verifies the same assignment, visit/encounter versions and active hold, Registered/Serving state, and correct room/actor. Ordinary doctor held action stays absent. Independent extraction review accompanies the immutable13-file patch.

This draft does not include treatment outcome recording or the full Queue makeover. No merge or production activation requested.
