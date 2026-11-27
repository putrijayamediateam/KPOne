# Latest handoff list ordering prerequisite

Coordinator technical approval (27 November KL, 26 November UTC): separate this concrete existing ordering fix from unimplemented medicine search. Actual generated SQL currently orders attempt_number ASC then DESC because the history relation already adds ASC. List first() therefore selects the oldest attempt for medicineCount and completed label eligibility.

Exact files: DispensaryListService.php changes only list eager-load ordering to reorder('attempt_number','desc'); new DispensaryLatestHandoffListTest.php uses existing prepared/return/resend/start/remove/complete services to produce two attempts with different counts/status; TEAM.md records the bounded correction. Global history relation remains ASC. No model, permission, schema, mutation/Verify or frontend changes.

Reproduce both count and completed-label failures before the fix, then run new + existing List, Type, Queue-search and doctor-check tests; changed-path PHPStan/Pint and diff check. This backend-only result changes already-existing count/label projections; existing frontend rendering is untouched. Inventory reviews exact SQL and domain-generated regression; Staff reviews visible count/label implications. No claim of new label printing or actual physical printing. Publish scoped draft on1212, no merge, then return to medicine-search latest-handoff contract.
