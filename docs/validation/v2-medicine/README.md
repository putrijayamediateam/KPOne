# V2 Medicine Catalogue shots (feature/v2-medicine, head a1e26817)

Real Version 2 shell, CA Supervisor (can manage prices and stock) unless the name says otherwise, synthetic data only: eight
medicines with a long name, a controlled one, an inactive one, drug groups and other names, and one with no price.
File name = `<width>-<screen>.png`. Widths: 1440, 1280, 1024, 768, 390, 320. No sideways scroll at any width.

Every width: `list-light`, `list-dark`, `row-menu`, `drug-groups`, `other-names`, `controlled-confirm`,
`deactivate-confirm`, `add-page` (full page), `add-errors`, `edit-page`, `empty-state`. Phones also: `filters-sheet`.
1440 and 390 also: `row-menu-dark`, `drug-groups-dark`, `deactivate-confirm-dark`, `add-page-dark`, `edit-page-dark`,
`director-list`, `administrator-list`.

Full-page shots (`add-page`, `add-errors`, `edit-page`) are whole-page captures: the shell's top bar and sidebar can show
twice in the middle, which is a capture artefact and not on the screen.

Known gaps for Daniel: (1) the shared catalogue pickers (Category, Group, Dosage form, units, Route, Manufacturer) still look
like Classic inside the V2 form: smaller labels and a "Choose a saved value or type a new one…" hint under every picker. They are
shared components, so this page does not change them; a V2 picker block would. (2) Price fields are plain number inputs until the
V2 money input lands. (3) Add and Edit are one full page inside the same address, so the browser Back button leaves the
catalogue instead of returning to the list; "← Medicines" returns to the list.
Not opened: stale or error states of Edit, the Add page with opening stock rows, and the dialogs in dark mode other than
Drug groups and Deactivate.
