# REG-MON-1 screenshots

Static harness, not the live app: the real built Tailwind CSS with the exact class strings from `main` (before) and
`feature/reg-mon-1-small-fixes` (after), light and dark. The phone select is approximated by a bordered box.
Pages could not be seeded with a waiting visit in this session.

- `board-*-before`: DD-124, the Duration chip wraps onto several lines. No "after" is shown: the fix is held, because
  the chip needs about 172 px and the column is about 100 px at 1280.
- `phone-700-*`: select is 144 px before, 192 px after. `phone-390-*` is unchanged (full width).
- `cancel-390-*`: gap between text and icon is 0 px before, 12 px after.
