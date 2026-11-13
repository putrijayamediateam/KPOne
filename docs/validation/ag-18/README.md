# AG-18: Version 2 Quick answers, review shots

Code under review: branch `feature/ag-18-v2-quick-answers`, head `1df81f56` (cut from `main` e48ac62d). Classic page
`Agents/Assistant.vue` is unchanged.

- Real Version 2 shell, switched on through `SpacePreferenceService` for the demo Director.
- Task-owned database `kpone_ag17_testing` (shared with the AG-17 preview; connection checked as 127.0.0.1:55418/kpone_ag17_testing
  before the first request). All names and data are synthetic.
- Data: the preview database has 874 visits on 12 Nov 2026 (871 seen) and no invoices, doctor assignments or Panel claims, so
  Money, Panel and per-doctor answers read zero. The page's layout does not depend on the numbers; the clinic and stock answers show the
  table and totals.

## Files

`qa-{1440,1280,1024,390}-{light,dark}-{start,answer-table,answer-doctors,answer-stock}.jpg`
- `start`: the page as it opens (twelve questions in four groups, Branch, When, Answer, the "Choose a question" prompt).
- `answer-table`: "Patients seen, per branch" for 12 Nov 2026 (table with totals from 1280, cards below).
- `answer-doctors`: "Patients seen, per doctor" (a sentence only: no doctor rows in this data).
- `answer-stock`: "Items at or below reorder level" (a sentence only: nothing to show).

## Measured

200 OK, no sideways overflow, no console errors and no control under the touch-target size (44 px below 1024, 36 px from 1024, including
the Branch select) at every width tried. After pressing Answer, focus moves to the answer heading (`assistant-answer-heading`).

## Notes for the reviewer

- One filled button, "Answer". The questions are a real radio group (a fieldset per group) in rounded rows with a cobalt tint when chosen.
- The "AI agent" chip sits in the notice line beside "Answered by Dato' Arif".
- The table is a V2Table with cards below 1280 px; a negative amount uses a real minus sign ("−RM 20.00").
- The scope line starts with a capital ("All branches · 12 Nov 2026"); the server sends it in lower case.
- The sticky top bar shows mid-page in the full-page shots because the page scrolled to the answer before the capture; it is a capture
  artefact, not a layout fault. The shell's "Clock in" button is the HR feature.
- Opened by the author: 1440 light answer-table, 390 dark answer-stock, 390 light answer-doctors, 1024 dark answer-doctors, 390 dark
  answer-table. The other files were produced by the same script and not opened.
