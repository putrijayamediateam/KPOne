# Contact and Address demo headings — opt-in shared slot contract

Assessment base: reviewed draft #1224, remote/local `e8d3769e855467baa70e9e50259b43926c8f13f4`. Actual demo RegisterVisit.dc.html lines80–96 distinguishes Contact (phone/email) and Address. Current local heading intentionally combines both because shared RegistrationContactFields renders email/address in one loop.

## Bounded proposed change

Add two optional named presentation slots to RegistrationContactFields: `before-email` immediately before the existing email label and `before-address` immediately before address_line_1. Keep the shared entire script, field key order, model reference, labels/localisation, required helper, input attrs/error mapping and responsive grid unchanged. Implement through keyed template around existing label loop, with slot outlet only at the two known keys. No slot content by default. No new shared prop, field, endpoint or business rule.

RegistrationCreateForm alone opts in. Its existing whole PhoneInput fragment moves into before-email after a local Contact h3; before-address supplies Address h3. Both headings span the shared grid's two columns. The outer local section keeps border/spacing/mobile input+select44px min and is labelled by both unique heading IDs. Existing combined visible heading is replaced by the two actual headings, not repeated. Parent entire script remains byte-identical; ContactFields model/flags/errors/id-prefix unchanged. Patient root shrink-0 and all disabled/snapshot behavior retained. Guardian and duplicate review unchanged.

The two other concrete consumers are PublicCheckIn/Show.vue and RegistrationReview/Show.vue. They provide no slots and are not edited. Prove their default shared SSR output unchanged (allowing Vue structural comments only), including public Malay labels/required flags/error rendering and staff labels; preserve input IDs, order, values and same model updates. No public feature work or new authority.

## Tests and review boundaries

Before source edits, peer-review this reusable-component contract. Exact adaptation required for the new grouping tests introduced in #1224: expected h3 sequence becomes Identity, Contact, Address, Identifiers; section accessible association includes both contact/address IDs. All original control/condition/error/required/locked checks stay. Existing older source pins must be separately proposed only if an actual gate identifies them.

Add actual shared-template default-vs-parent render comparison and actual model-update test with no slots, plus opt-in slot order/one copy of phone-email-address controls. Confirm both scripts unchanged and original data bindings equal aside from slot placement. Browser fullpage/dialog390/1440both themes, contact/address separation, first-invalid focus and uncertain original display disabled. Existing public/review smoke can use actual component SSR where no new route fixture is needed; do not claim live public flow proof without it. No registration writes required beyond controlled transport fixture. Independent final hash/visual review and scoped draft publication; no merge.

If slots prove intrusive or default DOM behavior differs materially, keep #1224's truthful combined label and report the gap rather than duplicate shared fields.
