# PNL-30 wording correction — approved

Independent review found that skipped entries can be missing or already decided, so the response and confirmation must not claim every skipped row remains waiting.

Proposed production wording:

- Controller: `N could not be approved. Review the refreshed lists. <reasons>` replaces `N could not be approved and are still waiting. <reasons>`.
- Confirmation: `Each one is checked on its own. Any that cannot be approved are skipped. Review the refreshed lists and reasons after approval.` replaces the claim that changed rows stay waiting.

Exactly one existing assertion changes, in `PanelPriceBulkApproveTest::test_the_route_says_how_many_were_skipped_and_why`:

```diff
- ->assertSessionHasErrors(['bulk' => '1 could not be approved and are still waiting. You proposed this. Someone else must decide.']);
+ ->assertSessionHasErrors(['bulk' => '1 could not be approved. Review the refreshed lists. You proposed this. Someone else must decide.']);
```

No permission, migration, count, partial-approval behavior or other assertion changes. Add a new route test for missing/already-decided entries to check accurate wording. Owner explicitly approved this exact wording and one assertion change on 26 November 2026, relayed by B.E.R.N.I.S.S. Applied as approved.

Suggested question: “PNL-30 — lulus satu perubahan assertion mesej ini? Tukar ‘1 could not be approved and are still waiting…’ kepada ‘1 could not be approved. Review the refreshed lists…’, kerana item yang dilangkau mungkin sudah diputuskan atau tiada. Tingkah laku kelulusan dan semakan kuasa kekal.”
