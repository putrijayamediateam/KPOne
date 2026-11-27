Organizes the existing Panel payment form into payment details, accepted-claim split, and review/record sections. Mandatory warnings and errors stay visible; optional help explains the loaded 200-claim window and existing split rules. Page-local control targets measure 44px below 1024px and 36px on desktop.

Stacked on #1232 (`feature/panel-follow-up-status`). Three scoped files; complete financial script and 29 critical bindings unchanged. No backend, authority, payment transition, migration, or existing assertion changes.

Validation: 2,232 frontend tests; 20 existing backend tests / 504 assertions; full lint, format, types and production build pass. Thirty synthetic browser captures cover 390/768/1440 light/dark, no Panel/empty/valid/oversplit/reversed history and actual CSV replacement Cancel. Cancel preserves the draft; Director payment-route access remains 403; payment/remittance/reversal counts remain unchanged. Shared calendar popup and full-demo parity are outside this bounded claim.

Independent Staff Design and Inventory review packet: `/workspace/kpone-validation/panel-remittance-workspace/`. Frozen patch SHA256 `f41aba958851eeaa0b1ef274e702d85d7e8bd4e8976276df525dea5cc33ef5b1`. Published under the owner’s standing delegated continuation and scoped draft publication authorization. No merge requested.
