# Registration and Queue scope review — 26 November 2026

The actual synthetic doctor preview showed the doctor’s own red patient in the emergency lane and another doctor’s patient in the ordinary Registration table, with Registered status and no queue number. This is existing authorized Registration behavior, not new identity exposure.

Main and integration both use VisitPolicy::viewAny / view with visits.view.branch and effective branch access. VisitDirectoryService’s ordinary query is organisation + branch + registration date, with optional user filters, not an assigned-doctor restriction. Its row exposes the registered patient’s identity and assigned doctor. visibleQueueEntry separately checks QueueEntry authority; another doctor’s queue therefore has no queue number/status/version or Call In action. VisitPolicy has no integration diff. The equivalent main row already contains these identity fields and that queue guard.

TRI2’s EmergencyLaneProjection uses queue.view.branch or assigned queue.view.own, and derives its complete exclusion subquery from that same scope. Thus the visible lane replaces only its authorized rows, across all lane pages; a doctor’s other branch registrations remain ordinary rows with queue/triage metadata hidden. Recovered design §3.5 says the lane row replaces its table row and §11 includes both Queue and Registration. This implementation preserves the coordinator’s explicit ordinary identity policy requirement. Blanket removal of other doctors’ red patients would change existing branch directory access and indirectly disclose hidden triage membership through disappearance.

Handoff: no application change or permission expansion. A separate NEW regression checks the doctor’s own emergency lane, branch-authorized other-patient identity, hidden other queue/triage/action metadata, and Queue own scope. Existing assertions untouched. Test-only followup requires focused execution after integration; it does not invalidate the currently running application aggregate gate.

Patch SHA256: 3d0d8e53a2ec7965a4863f9c01752ceaac0afb5fb7b2ccfa162147851b3c2673

Validation: new focused regression PASS 1 test / 26 assertions; Pint PASS. Forward apply-check to CA integration PASS. Log: registration-scope-test.log. No preview data or state changed.
