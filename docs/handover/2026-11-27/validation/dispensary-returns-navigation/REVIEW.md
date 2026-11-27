# Returns navigation review packet

Frozen patch: `9e99caca06baf64c8c0ee80356816e92ffbea9d3168b6fb1948d932d3c03ef35`, three files listed in source-manifest.json. Base: published age-filter PR1225, cf3adbb7f584fc3aad016ebcfdf9c884c6f76f17.

The Returns page now offers the existing Dispensing route and marks Returns current. The complete script is byte-identical. No backend, policy, query, count, mutation or existing test assertion changes. The current Returns gate already implies existing Dispensing authority; Inventory reviewed that implication. Destination policy remains authoritative.

Validation: 16 focused frontend tests, full TypeScript and production build, scoped ESLint/Prettier and diff check passed. Two new actual-template tests cover navigation on both tabs and absence of mutation controls. Backend is unchanged; prior PR1225 backend evidence is carried, not rerun or claimed as new validation.

Actual synthetic browser: eight Waiting/Checked width/theme states, two keyboard navigation/back flows and unauthorized CA403. Targets44px mobile/36px desktop; no page overflow or browser errors. Navigation reaches /dispensary without Returns filters; Back restores semantic query values. Initial harness failure compared query ordering literally; preserved browser-initial-back.log and navigation-debug.json distinguish that harness correction from application behavior. Final browser-final.log passes.

The destination on this separate base has the older Index presentation. This packet does not claim the unmerged1140 workspace or full demo parity. No production data, return submission, clinical transition or authority change was performed. Preview fixtures and browser artifacts are local only.

Final independent Design review CLEAR at exact9e99caca patch; all three hashes verified and two tests independently rerun. Inventory plan review clear. No merge authorized.
