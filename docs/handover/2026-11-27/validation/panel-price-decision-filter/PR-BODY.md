Adds Approved, Rejected and Withdrawn filters to the existing Panel price Decided list. Filtering happens before the existing 25-row pagination within the existing organisation scope. Waiting proposals, proposal price selection, staff visibility, amounts and all decision actions stay unchanged.

Direct filtered links and Clear-to-All retain Decided context. Read navigation preserves the proposal draft; the control keeps its loaded value while a request is pending or fails. Panel and charge selections remain proposal context, not decision-list filters.

Stacked on #1237 (`feature/panel-remittance-workspace`). Six files; no existing assertion changes, permissions, migrations or financial transitions. Existing separate #1192 page composition remains outside this stack and requires normal release reconciliation.

Validation: 28 backend tests / 354 assertions; scoped PHPStan and Pint; 2,235 frontend tests, full lint/format/types/build. Final label/caption polish followed by 14 focused frontend tests, scoped lint and production rebuild. Independent backend and design reviews recorded in `/workspace/kpone-validation/panel-price-decision-filter/`. Actual synthetic browser evidence covers filtered pagination, direct All reload, separate-organisation empty state, preserved draft and failed read, at 390/768/1440 light/dark. Only the new filter controls have bounded 44px mobile / 36px desktop proof; no full-page demo parity claim.

Frozen patch SHA256 `54a9584d3acdae6d45cdfad8eac40bd432cb061f05f5de54bb5b9e5af7916fab`. Draft publication under standing delegated continuation authority; no merge requested.
