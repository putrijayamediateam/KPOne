# V2Dialog size="tall" and the discard guard (feature/v2-dialog-size-guard)

Behaviour checked in a browser (a specimen page with a dirty form inside a V2Dialog):
- clean dialog: Esc closes at once (update:open false).
- dirty dialog: Esc, the X, a tap on the backdrop and Cancel each show "Discard what you typed?" inside the same dialog with "Keep editing" focused, and emit nothing (the dialog stays open).
- Esc on the question = Keep editing: the question closes, the typed text is kept and focus returns to the field.
- Discard emits `discard` then `update:open` false. Tab and Shift+Tab wrap between the two buttons (the page behind is inert).
- Save (the page closes it itself): closes with no question.
Sizes (dialog box, measured): tall 390x844 -> 390x844 full height; tall at 768 -> 672 wide (42rem); tall at 1100 and 1440 -> 896 wide (56rem) and 810 tall (90 percent of 900); default at 1440 -> 512 wide.
Shots: tall-390-light/dark, tall-768-light, tall-1100-light, tall-1440-light/dark, default-1440-light, discard-question-1200-light, discard-question-390-light.
A first version let Tab leave the question for the browser (the dialog's own trap ran out of places because the page behind is inert); fixed with a Tab loop on the question and re-checked. Specimen only: not a real stock form.
