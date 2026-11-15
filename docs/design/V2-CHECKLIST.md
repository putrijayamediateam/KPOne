# Version 2 page checklist

One page for every team that draws a Version 2 page (owner decision 2026-11-13: every department starts its Version 2
pages). Tick every line before you send shots. A shot set that breaks a line is sent back without a full review. These
are the defects found again and again in the Registration slices (S1 to S6), so each line names the page where it was seen.
The look itself is `GUIDE.md` section 19. The pattern to copy is the Registration board, Register visit, Visit and
Edit visit pages.

## Shots to send

- Real Version 2 shell (Version 2 switched on through the real dispatch), never the Classic shell with a forced attribute.
- Seeded, realistic data: several rows, one long name, one waiting row at 30 and one at 60 minutes or more, one empty state,
  one error state. A one-row board proves nothing.
- A new pattern or a new page: 1440 and 390, light and dark, plus 1024 and 1280 when there is a table or a rail, plus every
  menu, dialog and sheet open once. A page that reuses an approved pattern: 1440 light and 390 dark are enough.
- Money, clinical and queue-control pages always get the full set, even when they reuse a pattern.
- Say which shots you opened and which you did not. Name the Classic page and say it is unchanged (the compare).

## Layout

- [ ] One cobalt filled button on the page. Everything else is outline, ghost or a text link. One filled destructive button,
      only inside a confirm dialog (Registration: Cancel visit).
- [ ] Page gutter 16 px on phones and 24 px from 1024. Nothing touches the screen edge. No sideways scroll at 320, 390, 768,
      1024, 1280 and 1440.
- [ ] Title 24 px on phones and 30 px from 1024 (`V2WorkLayout`). One description line. The crumb sits above the title through
      `PageFrame` / `usePageCrumbs`, never a link row.
- [ ] A rail only from 1536 px. Below that, the rail content becomes one disclosure bar above the table (Registration: needs attention).
- [ ] Forms and detail pages with a side column: the rail starts at 1280 px at the earliest (never at 1024: the sidebar leaves the main column too narrow); below it the rail content is the last card of the main column, and the action is the sticky bar at every width below the rail.
- [ ] Tables become cards below 1280 px when a sidebar is showing. No Actions column pushed out of view at 1024 or 1280.
- [ ] A phone card is 250 px or less for a normal row. Label and value in two columns, values right-aligned, nothing empty.
      Show a field only when it has something to say (Payment only for Panel).
- [ ] The danger card (Cancel, Delete) is last on a phone.
- [ ] The sticky action bar on a phone is one full-width 44 px button. The page leaves room so the last field is never behind it.
- [ ] Filters: search plus one "Filters (n)" button on a phone, opening a sheet with every filter, Reset and Apply.

## Controls

- [ ] Every target is 44 px below 1024 and 36 px from 1024.
- [ ] Every field has a visible label, sentence case. No placeholder-only field. Required marks with a star.
- [ ] No native select or native file input. Use `OperationalSelect`, `V2OptionGroup`, `V2ChipChoice`, `FileChooseButton`.
- [ ] Optional fields go behind "More details (optional)", unless a setting makes them required (then they show).
- [ ] Row menu: sentence case, grouped (Open, Move, Call, Money, then Danger), from server flags only, a disabled item shows its reason.
- [ ] A save button is cobalt only when it would do something. An outline button when the form is clean.
- [ ] Dialogs are `V2Dialog`: a bottom sheet below 640 px, a required reason when the action needs one, and a Confirm that stays
      pressable (aria-disabled) and shows the error on press.

## Words and numbers

- [ ] No system text: no codes (`SUNGAI_BESI`, `inventory.stock_counts`), no ISO dates, no lowercase enums ("female"). Branch names
      and dates the way staff read them ("3 Feb 1991 · Female", "12 Nov 2026, 4:53 PM").
- [ ] Status is a dot plus a word (`V2Chip`), never colour alone. A waiting time uses `WaitRing` with words ("72 min · very long wait").
- [ ] A chip never wraps inside itself. Long text under it wraps on its own line.
- [ ] Every sentence of help text is said once. No label that repeats its helper.
- [ ] Bahasa Melayu or English follows the surrounding screen. Do not mix inside a sentence.

## Accessibility

- [ ] Text 4.5:1, non-text and focus ring 3:1 against what is behind them, in light and dark. Filled buttons use the ring offset (DD-74).
- [ ] Every control reachable by keyboard, with a visible focus ring. Menus and dialogs trap and release focus, and Esc closes them.
- [ ] Motion stops with reduced motion (DD-55).
- [ ] Icon-only controls have a spoken name. Errors use `InputError` (one live region).

## What a reviewer then checks (lighter pages)

The title and gutter, the one filled button, the 1024 and 390 layouts, the menu and dialog states, the words, dark mode, and that
Classic is untouched. A page that adds a new block, a new rail, a new table pattern, or touches the shared shell is a full review.

## Settings switches

A switch row only changes the form value. The page decides whether it saves at once or with its own Save. A page that saves at once must show a visible result ("Saved"). A switch with a privacy or money effect (for example showing full patient names on the waiting-room TV) needs the page's own confirm dialog that says who sees what or what changes.

## Money, times and dates

A money box states the amount with an RM prefix and shows two decimals after blur; paste rules never guess ("1,25" is refused, not read as 125.00). A read-only price elsewhere on the page groups thousands ("RM 1,250.00"). A disabled price box shows the reason as text. A time field never guesses AM or PM: a bare hour such as "5" is refused with "Add AM or PM" unless the text is clearly 24-hour. Any date that decides access or money shows the chosen date in words under the field ("From 13 Nov 2026"); native "mm/dd/yyyy" boxes are not enough alone.

## Rails and buttons

Inside a rail, never switch layout by viewport width: use a container query or stack the rows (name line, then chips). A name gets the full width of its line and wraps by word. A button's words say what it does ("Show in list"), not a bare "View". A page's one filled action appears exactly once at every width: in the header or in the sticky bar, never both. If the header carries the action from 640 px, the page passes hideFrom so the bar disappears at the same width.
