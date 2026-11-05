# Pre-hand-off design checklist

For every team designer, before a PR that adds or changes a screen goes to LUCY. Tick each line or write why it does
not apply. Rules and examples are in [GUIDE.md](GUIDE.md) (section numbers in brackets).

## Screenshots (attach to the PR body)

- [ ] 390 px and 1440 px, light and dark, for every new or changed screen (Staff Experience also 320 and 768 px).
- [ ] Synthetic `.test` data only: no real names, IC numbers, phones or emails in any image.
- [ ] One screenshot shows the empty state, and one shows an error state if the screen has a form.

## Page

- [ ] Exactly one `h1`, using `PageHeader`; nothing placed above it. (4)
- [ ] One short description line; long rules are in a collapsible block. (4, 15)
- [ ] At most one `primary` button per area; no duplicate "Return to workspace". (4, 5)
- [ ] Page width follows the page type (board, list, form). (3)

## Colour and dark mode

- [ ] Tokens only (`bg-card`, `text-muted-foreground`, `text-destructive`...). No new `bg-*-50`, `bg-white`, `slate-*`
      without a matching `dark:` class, and preferably none at all. (1, 13)
- [ ] Pink text uses `text-brand-strong`, not `text-brand`. (1)
- [ ] Dark screenshot checked for pale panels, invisible icons and native date or select controls. (13)

## Tables, lists and phone

- [ ] Tables use `OperationalTable` (label, empty row, loading row). (6)
- [ ] Below 768 px the list becomes cards or a short list; key columns and actions stay visible. (6)
- [ ] No sideways page scroll at 320, 390 and 768 px (page width equals screen width). (16)
- [ ] Tables inside cards: `min-w-0` on the grid item, table minimum width only from `sm` up. (16)
- [ ] Row actions: one visible action plus a `...` menu; phone targets at least 44 by 44 px. (5, 12)
- [ ] Money and counts right-aligned with `tabular-nums`; money as `RM 0.00`. (2, 15)

## Forms

- [ ] Every field has a visible label connected with `for` / `id`; required fields marked `*`. (7)
- [ ] Each error sits under its field (`InputError`) and says what to do. (7)
- [ ] Fields stack on a phone (`grid gap-4 md:grid-cols-2`). (16)

## Feedback

- [ ] Confirmations use `OperationalConfirmDialog`, never browser `confirm()`. (8)
- [ ] Success is a short past-tense toast at the top (shared placement, no per-page position); errors stay on the
      page; no sticky action bar at 64 px or under the toast. (9)
- [ ] Statuses use `StatusBadge` with a word, keys added to `lib/presentation.ts` if new. (10)
- [ ] Empty, loading and error states use the `components/ui/state` components. (11)

## Access

- [ ] Icon-only buttons have an `aria-label`; decorative icons `aria-hidden`. (12, 17)
- [ ] Keyboard: every control reachable with Tab, focus ring visible in both themes. (12)
- [ ] Current navigation item or tab has `aria-current`. (12)

## Wording

- [ ] Right language for the screen (staff English unless the screen's rule says Bahasa Melayu; never mixed). (15)
- [ ] Sentence case; buttons are verbs; no internal words ("canonical", "operational view", "projection"). (15)

## Print (only if the PR touches a print view)

- [ ] Paper stays white in dark mode; screen-only controls have `print:hidden`; layout change approved by the owner.
      (14)

If any line fails and you keep it on purpose, say so in the PR body and add it to the design debt list (DES-2).
