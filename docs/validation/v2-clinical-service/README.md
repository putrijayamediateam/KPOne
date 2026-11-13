# V2 Clinical Service Catalogue shots (feature/v2-clinical-service, head 11acd102)

Real Version 2 shell, CA Supervisor (can manage prices) unless the name says otherwise, synthetic data only: four services
(one inactive, one with a category). File name = `<width>-<screen>.png`. Widths: 1440, 1280, 1024, 768, 390, 320. No sideways
scroll at any width.

Every width: `list-light`, `list-dark`, `row-menu`, `deactivate-confirm`, `add-page` (full page), `add-errors`, `edit-page`,
`empty-state`. Phones also: `filters-sheet`. 1440 to 768: `filter-applied` where the filter applies.
1440 and 390 also: `row-menu-dark`, `deactivate-confirm-dark`, `add-page-dark`, `edit-page-dark`, `director-list`,
`administrator-list`. 390 also: `director-list-dark`, `administrator-list-dark`.

Same behaviour as the Medicine Catalogue, checked in a real browser (12 steps, all as expected): Add puts `?add=1` in the
address, Back returns to the list, Forward returns to the form, refresh keeps it, Back with unsaved work asks first ("Keep editing"
restores what was typed), "← Clinical services" asks too, a shared `?edit=<id>` link opens that service, Edit from the list and
Back return to the list. The list shows for a moment before the question appears after Back.

Known gaps (design debt): (1) the "Consultation tariff" panel at the top of the page is a shared component and still looks like
Classic (small headings, its own cobalt save button, a table of its own); it also pushes the services table far down the page.
A V2 version of that panel would fix both. (2) The Order unit picker is a shared catalogue picker (Classic look, a hint under it).
(3) Price fields are plain number inputs until the V2 money input lands. (4) Two filled buttons can show on one screen at 1440
(the page's "Add clinical service" and the tariff panel's "Save consultation tariffs"), because the panel is shared.
Full-page captures can show the shell's top bar and sidebar twice in the middle: a capture artefact.
Not opened: stale or error states of Edit, the Consultation tariff panel's own states.
