# Unconfirmed registration summary copy

Base #1209, `8c0a494b5b52e1881a1c0982a7cae75a7d3cc744`. Branch `fix/registration-uncertain-summary-copy`. Exact three-file patch SHA256 `5e18e6456bac9f7193b6e9a03951b7bcaa8c780dbad2627e34fd499732fbcaa6`.

Independent review identified that the normal summary wording could imply nothing was saved after an unconfirmed response. An optional `unconfirmed` prop now renders 'Review the original details retained for unchanged retry. The registration result is unconfirmed.' The normal description remains unchanged. Registration passes its existing notice state; no form-script, request, snapshot, permission or backend change. Script parity is recorded.

Thirteen focused tests passed, including a new actual summary-render case covering absence of the unsaved claim, retained original rows, no new controls, and unchanged normal default. Types/build, scoped ESLint/Prettier and diff checks passed. Final full frontend passed 2,231/2,231.

Actual four screenshots at390/1440 light/dark passed with no overflow/JavaScript errors. This copy-only proof deliberately aborts one synthetic POST before it reaches the server; it is not represented as a fresh committed-response replay test. Visit count stayed9. Parent1205/1209 actual lost-committed-response/unchanged-retry evidence remains applicable because all command scripts are byte-identical. No real-device/screen-reader or complete demo parity claim.
