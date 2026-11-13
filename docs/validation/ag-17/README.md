# AG-17: Version 2 Daily summary, review shots

Code under review: branch `feature/ag-17-v2-daily-summary`, head `9bc6095e` (merged with `main` ef80c483). Classic page
`Agents/DailySummary.vue` is unchanged.

- Real Version 2 shell, switched on through `SpacePreferenceService` for the demo Director, CA Supervisor and Finance Officer.
- Task-owned database `kpone_ag17_testing` (a clone of an earlier task-owned preview database, migrated; connection checked as
  127.0.0.1:55418/kpone_ag17_testing before the first request). All names and data are synthetic.
- Data:
  - **rich** = 8 Nov 2026, Cheras: a 07:00 report with comparisons (Registered 142, Net invoice sales RM 12,345.67, doctors with a long
    name, Panel and stock lines), a 14:00 update (today so far, no comparison), Hana's counts (9, 5, 2 and 3 made), Rizal with 14 counts due
    (five listed and "and 1 more"), and Faris "All clear". These sections were stored through the real model with crafted
    synthetic figures (the real daily run had no invoices, so every money figure was RM 0.00); each section has a real agent run.
  - **sparse** = 12 Nov 2026, from the real daily run (`agents:daily-run`): real visit counts and zero money, Panel and stock figures,
    Faris "Needs a look" (4 error log lines).
  - **today-empty** = 13 Nov 2026: no report yet at any branch.

## Second review pass (Daniel's changes)

1. Length: the newest report of a branch is open; an older report is its own closed bar. Panel and Stock appear only in the newest
   report. Phone page, default state, Director: 3,379 px tall (about 5,400 px before). Two stats to a row on phones for the clinic
   figures (from 360 px) and for money (from 390 px, and only while the longest amount is 12 characters or fewer); at the smaller
   size of 16 px so "RM 12,345.67" fits, measured at 320, 375, 389, 390, 391, 393, 412, 430 and 600 px with no overflow.
2. Words: "Panel claims when this report was built" and "Stock when this report was built"; "After reversals" instead of "Net".
3. Units (from what the server counts): "7 items, counted per location" (low stock is one row per item at a location), "12 batches" and
   "2 batches" (expiring and expired), "14 counts", "2 cases", "1 difference", "23 invoices", "11 claims".
4. Crumb: "Agents > Daily summary" (plain text, no link, because a CA Supervisor or Finance Officer cannot open the Agents list).
5. Role views re-shot (CA Supervisor and Finance Officer, 1440 and 390). Checked in the page text for the CA Supervisor: no "RM"
   figure, no net-sales line, no Panel part, and a headline that names only the registered count.

## Files

`daily-{rich,sparse}-{director,ca_supervisor,finance_officer}-{width}-{light,dark}-{default,open}.jpg`
- `default` is the page as it opens (the active branch open, the others folded); `open` has every branch bar opened.
- Director: 1440, 1024 and 390, light and dark, plus 360 and 320 light. CA Supervisor and Finance Officer: 1440 and 390, light (they see fewer parts).
- `daily-director-{1440,390}-{light,dark}-today-empty.jpg`: the "no report yet" state.

## Measured

200 OK, no sideways overflow, no console errors and no controls under the touch-target size (44 px below 1024, 36 px from 1024) at
every width tried. Every agent section carries the "AI agent" chip as words (Director rich: 5 chips).

## Notes for the reviewer

- Comparisons are words only ("Up from 131", "Down from 26 min", "Same as 11 min"), never colour; nothing is shown when both days are 0.
- A part the server sends as null is not drawn: the CA Supervisor sees no money or Panel figures, the Finance Officer sees no doctors list.
- Each branch is a V2DisclosureBar whose closed title carries the newest report's headline ("Cheras · 88 registered · Net sales RM 12,345.67").
- On phones the four clinic stats sit two to a row; money stays one per row so amounts never overflow.
- The shell's "Clock in" button in the top bar is the HR feature, not part of this page.
- Opened by the author: rich Director 390 light (default), rich CA Supervisor 390 light (default); the first review pass opened Director 1440 light,
  Director 390 dark, CA Supervisor 390 light and the no-report 1440 dark. The other files were produced by the same script and not opened this pass.

## Round 3 (head 75ede439)
- Low stock now reads "7 items, by location" (Daniel's tweak).
- Own checks: Finance Officer 1440/390 (money, Panel and stock shown, no Faris block); CA Supervisor 1440/390 (no RM, net sales or Panel); Director dark 1440/1024/390; Director 360 and 320 light. No page overflow, no clipped stat in any of them.
- Limits: seeded runs read "Scheduled" (seed artefact); only the synthetic 8 Nov day has non-zero money.
