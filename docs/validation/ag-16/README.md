# AG-16: Version 2 AI agents list, review shots

Code under review: branch `feature/ag-16-v2-agents-list`, head `d77ba234` (cut from `main` 06a407bf). Classic page
`Agents/Index.vue` is unchanged.

- Real Version 2 shell, switched on through `SpacePreferenceService` for the demo Director.
- Fresh task-owned database `kpone_ag16_testing` (connection checked as 127.0.0.1:55418 before the first request).
- Seeded: 6 agents (one waiting for approval with 3 pending suggestions, one working, one last run failed, three idle,
  one build agent) and 5 runs, one of them failed. All names and data are synthetic.
- Files: `agents-{320,390,768,1024,1280,1440}-{light,dark}.jpg` (full page), `agents-dialog-{390,1440}-{light,dark}.jpg`
  (the Disable confirm dialog).
- Measured at all 12 width and theme combinations: 200 OK, no sideways overflow, no console errors, six agent cards.
- Opened by the author: 1440 light, 390 dark, 390 light, 1024 light, 390 dark dialog. Not opened: the other files.
- Seed artefact: every run reads "Scheduled" because the seed set no requester. It is not a page defect.
