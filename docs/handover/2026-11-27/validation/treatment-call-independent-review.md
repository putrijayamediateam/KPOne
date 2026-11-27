# Treatment-call authority adapter independent review

Reviewed RoomCallService + treatment-call route diff and new authority/race coverage in /workspace/KPOne-treatment-call. No scoped source blocker identified. Already-approved narrow queue.treatment_call.branch authorises only treatment announcements, including held consultations; existing queue.call own/branch holders retain their prior held refusal unless they also hold narrow authority. Actor active/access, organisation/branch ownership, registered visit, serving queue, queue version, active treatment-room kind/branch, cooldown and audit checks remain.

New authority tests check held announcement preserves clinical/visit/queue/hold state, stale Resume version rejected, fresh postResume does not rehold, wrong branch/version/room/waiting/refused role/inactive actor, and no doctor-recall authority from narrow permission. Shared Visit lock orders with Resume reviewed; team reports registered real PostgreSQL contention test passes both lock orders1test/14assertions. Final ordinary13tests/140assertions and Pint/diff pass reported. New race registered Contention/Contention3 and excluded Feature; no historical assertion edits, migration or permission grants in this adapter.

No UI change. This is independent scoped source/test review, not a new full suite or release approval. No reviewer source edits.

## Follow-up: Treatment action visibility

Reviewed the subsequent QueueDirectoryService/QueueController/Queue Index projection and mapper changes. No bounded blocker: dedicated canCallTreatment requires active registered Serving state and narrow treatment authority, or existing doctor-call authority only while unheld. Loading treatment rooms for the narrow permission does not grant Call/Recall. The reused action still depends on available treatment rooms; no new layout or clinical payload. Team reports new mapper tests 2 pass and projection/directory/performance tests 13/140 pass. This supersedes the earlier “No UI change” statement for the adapter package: action visibility is now deliberately included. No reviewer source edits or full-suite claim.

## Integrated held-treatment visual review

Reviewed all four ma-held-treatment-dialog390/1440 light/dark screenshots plus ma-puchong390light. Copy clearly distinguishes announcement from consultation ownership; layout and contrast have no bounded blocker. Found undersized controls, confirmed by dialog-hitboxes.json: mobile room select36px (needs44), desktop actions32px (needs36). Queue delivered root-approved local class fix, preserving variants and permissions. Finding remains open until root’s next combined build and actual390/768/1440 measurements/screenshots verify it. No independent source edits or repeat announcement by reviewer. Screenshot review does not substitute for the team's backend/state-persistence checks.

Final sizing follow-up: re-read refreshed dialog-hitboxes.json (passed): selector and both actions44px at390/768 and36px at1440. Re-inspected refreshed390light and1440dark screenshots; prior full five-image review remains applicable to unchanged layout. Concrete sizing finding is closed. No remaining bounded visual blocker. No second announcement was submitted for this review; prior state-persistence proof remains separately recorded by Queue.
