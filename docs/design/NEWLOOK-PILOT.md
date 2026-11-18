# New look (preview): design rules for the pilot

Status: **approved by the owner 2026-11-18; dark values still to measure; font package to be named by Staff
Experience.** Step 1 of the pilot. Written by the Lead UI/UX Designer for B.E.R.N.I.S.S and the Staff Experience team.
The owner's three OKs: one black main button per view with quieter row buttons and cobalt kept for links and the focus
ring; Geist self-hosted, never from Google; dark mode ships together with light. The note itself authorises no build
beyond the pilot scope in section 1.

Source: the owner's mock-up "KPOne Clean Look" (fictional data), boards Today (modern), Front desk (Pipeline), Billing,
Consultation, Settings, Sidebar and the Billing function map. The owner's words: "Apple-like, mostly white, modern".
We take the ideas, not the page code. Contrast numbers below are **computed** from the mock-up's hex values; anything
marked "to measure" is not measured yet.

## 1. Scope and how it switches on

- A per-person switch **"New look (preview)"**, default **off**. Classic stays Classic, Version 2 stays Version 2.
- Pilot screens only: (a) the shell (sidebar and top bar), (b) Today, (c) Front desk board with the Pipeline.
  Every other page keeps its current look, so the shell must frame an old page without clashing (see section 7).
- No permission, route, payload, validation or business-rule change. A restyle never changes who sees what.
- Technical rule (same as section 19 of GUIDE): new tokens live under a new attribute (suggest
  `<html data-ui="v2" data-look="new">`), shared components add `look-new:`-prefixed classes and keep their old ones.
  With the switch off, a screenshot test must show **no pixel change** in Classic and V2.
- Every pilot page has a visible way back ("Back to the current look" in the account menu).

## 2. Tokens

Values from the mock-up, adjusted only where a contrast check failed. Names map onto the existing token names so no
page ever writes a hex.

| Token | Light | Dark (proposed) | Notes |
|---|---|---|---|
| `background` | `#f4f4f6` | `#0f0f12` | Page |
| `card` | `#ffffff` | `#1a1a1f` | Cards, rows, panels |
| `card-quiet` (row inside a card) | `#fafafb` | `#222228` | One level only |
| `foreground` | `#111114` | `#f4f4f6` | Text (18.9:1 on white; 15.8:1 on dark card) |
| `muted-foreground` | `#6b6b73` | `#a8a8b3` | Help, metadata. 5.3:1 on white, 4.8:1 on `#f4f4f6`; dark 7.4:1 |
| `border` / hairline | `rgba(17,17,26,.06)` ring | `#2a2a31` ring | See "Hairline" below |
| `primary` (the one main action) | `#111114` fill, white text | `#f4f4f6` fill, `#111114` text | Black button, owner's choice |
| `link`, `focus ring` | `#0a56d1` (existing cobalt) | `#6ba6ff` | **Keep cobalt for links and focus**, so keyboard users still see a colour ring on black buttons |
| `brand` (pink) | `#c2185b` | `#ff6b9d` | Logo mark, hero gradient, small accents. 5.35:1 on `#f4f4f6` |
| `warning-text` | `#8a4b00` or darker | `#ffb454` | Mock's `#b25e00` is only 4.67:1 and only on white; use the darker one |
| `success`, `destructive` | existing V2 values | existing V2 values | Status meaning, always with a word |
| status dot colours | see section 4 | | Never the only signal |

**Hairline.** The mock-up's `rgba(17,17,26,.04-.06)` ring is almost invisible and does not meet the 3:1 rule for the
edge of a control. Cards may keep it (a card edge is decoration). **Fields, outline buttons and selected states must
use a visible edge: `#8a8a93`-grade or darker, at least 3:1** (the existing V2 `border` token already does this; reuse it).

**Radii.** Hero card and big tiles 26 px; cards 20 to 22 px; rows 18 px; inner chips 14 px; buttons, fields and chips in
rows **10 to 14 px** (not full pill, except tabs and counts); pills for segmented tabs and the search bar. Floating
panels 26 px. This is larger than GUIDE section 19, so it is the *new look's* scale, not a change to V2.

**Shadows.** Cards: ring only. Hover on desktop cards: `0 12px 28px rgba(17,17,26,.08)`. Floating bar and panels:
`0 8px 30px rgba(17,17,26,.10)`. Dark: `rgba(0,0,0,.5)` at the same sizes. No shadow on rows inside cards.

**Type.** Geist (self-hosted, see "Fonts"), Inter then the system stack as fallback. Scale: page title 28/32 (30/36 from
1024), 600 weight, tracking -0.02em; hero greeting 30; section title 18, 600; card label 13, 500, muted; body 14/20 in
dense lists, 15/22 elsewhere; big hero number 56, secondary figures 28 to 34, 700, **tabular figures** for money,
times and counts. Minimum text size 12 px, and 12 px text must reach 4.5:1.

**Gradient rule.** *One* pink-to-violet gradient card per page, the page's hero (Today) or the selected-patient header
(Front desk). Gradient: `radial-gradient(120% 140% at 0 0, #e0457f, #c2185b 38%, #7a2bd1)`.
Text on it is **white at full opacity** and 14 px or larger (white on `#e0457f` is only 3.9:1, on `#c2185b` 5.9:1, on
`#7a2bd1` 6.8:1). Put text over the darker part, or darken the light stop to `#d02a6c` (to measure). The mock-up's
`opacity .85` captions fall to 3.2:1 on the light corner and must not be used. No gradient anywhere else, including
buttons, chips, tab fills and page backgrounds. The soft "AI" strip with a faint gradient is **not** copied.

**Motion.** Hover lift `translateY(-2px)` only on cards that are links; none in lists of rows; all of it off under
`prefers-reduced-motion`. Slide-in panel 200 ms.

**Fonts.** The mock-up loads Geist from Google Fonts. **Do not do that in KPOne**: it sends every staff member's IP to a
third party. Self-host the Geist variable font (SIL OFL licence, woff2, `font-display: swap`) as a project asset.
Whether to add the font package is a dependency decision for Staff Experience and the owner.

## 3. Components

All components need: real `button`/`a`/`input` elements, a visible focus ring (cobalt, 2 px, offset 2), 44 px hit area
below 1024 px, and a name for every icon-only control.

- **Card.** White, radius 20 to 22, ring hairline, padding 20 to 22. A card holds rows, not cards. The hero card is the
  only gradient card.
- **Row** (visit, approval, branch). Radius 18, 12 to 16 px padding, quiet fill `#fafafb` inside a card or white with a
  ring on the page. The row's **primary action is an outline or text button**, never black: a list of 25 rows must not
  have 25 black buttons. The mock-up's black "Approve" on every approval row is **not copied**. One black button per
  view; on Today that is none, or the single bulk action.
- **Pill tabs / segmented control.** Track white with ring, padding 4, selected tab black with white text, others
  transparent. Use `role="tablist"` when it switches a view, `aria-pressed` buttons when it filters. Count in a small
  badge inside the tab. Selected state is the black fill **plus** `aria-selected`, never colour alone.
- **Status dot.** 8 to 9 px circle with a soft ring (the "glow") **next to the status word, always**. The glow is
  decoration. Dot colours on white are mostly below 3:1 (`#ff9f0a` 2.1:1, `#34c759` 2.2:1), so the dot alone is
  never the meaning, and the word carries the status. Suggested set (dot + word): Waiting amber, Long wait red, With
  doctor blue, Treatment violet, Pharmacy indigo, Payment pink, Done green, On hold violet. Use the existing V2 status
  words and their order.
- **Pipeline** (Front desk). Seven stage buttons in one row: label with dot, a **count** (30 px, 700), a one-line note.
  Selected stage: black fill, white text, note at 75% white (10.8:1, fine). Chevrons between stages are decoration
  (`aria-hidden`). Below 768 px: horizontal scroll with a right-edge fade, 44 px targets, the selected stage scrolls
  into view (same as the Queue tabs rule). `aria-pressed`; the list below it carries the stage name as its `h1`/`h2`.
  A warning note ("avg 24 min, 1 long") needs the dark-amber text and a word, not just colour.
- **Sidebar, two parts.** Rail 72 px wide: logo, area icons (44 px buttons, `aria-label` and tooltip with the area
  name), a **count badge** only where the number is actionable, the person's avatar at the bottom. Panel about
  248 px: area title, the area's pages as rows with optional count and a **"+" quick action** (a real button with its
  own name, e.g. "Register visit"), and the branch and duty status at the foot. Click an area icon to switch, click
  again to close; the open or closed state is remembered per person, and below 1024 px the panel is an overlay with a
  scrim and Esc to close. **Keyboard: Tab order rail then panel; arrow keys inside the rail are optional.** The
  current page is marked with a fill **and** `aria-current="page"`. The rail icons need a visible label on hover and
  focus, since icons alone are not enough.
- **Top bar.** Floating, translucent (`rgba(244,244,246,.78)` with blur) with a hairline. Contents: breadcrumb (page
  title as text, not only on the bar), search/command pill, notifications, one page action (the page's black button
  if it has one). The **⌘K** hint reads **Ctrl K on Windows**; detect the platform, and the shortcut also needs a
  visible, focusable search button. Requirements: a solid background fallback when `backdrop-filter` is missing or the
  person prefers reduced transparency; `scroll-padding-top` equal to the bar height so focused fields are not hidden
  behind it; text and icons keep 4.5:1 and 3:1 against whatever scrolls under the bar (to measure on a busy page).
- **Slide-in panel** (forms and the selected-patient detail). A real dialog: focus moves in, is trapped, returns to the
  opener on close, Esc closes, a visible Close button. Width 360 to 420 px from 1024 px (a non-modal side panel on
  Front desk, as in the mock-up); a full-height sheet below 1024 px. **Unsaved input is never lost on an accidental
  close**: same confirm rule as the other dialogs. A panel never holds a destructive confirm; a confirm is its own
  dialog.
- **Allergy and warning strip.** Dot + bold word ("Allergy: Penicillin"), tinted fill, 4.5:1 text; the mock-up's
  `#a1281c` on `#fff1f0` is 6.7:1.

### Named exceptions to "one black main button per view"

- **Me: the Clock in ring button stays cobalt** (`#0a56d1` light, `#6ba6ff` dark), with its existing soft shadow. It is Me's
  signature action and the only filled control on that page, so there is no second primary to compete with. Any other
  filled button on Me is black. Recorded 2026-11-18 at Kavitha's request (New look step B).
- Add further exceptions here only with the owner's OK, one line each, with the reason.

### Measured contrast (step B, rendered tokens, 2026-11-18)

Measured by the Staff Experience team from computed styles with the look on (Chromium, demo CA). Replaces the
"to measure" note for the shipped tokens: every text pair is 4.8:1 or better; the field edge on a card is **3.43:1
(light) and 3.02:1 (dark)**, which passes but has almost no margin. Do not put a field on a lighter or darker surface
than its card, and do not lighten a dark card, without measuring again.

## 4. Today (pilot b)

- Four-column grid from 1280 px: hero (span 2) + two stat cards, then "Needs your approval" (span 2, tall) + "Clinic
  right now" (span 2) + two small cards. Two columns at 768 to 1279 and one column below 768, hero first.
- Every figure comes from data the person is already allowed to see. **Sections follow the existing permissions**, so a
  CA sees no revenue card. An empty section says why ("Nothing waiting for approval"), it does not vanish.
- The mock-up's "Collected today", payroll approvals and per-agent text are **content of the owner's mock-up, not
  approved features**: build only the sections that already exist as data sources today.
- Charts: sparkline and ring need a text equivalent (`role="img"` with the value as a sentence, as the mock-up does)
  and the figure beside them. The green "+5%" chip needs a word ("up 5%"). Chart colours follow the dataviz rules
  (not red/green only).
- The "usual Wednesday is 140 patients" comparison needs a stated basis, or it is not shown.

## 5. Front desk (pilot c)

- Pipeline row (section 3), then the visit list (rows: queue number, avatar initials, name, doctor and payment, status
  with word, time) and the selected-patient panel on the right from 1280 px. Below 1280 the panel opens as a sheet.
- **Server rules unchanged.** The Registration board's 10-second refresh, filters, tabs and permissions stay exactly as
  they are; the Pipeline is a new presentation of the same tabs and counts.
- In the panel, **one black button** ("Call in to Room 1" or the next valid action for that person). "Not ready" and
  "Edit visit" are outline. Role and branch rules decide which show, as today.
- Patient identity stays masked as today (the mock-up's "•••• 4417" is the right idea). No new patient field is added
  by the restyle.

## 5b. Space rules (no empty screen)

Requested by the owner on 2026-11-18 ("how to stop empty space"). Applied by B.E.R.N.I.S.S to the HR and Stock
mock-ups; recorded here as rules for the pilot and for every page restyled later.

1. **Columns fill the screen height.** A long list scrolls inside its own card, not the page. The scrolling card is
   keyboard reachable (`tabindex="0"`, a name such as "Visits, scrollable"), keeps a visible focus ring, and shows a
   fade or shadow at the cut edge. Below 1024 px there is **one** scroll, the page's; no scrolling card inside a
   scrolling page on a phone.
2. **Lists show as many 44 px rows as fit** (about 12 to 15 at 1080 px tall). Paging only past that. Row count comes
   from the available height, but the server page size stays as it is today (no payload or limit change in a restyle).
3. **Side columns have a fixed width** (about 240 to 320 px). The middle work area takes all the rest. **Exception:**
   long text, forms and settings fields keep a readable line length (about 72 characters) *inside* the work area, so a
   wide screen never means a 1,200 px-wide input. The tables and boards themselves have no max-width.
4. **The big gradient banner is for Today only.** Work pages use a **slim stat strip** (about 56 px: three or four
   figures with words). This keeps the "one gradient per page" rule: on Front desk the gradient stays on the selected
   patient's panel header, not on a banner.
5. **Busy pages open with the sidebar on the 72 px rail only:** Consultation, Billing, HR roster. The person can open the
   panel, and the choice is remembered per person.
6. **Three columns from 1280 px, two from 1024 px** (the right column drops under the middle one), **one on phones.**
   Columns never reorder what a keyboard or screen reader reads: the DOM order is main work area, then side columns.
7. **An empty card shrinks to one line** that says why ("No approvals waiting"). It never keeps its full height, and it
   never disappears without a word.

**Check:** at 1440 x 900 and 1920 x 1080, no more than about **15% of the screen is blank** below or beside the
content, counted on the real page with realistic data (and with the empty-state version too). Shots at both sizes are part
of Design's review, together with 768 and 390.

## 6. Contrast and dark mode

- Computed ratios (light): muted text `#6b6b73` 5.3:1 white, 4.8:1 on `#f4f4f6`; `#8a8a93` text 3.4:1 and 3.1:1 and
  must **not** be used for text; `#c4c4cc` 1.7:1 only for decorative dividers and chevrons; white on `#111114` 18.9:1;
  success `#1f7a3d` on `#e7f6ea` 4.8:1; `#6b6b73` on `#fafafb` 5.1:1.
- Dark mode is **not designed in the mock-up**. The values above are a proposal: muted `#a8a8b3` 7.4:1 on the dark card,
  `#8b8b96` 5.1:1 (the lowest allowed muted), pink `#ff6b9d` 6.5:1, amber `#ffb454` 9.8:1, green `#4ade80` 9.9:1,
  cobalt `#6ba6ff` 7.0:1. The hairline `#2a2a31` on `#0f0f12` is only 1.3:1, so dark fields and outline buttons need
  a lighter edge (use the existing V2 dark `border`). The hero gradient keeps the same hue, one step darker
  (e.g. `#a3184f` to `#4b1a8f`, to measure). Dark ships **in the same release** as light; a pilot with no dark mode is
  not accepted (DD-07 was a Blocker once).
- Every pilot screen is checked at 1440, 768 and 390 in light and dark, with the shots reviewed by Design.

## 7. What NOT to copy from the mock-up

1. A black button on every row (one black button per view).
2. Dot-only status, or colour-only status. The word always stays.
3. Muted text in `#8a8a93` and lighter, white text at 85% on the pink end of the gradient, a 1 px hairline as the only
   edge of a field.
4. Gradient anywhere but the one hero. No gradient strip for "AI prepared" messages.
5. Loading fonts from Google, or any third-party request.
6. Hover lift on rows; the card lift is for link cards only.
7. The ⌘K hint on Windows laptops; a search that exists only as a shortcut.
8. Fictional figures, names and features as if they existed: payroll approval tile, "stock agent" card, "Collected
   today", "average wait" and "usual day" numbers. Build from real data sources only.
9. A floating bar that hides focused fields or turns unreadable over busy content.
10. A full-screen rebuild. Pages outside the pilot keep their current look and must still sit well inside the new shell:
    the new shell frames an old page on the same `#f4f4f6` background with the page's own card styles untouched, and the
    sidebar shows the old page's name and area. If an old page looks wrong inside it, the shell, not the page, is fixed.
11. A new look for the TV screen, the public QR pages, printed documents and labels: **not restyled**, as today.
12. Anything that changes data, wording of a clinical or money action, or a permission.

## 8. Pilot acceptance (Design's checklist)

- [ ] Switch off: no pixel change in Classic and V2 (screenshot comparison).
- [ ] One black button per view; row actions outline.
- [ ] Every status shows a word; no dot-only status.
- [ ] Text contrast 4.5:1 (3:1 for 24 px and larger), measured, light and dark.
- [ ] Focus ring visible on every control, including on the black buttons and over the translucent bar.
- [ ] 44 px targets below 1024 px; no sideways scroll at 320, 390 and 768.
- [ ] Rail icons named; the panel, search and slide-in reachable by keyboard; Esc closes overlays; `aria-current` set.
- [ ] Reduced motion and reduced transparency respected.
- [ ] Section visibility follows existing permissions (check as CA, CA Supervisor, Director, Finance Officer).
- [ ] Fonts self-hosted; no third-party request.
- [ ] Old pages still look right inside the new shell at 1440, 768, 390.
- [ ] Space rules (section 5b) met: at 1440 x 900 and 1920 x 1080 no more than about 15% blank; lists scroll inside their card; slim stat strip on work pages; empty cards one line.
- [ ] Back to the current look works from every pilot page.
