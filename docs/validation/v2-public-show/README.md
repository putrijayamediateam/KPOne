# V2 public QR intake form (PublicCheckIn/V2/Show) evidence, after Daniel's review

Synthetic props, 320 / 390x740 / 1440, light and dark. Fixed clock off; reduced motion emulated, CSS animations frozen.
- `v-*` files are VIEWPORT shots (not full page): `v-s2-scrolled-middle` (bar in view), `v-s2-keyboard-short-viewport` (visual viewport halved; the focused field clears the bar: field bottom 258 <= bar top 293 at 390), `v-s1/s3/s4/s5` (one filled button per step, bar at the viewport bottom, buttons 48 px).
- `s2-ic-12-digits-320-*`: the ID type sits above the number below 400 px; all 12 digits show.
- `s5-double-submit-*`: busy state "Menghantar…", button disabled; exactly 1 POST after two clicks.
- `s5-network-error-beside-button-*`: refused/failed send, message beside the button, button back to normal.
- `s3-guardian-errors`, `s4-panel-long-selected`: the longest steps at 320.
Switch OFF: Classic Show.vue byte-unchanged; pixel compare main vs branch, Classic welcome, step 2 errors, step 2 filled at 390 and 320, light and dark, frozen clock: 12 compared, 0 differing pixels.
