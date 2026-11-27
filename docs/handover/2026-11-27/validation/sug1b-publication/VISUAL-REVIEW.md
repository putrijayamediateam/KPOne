# SUG1b component browser validation

Coordinator observed the actual Vue page components using a public, synthetic-prop Vite harness at http://127.0.0.1:3132/frames.html. The harness loads the candidate production CSS. This is component rendering and interaction evidence, not signed-in application, backend mutation, or whole-demo parity evidence. Backend authorization/mutations were tested separately (18 PostgreSQL tests / 285 assertions).

Final target patch SHA256: 58135424fc6d913b30273bfaba6c6823eddbada10d12c12cec265c65289a7fc4.

- Prescribing phrases: all 11 rendered button/input targets measured 44px at 390 and 768, and 36px at 1440, in both light and dark themes. No horizontal document overflow. At requested 390px frame width, the vertical scrollbar leaves a 375px document client width; the window remains 390px.
- Review suggestions: rendered approval target measured 44px at 390/768 and 36px at 1440 in both themes, without horizontal overflow.
- Keyboard Tab from Edit focused the archive switch with focus-visible state and a blue #007aff ring/offset. Its decorative track is 44x26px inside the responsive hit target.
- Add phrase dialog: originally discovered a 36px Kind selector despite h-11 because SelectTrigger's data-size height wins. Changed the local trigger minimum to min-h-11. Final settled measurement: all six button/input controls 44px, dialog 358px wide inside the 390px frame. A first 41.8px observation occurred during the 0.95-scale opening animation; settled geometry was measured separately. No submission occurred.
- Screenshots actually inspected: mobile light add dialog, mobile dark phrases page, and desktop light phrases page (full page). Layout, content, visible focus, and semantic light/dark surfaces were readable. Live evidence tab marked for review.

The early dev-CSS scan omitted external page classes and its geometry was invalid; it is excluded. Final checks used production CSS assets app-CtUn_Mhs.css and app-Ds1aFT64.css. Browser telemetry Statsig errors belong to the browser tooling, not the tested application. No real patient data, login credentials, production access or shared component edits were used.

## Integrated publication follow-up

The current-main integration at 2b16d7050 passed 2,209 frontend tests, TypeScript and production build. The correct Feature-suite PostgreSQL invocation passed 31 tests / 445 assertions with exit 0. Earlier invocations without a suite selector loaded overlapping Contention/Contention1/2/3 registrations, producing 65 PHPUnit duplicate-suite warnings despite all cases passing; the PAO condensed output hid those warnings. PAO_DISABLE=1 exposed the diagnostic. No test or phpunit.xml change was made.

Subsequent refresh a0dac5901ee7193cb80b97d163e51a49b28cf190 only integrates six upstream REL-AG documentation files. Independent exact-head parity review confirmed no application/test delta. Published normally to the original PR1117 branch; fresh CI remains pending and no merge is claimed.

The live component preview's stylesheet link was subsequently refreshed to the current production manifest asset app-DbPBFVJ_.css after the upstream-main rebuild removed the old hashed file. This keeps the marked preview usable; it does not relabel the earlier visual observations as a new screenshot matrix.
