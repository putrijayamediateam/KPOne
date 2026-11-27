# Quick-patient local field grouping plan

Base draft #1221, head `9ef687db400883a0e013d68bd5cd022599588d9d`. Demo source `RegisterVisit.dc.html` lines60–98 has Identity, Guardian, Contact, Address, Identifiers sections. Bounded local adaptation uses Identity, unchanged conditional GuardianFields, combined Contact and address, then Identifiers; duplicate override remains last. This avoids modifying or forking shared contact fields.

## Exact source ownership and movement

Only RegistrationCreateForm.vue template plus new actual-SFC grouping tests. Entire script stays byte-identical. Existing header/Find/New/search/results/selected branches and Visit/pay/summary sections remain unchanged.

Replace only quick-mode outer grid with a spaced local wrapper. Move complete unchanged field fragments into:

1. Identity: Name, DOB, adult confirmation, nationality, gender. Named section via unique local h3 `quick-identity-heading`; existing responsive two-column grid.
2. GuardianFields: unchanged component, bindings, condition and own heading; no extra guardian heading/wrapper condition.
3. Contact and address: unique h3 `quick-contact-heading`, existing complete PhoneInput then existing RegistrationContactFields with original flags/model/errors/id-prefix. Label is intentionally combined because shared component owns email+address together. No extra copy claiming a mandatory phone for minors.
4. Identifiers: unique h3 `quick-identifiers-heading`, complete existing IC/passport selector/value plus conditional passport issuer/else placeholder. Required/error/identity duplicate contract unchanged.
5. Existing duplicate-override refusal/checkbox stays intact after sections.

All remain descendants of original draftLocked fieldset. No additional form, field, required rule, validation, watcher, command/key, saved draft or policy. Moving DOM order does not alter error-priority map; first invalid target must remain visible and focusable. Public/shared components are untouched.

If actual geometry reveals inherited under44px controls inside this page's Contact and address section, scope minimum-height repair only to that wrapper after recording exact affected selectors/measurements. Do not modify shared component or blindly resize all descendants.

## Validation and review

Compare exact full script, original field fragments/bindings/conditions and unchanged shared source hashes. New compiled actual-template tests verify headings/order, one copy of controls, conditional passport and guardian, all existing required/error flags and disabled ancestry. Source-order assertions may need faithful reviewed adaptation; enumerate exact failure before editing.

Run existing search/mode/immutable form and focused field suites; types/build/fullfrontend/scoped/full static as warranted. Browser390/1440 both themes in fullpage/dialog: New field groups, passport, known minor guardian, actual validation first-error focus, uncertain original snapshot with controls disabled, no horizontal overflow. Any controlled server/transport fixture is explicitly labelled; avoid successful registration writes merely for layout proof. Independent final source/hash/visual review before separate stacked draft; no merge.

## Focus disposition after source inspection

The existing shared focusInvalidField selects the first DOM `[aria-invalid=true]`; there is no explicit fixed-priority map. Staff reviewed and accepted retaining that exact function and logical DOM/tab order. Regrouping changes the multi-error correction order to name, phone, identifiers when those are invalid. This is a deliberate presentation-order consequence, not a claim of unchanged priority. Prove each target is focused/unobstructed in fullpage and dialog; no CSS ordering or script workaround.
