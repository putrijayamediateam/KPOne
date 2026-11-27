# Treatment-call extraction independent review

Clear at13-file TREATMENT-CALL-CURRENT-MAIN-v1.patch SHA2564411a15df92145e7f5f03690391747798c9731fc3a5ef4143f313677f210f9e2 on maineec5d5444bd14b0358e0383c1ea5d427e4d33f11. Every manifest file independently hash-verified.

Reviewed source reconciliation: narrow already-approved treatment-call permission is added to route/service authority and dedicated UI projection, preserving existing doctor Call/Recall behavior. Held Serving announcement is allowed only by that narrow authority; existing Visit/queue version and branch/user/room guards remain under lock. No new grant or migration. phpunit union registers the Resume race in Contention/Contention3 and excludes Feature, preserving upstream entries. Added test/worker files are present in the13-file manifest.

Independently viewed actual MA modal390dark and1440light. Action wording correctly says consultation stays with doctor, target room selection and Cancel/Call remain distinct/readable, no visual blocker. Builder nine browser checks/six captures include390/768/1440 target44/44/36 and doctor-held action omission. Read state-proof: assignment, visit/encounter versions and hold unchanged; still Registered/Serving, exactly one additional announcement, correct room/actor. These are builder runtime assertions, not an independent DB rerun.

Builder final gates15/230 ordinary,1/14 race,2207 full frontend, types/build/static/lint/format reported pass. Prior integration source review remains consistent; this new note binds the current-main extraction. No merge, automatic resume, clinical state transition or full Queue/demo-parity claim.
