# Registration foundation control geometry — independent bounded review

Date: 2026-11-26. Reviewer: Patients & Registration agent, separate from root author of this target fix.

Reviewed exact commit `ecadc1125c6b6603828cf6951a06e6d4e547e3b6` and target patch SHA-256 `7384c96dd81e71642074795fef6c3a5166089bb01ed8ee40beb9e4324e1b6046` for PR #1132. Root source was not edited. Isolated detached snapshot `/workspace/KPOne-registration-foundation-preview` used physically copied generated assets; all 337 build files matched the frozen root build. Both trees remained clean at verification.

## Result

PASS for the requested four-control geometry scope. Actual browser execution produced 24 measurements and 12 captures, covering 390, 768 and 1440 pixel widths in light and dark themes.

| Control | 390 and 768 | 1440 |
| --- | --- | --- |
| Quick patient Identification type | 44px | 36px |
| Consultation | 44px | 40px |
| OTC | 44px | 40px |
| Edit Member/staff reference | 44px | 40px |

All controls retained focus and had unobstructed centers. Document overflow and JavaScript error checks passed. Native Consultation/OTC local pressed-state toggling passed. No patient or visit mutation requests occurred. Representative mobile dark Edit and tablet light Create screenshots were also inspected; the target controls are readable with visible focus. Full-page captures include the existing sticky shell at the current scroll position; these do not constitute a separate whole-shell layout approval.

## Authorized synthetic fixture

Create used the existing synthetic CA in Cheras. Edit used the existing synthetic Director after a normal authorized switch to Puchong, with Waiting visit KPV-00000004. The initial Cheras fixture was Serving and correctly denied Edit; the CA Puchong assignment had expired. No account, assignment, visit status, policy or fixture data was changed to bypass those restrictions. Edit only selected Panel coverage and entered a local unsaved member-reference draft.

## Evidence and limits

- `browser-results.json`: exact commit/hash, all measurements, focus/hit-test booleans, errors and mutation-request arrays.
- `browser.mjs` and `browser.log`: executed harness and successful result.
- `build-manifest.json`: copied production-build hashes.
- `target-snapshot.patch`: exact target patch.
- `create-quick-*` and `edit-panel-*`: twelve acceptance captures.
- Diagnostic screenshots/scripts document the initial denied fixture and branch selection; they are not acceptance captures.

Root separately reported 2205 full frontend checks, types/build and scoped lint/format passing. This review did not rerun those suites and does not certify full Registration demo parity, backend behavior, other controls or publication/merge status.
