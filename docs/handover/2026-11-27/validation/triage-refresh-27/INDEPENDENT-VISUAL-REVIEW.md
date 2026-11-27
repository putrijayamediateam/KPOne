# Independent Triage target review

CLEAR for commit 6eeec08a65c138088ec376bf744e3c397e669172. Independently verified Show.vue SHA256 a74c3f93c58b5de34506fecee214d768f3ac4292f468ccd3cf9f771f5fa593aa and target patch 20b887511ef3151b219986f49c0781ec82c34a705e0672b3c925d2fb22031c7b.

Ran the actual Triage Vue component through a public local Inertia harness with explicitly synthetic props and the final production manifest CSS (app-C0iYBeSf.css and app-Ds1aFT64.css). This does not exercise authentication, server authorization, clinical persistence, the full app shell or real clinical guideline data. No credentials or clinical writes were used.

Six independently executed viewport/theme checks passed: 390/768/1440, light/dark. Use the suggested zone measures 44px below 1024 and 36px at desktop, has a visible semantic blue keyboard focus ring, and Enter restores the supplied suggested Green state after selecting Red. No document overflow, JS errors or non-GET/HEAD requests occurred. Evidence: visual/results.json, check.mjs and six reset screenshots. Visually inspected mobile dark and desktop light captures: target remains readable and unobstructed. These viewport captures intentionally scroll to the focused control.

The first harness attempt focused programmatically after a mouse action and correctly did not activate :focus-visible; the harness now enters keyboard modality before checking the keyboard ring. A JSON width-key collision was also corrected and the complete check rerun; final results preserve viewportWidth separately from control width. No application source was changed during validation.

Root reports 2,205 frontend tests and type/build gates passing. Those full gates were not independently rerun. This is bounded target/interaction clearance, not full demo parity or backend clinical validation.
