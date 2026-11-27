# REG-MON-1 screenshots

Static harness, not the live app: the real built Tailwind CSS with the exact class strings from `main` (before) and
`feature/reg-mon-1-small-fixes` (after), light and dark. The phone select is approximated by a bordered box.
Pages could not be seeded with a waiting visit in this session.

- `board-1280-*` and `board-1440-*`, before and after (DD-124): the Duration chip wraps before and stays on one line after
  (24 px high instead of 64 px). After: Patient 14, Queue 7, Arrived 9, Visit notes 8, Doctor 14, Payment 12, Duration 15,
  Status 15 (%). The long doctor name still takes 2 lines with no clamp.
- `board-longnote-1280-*`: a long visit note. The harness does not apply the real clamp-2 and expand control, so the note
  wraps fully here; on the real board it shows two lines.
- `phone-700-*`: select is 144 px before, 192 px after. `phone-390-*` is unchanged (full width).
- `cancel-390-*`: gap between text and icon is 0 px before, 12 px after.
