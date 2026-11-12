# D1-CLASSIC screenshots (validation only; not for merging)

Returns to check (`/dispensary/returns-to-check`), synthetic preview data: CA Supervisor 2, one pending return.
Widths 1440, 1024, 900, 768 and 390 px, light and dark. Clock frozen. Taken with branch `fix/d1-classic-restore`.

| Folder | What |
|---|---|
| `classic-before/` | main with the page as it was before #546 (D1), Version 2 off |
| `classic-after/` | `fix/d1-classic-restore`, Version 2 off |
| `d1-merged-v2/` | main (D1 merged), Version 2 on |
| `v2-on/` | `fix/d1-classic-restore`, Version 2 on |

`python3 scripts/classic-compare/compare.py classic-before classic-after`: 10 shots, 10 identical.
`python3 scripts/classic-compare/compare.py d1-merged-v2 v2-on`: 10 shots, 10 identical.
