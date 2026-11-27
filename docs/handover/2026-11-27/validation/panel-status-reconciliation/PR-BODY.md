Panel queries currently appear within All and the broader Submitted view. This adds a dedicated Queried tab using the existing queried status, current branch authority and existing list filters/counts/pagination, with guidance to review the claim's recorded history.

Submitted retains its existing umbrella; Rejected, monetary summaries, claim amounts and all mutations remain unchanged. No permissions or migrations. This is stacked on draft #1203 and does not claim full six-tab demo parity or payment-period reporting.

Validation:38 backend tests/2162 assertions,2220 frontend tests, types/build, scoped lint/format/Pint/PHPStan and8 actual-data mobile/desktop light/dark browser states. New regressions cover21-row queried count/pagination, branch denial, rejected-batch redirect, and full-template rendering with the unchanged monetary summary DTO. No existing assertions changed.

A review caught an unintended Queried payout-summary entry before publication; removed it and added the full-template regression. Final gates use the corrected source. Existing compact body control debt and broader status/date/report mapping remain documented separately. Independent final review is recorded in the review packet.
