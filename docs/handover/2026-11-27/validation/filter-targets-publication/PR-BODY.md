Queue filter fields and room selection were 36px on mobile, while filter action buttons were 32px on desktop. Set the affected local controls to at least 44px below 1024px and 36px on desktop, following Clean Look section 4.

This changes only the Queue and Registration templates. Both page scripts remain byte-identical to main; handlers, permissions and test assertions are unchanged. Registration restyling remains in its separate PR; a three-way merge check preserves both changes without conflict.

Validation: 216 existing focused tests; scoped ESLint and Prettier; full TypeScript check and production build; git diff check. The identical class changes passed 30 integrated responsive checks at 390/768/1440 in light/dark, including measured target sizes, focus, overflow and browser errors. Independent source and screenshot review found no blocker. Backend code is unchanged; exact-head CI will provide the integration gate.
