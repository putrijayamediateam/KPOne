Eligible Resident Doctors can open a read-only bill review for their own completed cases in the active branch. Drafts and other doctors' cases are excluded; the response omits billing mutation controls and private billing metadata, and access is audited before content is returned.

The change uses the owner-approved `billing.view_readonly.branch` grant and migration. The shared audit allowlist preserves both `billing.reviewed` and upstream `queue.details_viewed`. View bill controls meet the reviewed 36px desktop / 44px below 1024px target requirement.

Current candidate: `3c2f5b1f8373c581e5ad5d262062f2fa262a24ec`, containing main `83c5999bb6658c9009c81bc70cb444b8a8e0a9c4`. Independent comparison confirms 15 of the 18 slice files are unchanged from the prior reviewed candidate; the remaining permission, route and team-log files retain the intervening upstream changes. The approved migration and test assertions are unchanged by this refresh.

Validation: 20 focused PostgreSQL tests / 263 assertions passed on an isolated synthetic database; both frontend tests, scoped Pint, PHPStan and diff checks passed. Independent source/parity review cleared this exact candidate and independently reran both frontend tests. Exact-head CI static checks have passed; remaining CI checks must finish successfully before merge. No production deployment or real-data operation is included.
