# V2 public QR intake form (PublicCheckIn/V2/Show) evidence

Synthetic props, 12 states (welcome, token-exchange error, step 1 who, step 2 empty errors and filled, step 3 patient and guardian, step 4 Panel coverage, step 5 review, consent error, session warning, submit error) at 1440 (taken before the 320 width fix, which does not change 1440), 390 and 320 px, light and dark.
Checks on every state: no staff-shell elements or text, no non-same-origin requests, no sideways page scroll. Storage: only the app's own `kpone:authentication-history` keys, which are also present on the login page; the form writes none.
Switch OFF: Classic `PublicCheckIn/Show.vue` is byte-unchanged vs main; with `v2-pages.public=false` the dispatch returns Classic (V2PublicPagesSwitchTest).
