# AG-16: Version 2 AI agents list, review shots

Code under review: branch `feature/ag-16-v2-agents-list`, head `03528cd9` (cut from `main` 06a407bf). Classic page
`Agents/Index.vue` is unchanged.

- Real Version 2 shell, switched on through `SpacePreferenceService` for the demo accounts.
- Task-owned databases `kpone_ag16_testing` and `kpone_ag16_empty_testing` (connection checked before the first request).
- Seeded on the first: 6 agents (one waiting for approval with 3 pending suggestions, one working, one last run failed
  and then disabled through the real dialog, three idle, one build agent) and 5 runs, one of them failed. The second has
  no agents and no runs. All names and data are synthetic.

## Files

| Daniel's point | Files |
|---|---|
| 1. "AI agent" chip, in words, at 390 and in dark | `agents-card-header-{1440,1024,390}-{light,dark}.jpg` (one card), every full-page shot |
| 2. Disable dialog and what it says, Cancel, filled Disable only inside it | `agents-disable-dialog-{1440,1024,390}-{light,dark}.jpg` |
| 2. Enable row and its confirm | `agents-card-disabled-*.jpg`, `agents-enable-dialog-*.jpg`, `agents-disabled-row-*.jpg` (full page with a disabled agent) |
| 2. A role that cannot manage (Technical Admin): no switch, one line saying who can | `agents-viewer-{1440,390}-{light,dark}.jpg` |
| 3. Activity table at 1440 and as cards at 390 | `agents-1440-*.jpg`, `agents-390-*.jpg` (and 320, 768, 1024, 1280) |
| 5. Empty state (no agents and no activity) | `agents-empty-{1440,1024,390}-{light,dark}.jpg` |
| Earlier full set | `agents-{320,390,768,1024,1280,1440}-{light,dark}.jpg`, `agents-dialog-{390,1440}-{light,dark}.jpg` |

## Measured

- 200 OK, no sideways overflow and no console errors at every width and theme tried.
- Every card shows the chips "AI agent" and Enabled or Disabled as text; the viewer role has no Disable or Enable button.
- Seed artefact, not a page defect: every run reads "Scheduled" because the seed set no requester.

## Opened by the author

card header 390 dark, viewer 390 light, empty 1440 dark, disable dialog 390 dark, enable dialog 1440 light, and from the
earlier set 1440 light, 390 dark, 390 light, 1024 light. The other files were produced by the same script and not opened.
