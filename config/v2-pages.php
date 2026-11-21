<?php

/*
|--------------------------------------------------------------------------
| Version 2 pages
|--------------------------------------------------------------------------
|
| A Classic Inertia page name listed here has a Version 2 page at the same place with `V2` before the last segment:
| `Billing/Show` is replaced by `Billing/V2/Show` (resources/js/pages/Billing/V2/Show.vue) for a
| person who switched on "Try the new layout". Same props, same routes, same permissions. A page that is not listed is
| never replaced. A test checks that every listed page has its V2 file and every V2 file is listed.
|
*/

return [
    'pages' => [],

    /*
    |--------------------------------------------------------------------------
    | Public (patient-facing) Version 2 pages
    |--------------------------------------------------------------------------
    |
    | Version 2 for the staff pages follows each signed-in person's own "Try the new layout" switch. A patient has no
    | account, so the public QR pages cannot work that way: they have one switch for everybody, and it is OFF. While it is
    | `false`, every request to a page listed in `public_pages` gets the Classic page, signed in or not. The owner turns it
    | on (changes this value to `true` and deploys) only after saying "turn on new QR pages". Even then, a page is swapped
    | only when its V2 file exists at `resources/js/pages/<Dir>/V2/<Name>.vue`.
    |
    */

    'public' => false,

    // A page is listed here only once its V2 file exists (V2PageDispatchTest checks both ways).
    'public_pages' => [],
];
