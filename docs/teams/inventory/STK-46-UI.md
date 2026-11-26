# STK-46 cost entry and independent checking

26 November continuation. This separate slice builds on the approved hand-cost service/schema and retrospective replay/report overlay. No migration, permission grant or staff-account authority changes.

The dedicated Costs to fill in page is available only with existing cost-view authority plus the approved enter or check permission. Inventory desktop and mobile links follow the same rule. The page lists original missing/estimated/pre-STK-10B receipt or opening costs across the organisation, with branch, item, batch, date, quantity and individual cost evidence. It exposes no stock value total, COGS or margin. Actual-cost entries are excluded. Reversed receipts remain visible only where hand-cost history needs preserving; a waiting entry can be rejected but cannot be accepted or replaced.

The entry sheet takes a total in RM, shows a four-place per-unit echo, starts with no reason selected, and accepts an optional reference and encrypted note (required for Other). Zero is explicitly free. Entry submission and independent decisions use the existing service's branch/version/retry/permission/audit checks. Accept warns that earlier months change; Reject requires a reason. Self-entry shows the explanatory message instead of check controls. Accepted history is never edited; replacement creates another waiting entry while the latest accepted cost keeps counting. Staff names use InventoryStaffNames and existing staff-directory visibility. IDs, retry hashes and keys never appear in the page's history payload.

The inherited route group supplies sensitive.no-store and encrypted Inertia history. The new HTTP test proves the exact no-store response header. No production data or accounts are involved.

## Decisions and checks

- Owner approved exactly the two existing DD12 assertions on 26 Nov: insert the hand-cost link before Stock value in both layouts, and count the permission-gated link in Reports and checks. Both applied and focused DD12 passes.
- Owner subsequently approved the exact Classic common-link exclusion for Costs to fill in, retaining 16 common links, plus a new positive guard assertion. Applied exactly; no other existing assertion changes.
- Final focused service/replay/page tests: 19 tests / 213 assertions pass; the four page tests / 100 assertions also pass after the final source-basis wording change, including route visibility, retry, self refusal, independent acceptance/rejection, zero, immutable replacement history, actual/reversed receipt eligibility, organisation isolation, staff-name visibility and no-store.
- The initial focused run had a new HTTP test expecting 422 for the intentional 403 self-check refusal; corrected only that new test. Existing service/replay assertions remain unchanged.
- Scoped PHPStan zero errors. Frontend types and production build pass. New integer per-unit tests pass.
- Final full frontend suite passes 2,182 tests with runtime PHP on PATH. The initial scan found only the three approved navigation pins and a PHP PATH harness error; no additional existing assertion edits were required.
- Billing independent source review: no blocker. Designer supplied a bounded layout sketch before implementation and reviewed six states across 390/1440 light/dark. Applied the two review improvements: mobile search wraps onto its own row, and the source chip says Original entry so it does not contradict the accepted cost.
- Live synthetic entry/self-check/independent acceptance/replacement/rejection/history flow passes with 24 screenshots, no JavaScript errors or horizontal overflow. Four further screenshots show accepted free cost. Final-source browser proof also verifies fractional sen refusal, server error focus to the invalid note after Inertia finishes processing, and Escape restoring the entry trigger.
- Browser harness initially used a raw-label matcher for the RM field and later hit duplicate immutable history notes during repeat runs; changed to the actual accessible textbox name and an explicit first matching historical note. No product assertions weakened. The focus probe found a real callback timing bug, fixed by focusing from onFinish after disabled controls become enabled.

No commit, push, PR, merge, deployment or full backend suite. Coordinator owns integration and full validation.

Final designer reinspection closed both findings: the search field has a readable mobile row and Original entry is distinct from the effective accepted/free basis. No remaining bounded visual blocker.
