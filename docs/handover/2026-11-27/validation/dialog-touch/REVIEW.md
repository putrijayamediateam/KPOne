# Shared dialog close touch target — review-ready

Isolated worktree /workspace/KPOne-dialog-touch, branch fix/clean-look-dialog-touch, base23639679e. One source file: resources/js/components/ui/dialog/DialogContent.vue. Root frozen source untouched. Patch dialog-touch.patch. No commits/pushes/dependency/token changes or existing assertion edits.

Problem verified: live Billing payment-results.json measured default Close height16px at390/1440 light/dark; source has16px SVG but no padded button. It is a16px hitbox, not a44px button with small icon. Shared Button sm intentionally remains32px desktop/44px touch; Billing owns its local AddPayment36px correction, no global Button change here.

Fix: default close inline-flex size-11 (44px), centered16px icon, top/right2, rounded-md, existing focus ring/accessible Close name retained. When close shown, reserve right padding for direct DialogHeader and direct DialogTitle so long titles do not overlap. No spacing override when showCloseButton=false.

Consumer audit:38 shared DialogContent consumer files found. All use a direct DialogHeader except ClockPhotoThumb (direct DialogTitle with compact p-3) and HR Attendance protected notice (close disabled). Existing busy/processing close guards preserved. Reka-based custom ClockPhotoDialog and SheetContent/DialogScrollContent are separate primitives, not silently restyled. Inventory/HR/clinical/billing dialogs continue using same component API. Consumer audit files ../dialog-consumers.txt and ../dialog-consumer-audit.txt.

Validation:10 existing confirm-dialog/clock-focus/protected-export tests pass unchanged (focused.log). git diff --check passes. Repository ESLint/Prettier explicitly ignore generated ui primitives; preserved their style and do not claim those ignored checks as lint coverage. Actual Vue+Reka component compiled in isolated Vite fixture using repository CSS; no Laravel/API/database mutation or full heavyweight gate.

Repeatable browser: node /workspace/kpone-validation/dialog-touch/check.mjs against3042. All widths390/768/1440 pass: actual close44×44, SVG16, reserved header space, Enter closes and focus returns to trigger, click2px from corner hits button, showCloseButton=false omits defaultclose, Escape closes, compact direct photo title clears close. Screenshots dialog-*-dark.png include visible focus ring; results.json/browser.log. Harness setup issues (initial duplicateVue import/CSS source discovery, overlybroad selector matching Cancel) corrected before passing run; no product defects inferred from those harness errors.

Scope limitation: live fixture exercises actual shared component and representative payment/confirmation/protected/photo consumers; full application Billing integration should apply this one-file patch and rerun payment probe as final integration evidence.

Suggested commit: fix(dialog): give default close control a44px touch target
PR: Default dialog close rendered a16px button. Give it a44px hit area while retaining16px icon, accessible name and focus ring; reserve title space for standard and compact-photo dialogs. Existing close-disabled guards unchanged. Validated10 existing tests and real component browser checks at390/768/1440.
