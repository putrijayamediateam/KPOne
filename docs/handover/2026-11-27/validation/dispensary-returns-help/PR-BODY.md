Returns now has optional helpful-information disclosures for the current tab, existing filtered counts, the authorized Dispensing link and return-check guidance. Hiding help keeps required instructions and all operational controls visible. No return-check workflow or backend behavior changes.

This draft stacks on #1239. The existing960px table stays intact: help appears below until available content width can fit a280px rail. Counts retain their actual age/outcome/Controlled/month scope and are omitted when absent.

Validation:19focused frontend tests, full types/build, scopedlint/format and20synthetic browser states passed. Browser checks cover populated/empty layouts, keyboard focus, disclosure persistence, visible required instructions and GET-only behavior. Two old source assertions only normalize formatter whitespace; expected text/classes are unchanged and independently reviewed. Backend source is unchanged; no new backend rerun is claimed.

No new permissions, migrations, clinical mutations or production data. This is bounded demo alignment, not full-page completion.
