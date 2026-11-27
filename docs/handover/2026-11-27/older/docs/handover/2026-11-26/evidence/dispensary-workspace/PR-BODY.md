The Dispensary list now presents its three existing server-count states as selectable cards beside contextual help, following the uploaded Pharmacy workspace layout. Returns navigation uses the existing branch-view permission and protected route. Search, pagination, visible-only polling, case actions and labels retain their existing handlers.

This is a bounded presentation slice. Verify still completes a case; return checks stay on the case page. It adds no Ready state, all-branch access, new filters, follow-up links, permission grants or clinical defaults. Existing assertions are unchanged.

Validation: 2,206 frontend checks; full TypeScript and production build; scoped lint/format; eight focused checks, including three new component-runtime tests. Seven actual synthetic browser states cover 390/1440 light/dark, empty search, doctor-check tab and help disclosure with no page overflow, 44px mobile targets and 36px desktop targets. Independent Design review found no local blocker. Backend/migration suites were not rerun because this changes one Vue page, new frontend tests and team notes only.

Known inherited issue: the shared sidebar highlights Today on /dispensary because its menu links to the Registration dispensary tab. This PR does not change shared navigation. Broader full-demo parity remains unfinished.

Draft for owner handover. No merge requested; not-started work is on hold.
