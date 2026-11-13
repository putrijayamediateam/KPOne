# AG-17: Version 2 Daily summary, review shots

Code under review: branch `feature/ag-17-v2-daily-summary`, head `b054fbd1` (cut from `main` 9ea47cc1). Classic page
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

## Files

`daily-{rich,sparse}-{director,ca_supervisor,finance_officer}-{width}-{light,dark}-{default,open}.jpg`
- `default` is the page as it opens (the active branch open, the others folded); `open` has every branch bar opened.
- Director: 1440, 1024 and 390, light and dark. CA Supervisor and Finance Officer: 1440 and 390, light (they see fewer parts).
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
- Opened by the author: rich Director 1440 light (default), rich Director 390 dark (default), rich CA Supervisor 390 light (open), today-empty
  Director 1440 dark. The other files were produced by the same script and not opened.
