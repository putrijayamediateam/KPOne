The Dispensary case has operational controls and safety messages but lacks the reference's optional help panel. Add a header Show/Hide helpful info toggle and native About/Good to know disclosures after all operational controls. Hidden help stays mounted, preserving opened disclosures; mandatory allergy, scanner, dirty and error messages remain outside it.

Stacked on #1162. Three files only: Show.vue, four new actual-template rendering tests and the team delivery note. Existing Print/View-label predicates are retained on their own buttons. Readonly copy describes viewing only; OTC copy retains the existing allergy-confirmation requirement. No related links, new clinical data, permission, schema or Ready workflow.

Validation: 160 focused checks pass (156 existing assertions unchanged plus four new rendering tests), scoped lint/format, full frontend types/build and exact script parity after removing the local visibility ref. Actual synthetic browser checks pass 20 light/dark captures at 390/1440, keyboard state/draft retention, a real wrong-medicine warning while help is hidden, readonly/OTC copy and authorized Print/View-label/toggle targets44/36. No overflow or page errors. No new backend suite is claimed for this UI-only change.

This covers optional case help only; related links and full demo parity remain outside scope.

Independent Design source/visual review is clear for the exact three-file patch and independently reran all four new rendering tests.
