Queue now shows the daily average wait for the signed-in doctor's patients or the authorized whole branch. It distinguishes fewer than three calls, a real zero, loading and temporary failure. The optional request never blocks board polling, and stale requests cannot publish after a branch change or unmount.

This is the independently reviewed Q16e extraction directly onto main e5e72f69b, with no dependency on archive PR #1142. Existing Q16a/b/c remain unchanged. Daily duration/count aggregation runs in SQL rather than loading every called row. No permissions or migrations are added.

Validation: PostgreSQL 3 tests/15 assertions; all 2,207 frontend tests; scoped PHPStan, lint/format, full types and production build passed. Eight actual doctor/supervisor browser states at 390/1440 light/dark matched the scoped API with no overflow or JavaScript errors. The synthetic preview had two calls, correctly showing insufficient data; zero/positive means and scope boundaries are covered by automated tests. Independent source review and four independently rerun frontend tests passed.

This draft is a bounded dependency release, not the complete Queue demo makeover. No merge or production activation is requested.
