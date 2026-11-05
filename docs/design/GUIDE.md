# KPOne design guide

Owner: Daniel, Lead UI/UX Designer (design team, see [TEAM.md](../teams/design/TEAM.md)).
Version 1, 2026-11-05 (DES-1). Written from a survey of 35 staff screens on `main` at `322228f`: six seeded demo
accounts (CA, Resident Doctor, CA Supervisor, Finance Officer, Panel Officer, Director), 390 and 1440 px, light
and dark, 140 screenshots, synthetic `.test` data only.

**How to use this guide.** It describes what KPOne already does well and names the patterns to stop using. It
never changes a screen by itself: the team that owns a screen fixes it in its own PR. When the guide and an
existing screen disagree, follow the guide for new work and add the old screen to the design debt list (DES-2).
Run [CHECKLIST.md](CHECKLIST.md) before every hand-off.

**Out of scope** (owner-approved, do not restyle): the waiting-room TV (`pages/QueueDisplay/Screen.vue`), the public
website design profile, and the invoice, receipt and drug-label print layouts.

---

## 1. Colours and tokens

All colours live as CSS variables in `resources/css/app.css` (`:root` for light, `.dark` for dark) and are exposed
to Tailwind as `bg-*`, `text-*`, `border-*` utilities.

| Token | Use it for | Light | Dark |
|---|---|---|---|
| `background` / `foreground` | Page and main text | `#f8f9fa` / `#18181b` | near black / near white |
| `card` / `card-foreground` | Panels, table frames, dialogs | white | same as background |
| `muted` / `muted-foreground` | Quiet fills; help text, metadata | `#f4f4f5` / `#71717a` | grey / light grey |
| `primary` | The one main action on a screen (black in light, white in dark) | `#18181b` | near white |
| `brand` / `brand-strong` | KPOne pink: active tab, focus ring, "accent" badges | `#db2777` / `#be185d` | lighter pinks |
| `success`, `warning`, `warning-strong`, `destructive` | Status meaning only | green, amber, red | brighter versions |
| `border`, `input`, `ring` | Lines, field borders, focus ring | | |

Measured contrast on the light page background: `muted-foreground` 4.6:1, `success` 5.2:1, `warning` 4.8:1,
`destructive` 6.1:1, `brand-strong` 5.7:1, but **`brand` only 4.4:1**. In dark mode every token is above 5:1.

- **Do** use tokens: `text-muted-foreground`, `bg-card`, `border-border`, `text-destructive`. Example:
  `components/ui/status/StatusBadge.vue` maps five tones to tokens and works in both themes with no `dark:` class.
- **Don't** hard-code palette colours (`bg-amber-50`, `bg-pink-100`, `text-red-600`, `bg-white`, `slate-*`). Each one
  needs a matching `dark:` class, and forgetting it is exactly the bug DARK-1 (PR #207) fixed on four screens. Today
  about 70 pale palette backgrounds in `pages/` and `components/` sit beside only about 30 dark overrides (for
  example `pages/Dispensary/Show.vue`, `pages/Registration/Create.vue`, `components/patient-board/PatientBoard.vue`).
- **Do** use `text-brand-strong` for pink text that must be read. **Don't** use `text-brand` for small text on the
  page background (4.4:1 fails the 4.5:1 rule); `brand` is fine for lines, rings, icons and the active-tab bar.
- **Don't** add new colour tokens or fonts without the owner (TEAM.md rule). If a meaning is missing, ask Daniel.

## 2. Type

One font stack (`--font-sans` in `app.css`: Aptos, Segoe UI, system sans). No web fonts.

| Role | Classes | Example |
|---|---|---|
| Page title (one `h1`) | `text-2xl font-semibold tracking-tight` | `components/ui/page-header/PageHeader.vue` |
| Section title (`h2`) | `text-base font-semibold` or `text-sm font-semibold` | `pages/PanelClaims/Index.vue` summary cards |
| Body | `text-sm` | most pages |
| Help text, metadata | `text-xs text-muted-foreground` (`.kp-metadata`) | `components/ui/state/EmptyState.vue` |
| Table header | `text-xs font-medium text-muted-foreground`, uppercase in catalogue tables | `components/ui/table/OperationalTable.vue` |
| Money and counts | `tabular-nums`, right-aligned in tables (`.kp-tabular`) | `pages/Medicine/Index.vue` price column |

- **Do** keep 11 px (`text-[11px]`) for dense secondary lines inside boards only. **Don't** go below 11 px, and don't
  use it for anything a person must act on.
- **Don't** invent title sizes. The survey found four different `h1` styles (`text-2xl`, `text-xl` in
  `pages/Reports/*`, `text-lg` in `pages/ClinicManagement/Index.vue`, `text-[14px]`). Use `PageHeader`.

## 3. Spacing and layout

- Spacing steps: 1, 1.5, 2, 3, 4, 5, 6 (Tailwind units, 4 px each). Inside a panel use `p-4` (phone) to `p-5`/`p-6`
  (desktop); between sections `gap-4`; between a label and its field `gap-2`.
- Radius: `rounded-md` for controls, `rounded-xl` for panels and table frames, `rounded-full` for badges.
- Page frame: `mx-auto w-full px-4 py-4 md:px-5` with one maximum width per page type:
  - **work boards** (Registration, Queue, Panel Claims): full width, up to `max-w-[1600px]` (same as the header);
  - **lists and catalogues**: `max-w-7xl` (`pages/Medicine/Index.vue`);
  - **forms and detail pages**: `max-w-5xl` or `max-w-6xl` (`pages/Staff/Create.vue`).
  Today pages use four widths (5xl, 6xl, 7xl, 1600 px) with no rule; pick from the list above.
- The `.kp-page`, `.kp-section`, `.kp-control-group` classes exist in `app.css` but no page uses `.kp-page` yet. New
  pages may use them; Staff Experience owns making them the default.

## 4. Page header

![Do: one page header with title, short description and one primary action, then the table](img/do-page-header-and-table-light.png)

- **Do** start every staff page with `PageHeader` (`components/ui/page-header/PageHeader.vue`): optional eyebrow,
  one `h1`, one short description line, actions on the right. Good example: `pages/Medicine/Index.vue`
  ("Medicine Catalogue" + "Add Medicine").
- **Do** put at most one `primary` button in the header. Secondary links go in `outline` buttons beside it.
- **Do** keep the description to one sentence. Long rules belong in a collapsible "How this works" block
  (`components/ui/collapsible`) further down. For long text inside a table cell use
  `components/ui/disclosure/DisclosureText.vue` (clamp plus tooltip on desktop, tap to expand on a phone).
- **Don't** place links or buttons *above* the page title. `pages/Inventory/Index.vue` shows five links in three
  different styles (plain underlined, underlined in a box, boxed) before the "Inventory" heading, and a second
  "Return to Workspace" button beside the global one:

  ![Don't: links above the page title in three styles](img/dont-links-above-page-header.png)

- **Don't** ship a page without an `h1`. Registration, Queue and QR intake review (`pages/Registration/Index.vue`,
  `pages/Queue/Index.vue`, `pages/RegistrationReview/Index.vue`) have none, so a screen reader cannot tell which page
  is open (SMOKE-1 S1-009 to S1-014). A board may hide its title visually with `sr-only`, but it must exist.

  ![Don't: board with tabs and filters but no heading, and a jargon empty message](img/dont-board-without-heading.png)

- **Don't** repeat the global header's "Return to workspace" inside the page. When you need a back link, use one
  small `ghost` button with an arrow, as `pages/Registration/Create.vue` does ("Console"). Write it in sentence case.

## 5. Buttons

Variants in `components/ui/button/index.ts`: `primary` (default), `outline`, `secondary`, `ghost`, `accent`,
`destructive`, `link`. Sizes `sm` (32 px), `default` (36 px), `lg` (40 px), `icon` (36 px).

- **Do** one `primary` per area (the next step), `outline` for the others, `destructive` only for actions that
  void, remove, reverse or cancel.
- **Do** show progress in the button: spinner plus a "…ing" label while busy, as `OperationalConfirmDialog.vue` does.
- **Don't** put three or more text buttons in a table row. `pages/Medicine/Index.vue` rows show Edit, Deactivate and
  a third action that is cut off at 1440 px. Use one visible action plus a row menu (`...`), as
  `components/patient-board/PatientBoard.vue` does.
- **Don't** use `accent` (pink fill) for ordinary saves; keep pink for selection and emphasis.

## 6. Tables and their phone layout

Use `components/ui/table/OperationalTable.vue` for every data table: it gives a labelled, keyboard-focusable
scroll region, a hidden `<caption>`, a loading row, an empty row and consistent cell padding.

- **Do** right-align money and counts with `tabular-nums`; keep the status column as a `StatusBadge`.
- **Do** give every table a phone layout below 768 px. KPOne has three good patterns; use the one that fits:
  1. **Row becomes a card** with each cell labelled by `data-label`: `components/patient-board/PatientBoard.vue`
     (scoped CSS at the bottom of the file). Best for boards.
  2. **Separate card list** shown with `md:hidden` while the table is `hidden md:block`:
     `components/registration/QrIntakeTable.vue`, `pages/PanelRemittances/Index.vue`.
  3. **Short list** with `sm:hidden` for a few rows: payments list in `components/billing/BillingWorkspace.vue`.
- **Don't** rely on sideways scrolling as the phone layout for a list people work from. On a 390 px phone the
  Medicine Catalogue shows only Code and Name and hides prices, status and actions off screen. Sideways scroll
  inside the table frame is acceptable only for wide reference tables nobody edits on a phone.
- **Don't** write raw `<table>` markup for new screens. The survey found 28 raw tables against 11 `OperationalTable`
  uses.
- Pagination: `components/ui/pagination/CompactPagination.vue` ("Previous · 6 records · Page 1 of 1 · Next").

![Do: the same page in dark mode, tokens only](img/do-page-header-and-table-dark.png)

## 7. Forms and errors

![Do: phone form with stacked labels and grouped sections](img/do-phone-form-light.png)

- **Do** stack the label above the field, connect them (`<Label for="x">` and `id="x"`), and put the error right under
  the field with `components/InputError.vue`. Good example: `pages/Staff/Create.vue` (fixed in UI-POLISH-1).
- **Do** group fields in titled panels (Identity, Employment, Roles) and put the primary Save at the end of the form
  and, for long forms, also in the header.
- **Do** mark required fields with `*` in the label and say what format is expected in help text under the field
  ("12 digits, no dashes").
- **Do** set `aria-invalid="true"` on a field with an error; the input styles turn the border red automatically
  (`components/ui/input/Input.vue`).
- **Don't** leave fields without a label. The survey found 10 of 13 fields unlabelled on `pages/Pricing/Index.vue`
  and 7 of 23 on `pages/PanelSettings/Form.vue`; a placeholder is not a label.
- **Don't** use placeholder text as the only instruction; it disappears when the person types.
- **Don't** write server-validation messages in internal language. Say what to do: "Enter the 12-digit SSM number."
- Error colour: `InputError.vue` uses `text-red-600 dark:text-red-500`. New code should use `text-destructive`
  (same look, one token). Staff Experience may switch the component.

## 8. Dialogs and confirmations

- **Do** use `components/ui/OperationalConfirmDialog.vue` for every "are you sure" step. It focuses the confirm
  button, blocks closing while busy, shows a spinner label and an inline error, and supports a third choice
  ("Save and hold / Discard and hold / Cancel", OH-06c).
- **Do** write the title as the action ("Void this invoice?") and the description as the consequence in one or two
  sentences. Use `destructive` for irreversible actions.
- **Don't** use the browser's `confirm()`. `pages/Patient/Show.vue` (retire identifier) still does; it cannot be
  styled, is not themed in dark mode and ignores the KPOne focus rules.
- Long tasks (edit a record, choose a batch) go in a `Dialog` or `Sheet` (`components/ui/dialog`, `components/ui/sheet`)
  with a clear title, the form, and Save / Cancel at the bottom right.

## 9. Toasts

- **Do** confirm a finished action with a short toast. The server sends it with
  `Inertia::flash('toast', ['type' => 'success', 'message' => ...])` (see `app/Http/Controllers/QueueController.php`)
  and `resources/js/lib/flashToast.ts` shows it through `components/ui/sonner/Sonner.vue`.
- **Do** write it as a past-tense fact: "Patient called again on the TV."
- **Don't** use a toast for an error the person must fix; show the error on the page next to the cause.
- **Do** show staff toasts at the top, centred, just under the 52 px sticky header (64 px from the top), at every
  width, desktop included (owner decision 2026-11-05, Staff Experience PR #241, UX-1). The placement lives in
  `resources/js/lib/toastPlacement.ts`, used by `ClinicLayout` and `AdminLayout`; don't set a position per page.
- **Don't** let a toast sit on a page's sticky action bar (SMOKE-1 S1-015: a toast covered Complete Consultation at
  390 px). **Don't** pin a new sticky bar at `top-16` (64 px): that is where toasts appear (the Quick create bar
  clashes today).

## 10. Status badges

- **Do** use `components/ui/status/StatusBadge.vue` with a status key; tones and labels come from
  `resources/js/lib/presentation.ts` (Waiting = warning, Serving = accent, Completed = success, Void = danger).
- **Do** add a new status to `presentation.ts` instead of styling a one-off badge.
- **Don't** hand-build badges with palette colours (`bg-rose-100/70 text-rose-700`, `bg-pink-100/70` in
  `PatientBoard.vue`, `statusClass` in `QrIntakeTable.vue`). They look slightly different on every page and need
  separate dark styles.
- Use `components/ui/badge` (`Badge`) only for neutral labels and counts (tab counters), not for status meaning.
- Never rely on colour alone: the badge always carries a word.

## 11. Empty, loading and error states

- **Empty:** `components/ui/state/EmptyState.vue` (dashed panel, title, one line of help, optional action). Say what
  is missing and what to do: "No visits yet today. Register a visit to start." **Don't** write "No Patients match
  this operational view." (`PatientBoard.vue`): it is jargon and capitalises a common noun.
- **Loading:** `components/ui/state/LoadingState.vue` (spinner or skeleton rows, with `role="status"`). It exists but
  no page uses it yet; prefer it over ad-hoc "Loading…" text. Tables use the loading row in `OperationalTable`.
- **Error:** `components/ui/state/ErrorState.vue` (`role="alert"`), and for live boards keep the last good rows with a
  calm notice, as REG-LIVE-1 does in `pages/Registration/Index.vue` ("Live refresh paused. Showing last successful
  results.").
- **Refused (403) pages:** keep the standalone 403 page; do not show a link to a page the person cannot open
  (SMOKE-1 S1-005).

## 12. Focus and accessible names

- **Do** keep the built-in focus ring: `focus-visible:ring-[3px] focus-visible:ring-ring/40` on buttons and inputs,
  `focus-visible:ring-2 focus-visible:ring-ring` on custom links (see `components/workspace/WorkspaceHeader.vue`).
- **Do** give every icon-only button an `aria-label` ("Open navigation", "Open user menu" in `WorkspaceHeader.vue`).
  The survey found one unnamed button on `pages/PaymentMethods/Index.vue`.
- **Do** give every page exactly one `h1`, sections `h2`, and tables a label (OperationalTable does this).
- **Do** mark the current item with `aria-current="page"` in navigation and tabs.
- **Do** make touch targets at least 44 by 44 px on phone screens for row actions (PatientBoard uses `min-h-11
  min-w-11`). Small `sm` buttons are fine on desktop toolbars.
- **Do** close the mobile navigation drawer when a link in it is chosen (SMOKE-1 S1-017). The Sheet in
  `WorkspaceHeader.vue` is controlled (`v-model:open`) and closes on link click. **Don't** close it on every Inertia
  navigate event: live boards refresh themselves and would snap it shut (UX-1, PR #241).

## 13. Dark mode

Every screen must work in light and dark. The theme comes from Settings > Appearance (stored in the browser and a
cookie); `.dark` on `<html>` switches the tokens.

- **Do** use tokens and nothing else; then dark mode is free.
- **Do** check native controls in dark mode. Date and time pickers (`pages/Reports/*`, `pages/PanelInvoices/Index.vue`)
  show a dark calendar icon on a dark field because the app does not set `color-scheme: dark`. Staff Experience owns
  this one-line shell fix.

  ![Don't: long rule text above the form, and date icons that vanish in dark mode](img/dont-wall-of-text-dark-date-icons.png)

- **Don't** use `dark:bg-slate-900` and similar one-off dark colours (`OperationalConfirmDialog.vue` does). Use
  `bg-card` or `bg-popover`.

## 14. Print

- Print views (`pages/Billing/Print.vue`, `pages/Dispensary/Labels.vue`) use the `.document-print` and
  `.label-preview` classes. `app.css` forces white paper and black text in every theme (PRINT-1). Keep it.
- **Do** hide screen-only controls with `print:hidden` (toolbar, help notes).
- **Don't** change the invoice, receipt or label layout without the owner and, for labels, the pharmacist.

## 15. Wording

- **Language:** staff screens are English unless the screen's rule says Bahasa Melayu. Registration and Patient
  screens are English (owner, 2026-11-05); "Panggil semula" stays. Patient-facing QR pages are Bahasa Melayu except
  the REG-2 messages. Drug-label headings are Bahasa Melayu. Never mix both languages in one sentence.
- **Sentence case** for titles, buttons and labels: "Register visit", "Return to workspace". Proper nouns keep
  their capitals (Panel, KPOne, CA). The survey found "Return to Workspace" and "Return to workspace" side by side.
- **Buttons are verbs**: "Save prices", "Run report", "Add panel". Not "OK" or "Submit".
- **Plain English, short sentences.** Explain the result, not the system: "Shows receipts by the day they were
  paid." instead of "original receipt date, not invoice finalisation". Keep internal words out of the UI
  ("canonical", "operational view", "cohort", "projection", "provisioning", "authoritative").
- **Numbers:** money as `RM 0.00` (two decimals, space after RM), dates as `5 Nov 2026` on screens and in the branch
  time zone, with times as `5:04 PM`.
- **Long rules** (the Daily summary page shows about 15 lines before the form on a phone; the text comes from
  `app/Domain/Shared/Services/DailySummaryReportService.php` and is shown by `pages/Reports/BranchReport.vue`):
  one summary sentence on screen, the rest in a collapsible "How this report counts" block.

## 16. Phone rules (320, 390, 768 px)

![Do: phone summary that stacks cleanly](img/do-phone-summary-dark.png)

- **No sideways page scroll at 320, 390 or 768 px.** The page width must equal the screen width. Survey failures:
  `pages/Insights/Today.vue` is 490 px wide at 390 px (ranking cards, SMOKE-1 S1-004) and `pages/Patient/Create.vue`
  is 433 px wide at 390 px (a field grid).

  ![Don't: the Insights page is wider than the phone](img/dont-phone-sideways-scroll.png)

- **Do** stack columns below 768 px: `grid gap-4 md:grid-cols-2`, never a fixed `grid-cols-2` on a form.
- **Tables inside cards on phones** (UX-1, PR #241):
  - **Do** put `min-w-0` on the grid item that holds the card, and set a minimum table width only from `sm` up
    (`sm:min-w-[...]`), never a bare `min-w-[...]`.
  - **Do** on phones let the name column truncate with a `title` tooltip, or wrap with
    `max-sm:[overflow-wrap:anywhere]`.
  - A table of five or more columns may scroll sideways *inside its card*. **Don't** let it make the page scroll.
- **Do** add `min-w-0` to grid and flex children that hold long text, and `truncate` or `break-words` on the text
  (the QR status fix in PR #52 and the Registration result fix in REG-1).
- **Do** let filter rows wrap (`flex flex-wrap gap-2`) and make the Apply button full width on phones if it sits
  alone, as on `pages/Queue/Index.vue`.
- **Do** keep tabs scrollable in one row (`overflow-x-auto`) with the active tab visible; never let tabs push the
  page wider.
- **Do** check the header: the top bar collapses to the menu button and logo below `xl`
  (`WorkspaceHeader.vue`).

## 17. Icons

- Icons come from `@lucide/vue` only. Size `size-4` in buttons and text, `size-5` in the header.
- Decorative icons get `aria-hidden="true"`; an icon that is the only content of a button needs the button's
  `aria-label`.
- The coloured square icon next to a page title (Staff, Patients) is optional; if used, use a token fill
  (`bg-success/10 text-success`), not `bg-emerald-50`.

---

## Appendix: survey notes (2026-11-05)

Screens surveyed (each at 390 and 1440 px, light and dark): Main Menu, Registration board, Register visit,
Consultation queue (CA and doctor), Patient directory, Create patient, QR intake review, Daily cash closing,
Medicine and Clinical Service catalogues, Inventory, Stock review, Pilot readiness, Stock count, Pricing, Queue
display settings, Daily summary, Transaction detail, Pay later to collect, Payment methods, Finance / Billing,
Panel Claims, Panel invoices, Checkout settings, Add panel, Clinic management, Staff directory, Create staff, Insights
Today, AI agents, Business details, Registration settings, Clinician credentials. One Finance Officer URL (Billing
approval limits) correctly returned 403. Boards were empty (the demo seeders create no visits), so the board phone
cards were reviewed in code, not in a screenshot.

Code counts on `main` at `322228f`: 101 page files; `PageHeader` in 18, own `h1` in 65; `OperationalTable` 11 uses
against 28 raw tables; `StatusBadge` 32 uses; `OperationalConfirmDialog` in 10 files; `LoadingState` 0 uses;
browser `confirm()` in 2 files (one is the TV, out of scope).
