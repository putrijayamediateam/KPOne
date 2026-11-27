# Independent Inventory backend review

Bounded backend diff reviewed in KPOne-panel-queried-view: service TABS adds queried and applyListFilters adds only exact status=queried; controller shared listRules adds that same value. Existing active branch/org and locked manage authority remain; claimBase scopes before filtering, count and rows use the same predicate before existing 20-row pagination. Submitted umbrella remains unchanged, summaries and monetary calculation paths have no diff. The extra tab adds its expected count query; no policy, mutation, grant or cross-branch projection change.

Read three new real-service tests covering query-to-rejection, original Submitted membership/amounts, 21 queried rows before pagination with name filter, branch/authority refusal and failed bulk redirect. Builder reports38 tests/2162 assertions; not independently rerun. No bounded backend blocker. Template/browser review remains Staff/builder-owned.
