The medicine label preview now presents its existing patient/branch context and separate prepared, blocked, excluded and previously printed counts in a responsive screen toolbar. Mixed blocked selections still refuse combined printing and retain existing individual eligible-label links; readonly previews retain their existing authority.

Only screen markup/styles change. The script, @page generation, printed medicine section and original print CSS are byte-identical to main. Existing assertions are unchanged. This is a bounded screen composition update, not a new selection workflow or physical-printer validation.

Validation: 53 focused frontend tests (50 unchanged +3 new actual-toolbar cases), full frontend types/build, scoped lint/format, zero-error scoped PHPStan,16 synthetic Laravel screen captures at 390/1440 light/dark, and two print-media toolbar/dimension checks. Controls measure 44px mobile/36px desktop; white paper remains white in dark mode. No physical printing was performed. Full backend validation is delegated to CI; no backend/migration/grant changed.

Independent source and visual review: no bounded blocker. Exact local evidence: `/workspace/kpone-validation/label-preview-next/` (source-manifest.json, print-parity.json, browser-results.json and INDEPENDENT-REVIEW.md). No merge requested.
