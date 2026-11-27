# Latest-handoff list ordering prerequisite

Coordinator technical approval authorized this separate prerequisite after actual SQL disproved the earlier latest-count assumption. This is delegated technical approval, not a new owner answer. Medicine search remains unimplemented.

Frozen3file patch27973048a93a6f3c278cb304c0d63a5d5fd497a374d8f768d47fbf4635bd2f49, base304cfd263fc884ceafed3c180d0b71d66b7f4b3b (PR1212). One application-line change: list eager handoff query uses reorder DESC, replacing global history relation's inherited ASC for this query only. Source SQL before was ASC,DESC; after is DESC. Global history relation remains unchanged.

Two new tests generate two attempts through existing prepared/return/resend/start/remove/complete services. Both reproduced before-fix failures: count1 versus current0; missing completed label URL despite latest completion. After the fix,33 focused tests/502 assertions pass across new tests, List, Type, Queue search and DoctorCheck. The new tests assert history1,2 and returned/open or returned/completed remains ascending, old medicine line unchanged, and existing CA reprint/doctor view eligibility. PHPStan zero, Pint/diff check pass. No existing assertions changed.

Actual own synthetic clone3170 captures12 GET-only width/theme states (prepare CA, done CA, done doctor at390/1440 light/dark). Fixture setup/completion uses the same existing domain services outside the browser. Current count0, preparing absent label link and completed role-specific links match the existing rendering/policy. No physical print or new eligibility rule is claimed. No frontend source changed; copied parent build manifest hashes match d1e3ea2ff2eaa462ee31784ef6fa5817255bed6b34fbab82e4b73071293c525f. Therefore no redundant frontend build/type rerun was performed. Browser checked no horizontal page overflow or runtime errors.

Inventory independently reviewed actual SQL/source/test flow; Design reviews visible implications separately. Existing shell/mobile tab presentation is unchanged. No clinical/Verify/mutation/grant/schema behavior changed, and no medicine-search implementation is bundled.
