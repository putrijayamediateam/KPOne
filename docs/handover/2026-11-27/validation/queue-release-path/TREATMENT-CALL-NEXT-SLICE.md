# Next blocking extraction: Treatment call

After AUTO1c publication, prepare a separate main-based branch from actual current main; no need to inherit the archive or unrelated warning/room summaries. Sources: frozen Treatment-call backend7ae6ec1e…, UI overlaya87cb121…, local dialog hitbox overlay. Read actual main and merge shared hooks by hunk.

Scope: RoomCallService held exception under existing narrow permission; existing route permission list; dedicated QueueDirectory canCallTreatment + treatment-room visibility; QueueController/QueueIndex/types consumer; local QueueCallDialog44/36 hitboxes; new authority/projection/mapper tests and registered actual Resume-vs-call race. phpunit.xml must retain all existing Contention entries/shards (including other teams). TEAM append only. No migration, role grant, existing assertion change or treatment-record implementation is part of this extraction.

Gates: scoped Feature/Unit including ContentionSuiteTest, registered Contention both observed lock orders on own database, static/lint/format/types/build, actual authorized MA-held dialog/call and unchanged hold proof in isolated synthetic preview. Independent source+visual review before draft publication. No clinical state clearing, production activation or archive merge.
