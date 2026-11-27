# Extract one Registration form owner

Bounded continuation of draft #1186, base46ca8f1977259c10e6c32440fd86b4effa39a9c6. This refactor moves current state and form markup together into RegistrationCreateForm. The existing page retains Head/layout/header/main and forwards reactive server props to one unkeyed child. No modal, new dirty-close behavior, navigation interception, business rule, backend, permission or schema change.

The entire props/state/computeds/watchers/search/submit/cleanup block is byte-identical. Form/picker template is identical except formatter whitespace and one trailing argument comma. Wrapper preserves the existing direct route, Console link, heading and flex parent. New summary and approved touch targets remain intact.

Twenty-one existing test files relocate source reads; all 581 lexical assert call sites remain. Both wrapper and child remain covered by broad no-V2/no-native-select guards. Dynamic paths in guardian, Gender and MY-default tests were discovered by focused execution and corrected only after exact independent delegated review. See DELEGATED-DECISION.md and both proposed diff artifacts. No owner answer is fabricated.

Focused114/114 pass, including four new tests using actual compiled page/form, actual Vue mounting/lifecycle and real Inertia useForm. These prove one unkeyed child, reactive prop forwarding with retained drafts/refusals/expected branch, one initial recommendation, pending-search abort/timer cleanup, original single submit/error callback and fresh state after unmount/remount. Rendering of the child in these lifecycle tests is replaced with a null render to isolate lifecycle; actual form DOM is covered by browser evidence, not claimed from those unit tests.

Final full frontend2213/2213 pass; full ESLint/resources formatting/types/build pass, and final path-only followups passed scoped lint and11 focused checks. Actual browser20 states pass, including a real rejected registration response preserving the same form DOM, selected patient and errors, plus Console/Back/Forward. Visit count remains5→5. Fourteen of16 baseline screenshots are pixel-identical; remaining2 have identical dimensions with small focus/timing regions. Supplemental actual viewport capture confirms the invalid doctor field and refusal text are fully visible/unobstructed below the header/action bar, with browser focus on assigned-doctor. This supplement used another rejected synthetic request because the original browser had closed; visit count still5. This is an extraction dependency toward a future dialog, not completed dialog parity.


Final frozen patch SHA-256 `9858bcab6585c15612d10778ec0f4c71121657beb1dd8123c39357174a3e564f`:24files,21 existing test relocations,581 existing assertion call sites preserved.


## Publication

Draft #1190 https://github.com/putrijayamediateam/KPOne/pull/1190, head `adef80a5cabf188c6a4b9af1afdfec4278e155cd`, branch `refactor/registration-create-form`, base `feature/registration-review-summary` (#1186). Exact reviewed patch9858bcab6585c15612d10778ec0f4c71121657beb1dd8123c39357174a3e564f; clean normal push, no merge. Final independent source/hash/runtime/visual CLEAR.24files: one moved form, page wrapper, one new runtime suite and21 faithful existing source-test relocations. All581 existing assertion call sites retained. Full frontend2213/2213, types/build/full lint/resources formatting and final path lint pass. Actual20browserstates plus one focused viewport prove retained refusal and focus, preserved form DOM, Back/Forward and visit count5→5. No modal or whole-demo-parity claim.
