# KPOne clickable prototype

A clickable mock-up of KPOne's staff screens, built from the design canvas
"KPOne, clean white look: mock-up only, fictional data". It is a design reference only:
nothing here is application code, and every name, number and record is fictional.

## What is here

| Path | What it is |
|---|---|
| `project/*.dc.html` | One Design Component file per screen (154 screens plus the shared `Sidebar`). |
| `project/canvas.json` | The canvas index (board titles, order and area notes) from the original design. |
| `src/specs_a.py` to `src/specs_d.py` | Short descriptions of the 47 screens added after the canvas (listed in `src/new_screens.json`). |
| `src/gen.py` | Turns a spec into a `.dc.html` screen with the same shell, cards, chips and sidebar as the hand-made ones. |
| `src/gen_all.py` | Regenerates every spec'd screen into `project/`. |
| `src/build.py`, `src/shell.html` | Bundle all screens into one self-contained `kpone-designs.html`. |
| `src/label_map.json` | Routes buttons that have no behaviour of their own to a screen, by their label. |
| `src/dc-runtime.js` | The Design Component runtime (bundles React 18, MIT licence) that renders the screens. |
| `src/test_all.cjs` | Opens every screen in Chromium and reports render errors. |

The original 107 screens (and `Sidebar.dc.html`, whose links were extended so every item opens a
screen) come from the canvas. Edit those `.dc.html` files directly. Edit the 47 NEW screens through
their specs, then regenerate.

## Build the single HTML file

```bash
cd docs/design/prototype/src
python3 gen_all.py
python3 build.py
```

This writes `docs/design/prototype/kpone-designs.html` (about 3.9 MB, git-ignored). Open it in a browser:
the left list groups screens by area and marks NEW ones, and the right side is the live screen.
Only Google Fonts needs the internet.

## Check it

```bash
NODE_PATH=$(npm root -g) node docs/design/prototype/src/test_all.cjs "$PWD/docs/design/prototype/kpone-designs.html"
```

Expect `problems 0`. Playwright with Chromium must be installed.

## Adding a screen

1. Add a spec to one of the `specs_*.py` files. The kinds are `list` (stat strip, table with selectable
   rows, detail panel), `form` (numbered sections of fields, choices and switches, with a summary) and
   `doc` (printable A4 sheet). `public=True` drops the sidebar for patient- or applicant-facing pages.
2. Link it from `project/Sidebar.dc.html` and, if a button label should open it, from `src/label_map.json`.
3. Run `gen_all.py`, `build.py` and the test.

## Rules that still apply

- Fictional data only: `.test` emails, masked identifiers (`•••• 4417`) and placeholders such as `[CLINIC ADDRESS LINE]`.
- Patient-facing wording is in Bahasa Melayu (see `CLAUDE.md`).
- A mock-up does not authorise building a feature. `docs/BASELINE.md` decides what is authorised.
