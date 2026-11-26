# TRI2 emergency lane backend recovery

This scoped layer follows lifecycle #1182, keeping Family #1148 and the compatible shared client as release prerequisites. Archive #1142 is never merged. No migration, grant, activation command or clinical transition is added.

The lane independently queries authorized red Waiting consultations for the active branch, including carry-over and retained not-ready flags. It never derives membership from an ordinary loaded page or search filter. It counts the complete scoped set, returns25 per page, clamps after shrinkage and excludes the complete scoped emergency set from ordinary rows/counts, including emergencies not on the selected emergency page. Queue and Registration reuse their existing authorized row mappers and batched hydration for Call In, readiness and version metadata.

Emergency response authority alone never grants identity access. Queue own scope only includes the assigned doctor's rows. Existing Registration branch identity scope may still show another doctor's ordinary patient identity; that does not expose the other queue/triage/alert/action metadata. A dedicated regression preserves this baseline distinction rather than hiding otherwise authorized patients or broadening the emergency lane.

## Reconciliation and approvals

Original approved lane patch97af5b9, branch-clock followupdcdf21e and Registration-scope regression3d0d8e5 are recovered. VisitDirectory is reconciled by hunk because AUTO-1c #1164 is a separate dependency and is not installed on this stack. No AUTO or Family source is copied incidentally. When those dependencies converge, preserve all-visit (ordinary plus emergency) warning hydration from the original reviewed integration. The new privacy regression accepts absent or null for the optional AUTO warning hint while all existing queue/triage metadata fields remain strict.

The owner's approved changes are retained: red moves to emergency payload, branch-authority query bound3, Queue query ceilings24/21/21, Pilot32, Registration20/Queue18 and additive emergency pagination metadata. This base measures Queue GET23 because the separate AUTO extraGET read is absent; the approved24 bound accommodates that dependency. Other less-than ceilings stay intact.

The exact deferred lane-total assertion from lifecycle is restored and executed here: all26 red Waiting patients remain in the lane after25 Takes while the bounded global alert response keeps the untaken emergency. The NEW1-versus26 query comparison additionally covers both full Queue and Registration hydration, not only the standalone lane projection.

The approved CombinedQueueSummaryTest adaptation is not applied here because that new integrated test and its Q16d/e endpoints are absent from this parent. It remains in the immutable original aggregate assertion artifact for the later combined Queue recovery. This is not a claim that combined stats/UI integration has passed.

No frontend changes are part of this layer. The compatible lane UI must consume `emergency_page`, preserve independent ordinary filters/pages, render all pagination totals/actions and maintain original draft-safe cancellation before release. Current focused query/privacy/order gates and independent source review accompany the frozen artifact.
