# V2 Medicine Catalogue shots, round 2 (feature/v2-medicine, head 1ccc11e5)

Real Version 2 shell, CA Supervisor (can manage prices and stock) unless the name says otherwise, synthetic data only: eight
medicines with a long name, a controlled one, an inactive one, drug groups and other names, and one with no price.
File name = `<width>-<screen>.png`. Widths: 1440, 1280, 1024, 768, 390, 320. No sideways scroll at any width.

Every width: `list-light`, `list-dark`, `row-menu`, `drug-groups`, `other-names`, `controlled-confirm` (says what changes
for the label, the Dispensary and the controlled register), `deactivate-confirm`, `add-page` (full page, action bar at the
bottom), `add-errors`, `edit-page`, `empty-state`. Phones also: `filters-sheet`.
1440 and 390 also: `row-menu-dark`, `drug-groups-dark`, `other-names-dark`, `controlled-confirm-dark`,
`deactivate-confirm-dark`, `add-page-dark`, `edit-page-dark`, `director-list`, `administrator-list`.
390 also: `director-list-dark`, `administrator-list-dark`.

Changes since round 1 (Daniel's list): Add and Edit now live in the address (`?add=1`, `?edit=<id>`), so Back, Forward, refresh and
a shared link work; the Controlled chip is neutral (no amber); prices read "Self-pay: no price" and "Default Panel: no price";
the action bar (Cancel, Save) stays at the bottom of the screen at every width; the Controlled confirm names the label, the
Dispensary return and the controlled register.

Behaviour checked in a real browser (12 steps, all as expected): Add puts `?add=1` in the address; Back returns to the list;
Forward returns to the form; refresh keeps the form; Back with unsaved work shows "Discard your changes?"; "Keep editing"
restores what was typed; "← Medicines" with unsaved work asks too; Discard returns to the list; a shared `?edit=<id>` link opens
that medicine; "← Medicines" on a clean form returns to the list; Edit from the list and Back return to the list.
How Back with unsaved work works: the browser swaps the page first, so the form's data is stashed on the way out and the new
page instance asks before it forgets it. The list shows for a moment before the question appears.

Known gaps (design debt for Kavitha's V2 blocks): the shared catalogue pickers (Category, Group, Dosage form, units, Route,
Manufacturer) still look like Classic inside the form, with a "Choose a saved value or type a new one" line under each; price
fields are plain number inputs until the V2 money input lands (empty stays empty, 0.00 is valid, "(RM)" in the label).
Full-page captures can show the shell's top bar and sidebar twice in the middle: a capture artefact, not on screen.
Not opened: stale or error states of Edit, the Add page with opening stock rows.
