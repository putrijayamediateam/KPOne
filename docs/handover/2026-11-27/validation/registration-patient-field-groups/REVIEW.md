# Quick-patient local field groups

Five-file slice based on #1221 `9ef687db400883a0e013d68bd5cd022599588d9d`. Exact patch `b5dc58e1cfae55c7fde77f79245f4f6e73693d6e37d88abac5219bd3880c2671`.

Existing fields now have local Identity, Contact and address, and Identifiers headings. The existing conditional GuardianFields remains between Identity and Contact, with its own unchanged heading. Duplicate-override refusal and acknowledgement remain last. Contact and address is intentionally combined because its shared component owns both; no duplicated/forked fields or shared/public-consumer edits. No new rule, field, authority, migration or backend behavior.

Whole original script is byte-identical. Compiler-node comparison preserves every original non-class attribute/directive/binding, excluding only new heading IDs/associations; expression token comparison ignores formatter whitespace. Existing shared components are untouched. The original disabled ancestor still owns all fields. Actual measured contact inputs/select were40px at390/768; a local Contact wrapper raises only its input/select minimum to44 below1024, leaving desktop40px. There are no checkbox/radio controls in that wrapper.

The unchanged focus helper chooses the first invalid DOM field. Grouping therefore deliberately changes multi-error focus to current logical order (name→phone→identifier for those errors), reviewed explicitly by Staff. It does not preserve an imaginary fixed error-priority map. Validation expressions and submission logic remain unchanged.

Three existing assertions received precisely reviewed whitespace-only adaptations after the deeper template formatting exposed their pins. They retain the same minor-phone arguments, exact identity flex classes and unknown-DOB operands. No semantic guard removed.

Evidence: original-fragments.json, parity.mjs/json, pre-touch-geometry.json, exact source manifest and patch, PLAN with delegated review dispositions, independent review note. New actual-SFC SSR tests compile the actual shared Guardian/Contact/InputError components and cover group order, conditional guardian/passport, required/contact errors, duplicate refusal and disabled retry ancestry. Fullpage/dialog browser tests exercise actual client validation and focus. Unconfirmed state is controlled transport abort before server, not a new committed replay claim. Final gate/browser counts recorded below when completed.


## Measured dialog correction and final gate scope

An actual browser check found the overflow-hidden Patient flex child collapsed to2px while its scrollHeight was1767px. Name was focused but clipped behind the dialog header. Scroll-margin diagnostic did not repair it; isolated flex-shrink:0 did. The final source adds only `shrink-0` to the owned Patient root section and a new rendered regression. No shared Dialog or focus script change. Failed geometry is preserved in before-flex-geometry.json and diagnostic-last-focus.json/png; diagnostic styles are not used by final browser.mjs.

Full frontend2,244/2,244, types and full lint/format passed before the final class-only collapse fix. After that fix,11 relevant actual-template/four-section/immutable-form tests, scoped lint and fresh production build passed; full script/compiler binding parity and diff check still pass. This distinction is intentional; the unchanged full suite was not represented as rerun after the one-class repair.

Final original browser.mjs (no diagnostic CSS) PASS:40captures,30contact measurements across390/768/1440, six fullpage/dialog name→phone→identifier focus/viewport/hit-test proofs alltrue. Patient dialog root1769px/scrollHeight1767/flexShrink0, replacing measured2px collapsed state. Mobile contact44, desktop40. Minor Guardian and uncertain disabled groups verified; no checkbox/radio descendants in locally sized contact wrapper. One controlled submission was aborted before server; synthetic visits9 unchanged. No JavaScript errors or horizontal overflow.
