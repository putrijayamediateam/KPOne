# Bounded shared target debt note

Observed during current slice reviews; no broad shared-component changes are proposed or applied here.

- CompactPagination defaults have measured 32px on desktop in several owned pages. SUG1b and FinancialWork use local 36px desktop/44px below1024 overrides. Other consumers remain unverified; inspect actual hitboxes before changing them.
- OperationalSelect/trigger data-size h-9 can override a plain h-11 class. SUG add-dialog Kind required min-h-11; local Inventory picker wrappers likewise enforce minimums. Icon dimensions alone do not establish hitbox size.
- The shared branch switcher has previously measured36px on mobile in inherited shells. This is separate from the reviewed page-local target fixes; no current cross-page rollout proof is claimed.
- Pagination controls becoming disabled or removed after navigation need persistent labelled focus targets. Q16d has explicit proof; Treatment UI now adds synchronous target focus and awaits final runtime evidence. Avoid initial-load autofocus or asynchronous focus stealing.

Local fixes do not certify all shared consumers. No new permissions, assertions, migrations or global style edits are authorized by this inventory itself.
