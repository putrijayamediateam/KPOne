<?php

/*
|--------------------------------------------------------------------------
| Version 2 pages
|--------------------------------------------------------------------------
|
| A Classic Inertia page name listed here has a Version 2 page at the same place with `V2` before the last segment:
| `Registration/Index` is replaced by `Registration/V2/Index` (resources/js/pages/Registration/V2/Index.vue) for a
| person who switched on "Try the new layout". Same props, same routes, same permissions. A page that is not listed is
| never replaced. A test checks that every listed page has its V2 file and every V2 file is listed.
|
*/

return [
    'pages' => [
        'Registration/Index',
        'Registration/Create',
        'Registration/Show',
        'Registration/Edit',
        'PaymentMethods/Index',
        'Pricing/Index',
        'Agents/Inbox',
        'Patient/Show',
        'ClinicManagement/Index',
        'Queue/Index',
        'Patient/Index',
        'QueueDisplay/Settings',
        'Inventory/Index',
        'InventoryServicesSettings/Suppliers',
    ],
];
