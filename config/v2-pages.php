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
        'PanelClaims/Index',
        'PanelClaims/Create',
        'PanelClaims/Show',
        'ClinicalService/Index',
        'Dispensary/Show',
        'Dispensary/Completed',
        'Dispensary/ReturnsToCheck',
        'Medicine/Index',
        'PaymentMethods/Index',
        'Pricing/Index',
        'Agents/Inbox',
        'Agents/Index',
        'Patient/Show',
        'HR/Roster',
        'HR/Attendance',
        'HR/Leave',
        'ClinicManagement/Index',
        'Queue/Index',
        'Patient/Index',
        'Patient/Edit',
        'Patient/MergeRequests',
        'Patient/Create',
        'RegistrationReview/Show',
        'BusinessDetails/Index',
        'QueueDisplay/Settings',
        'Inventory/Index',
        'InventoryServicesSettings/Suppliers',
        'Staff/Index',
        'InventoryServicesSettings/MedicineDetails',
        'RegistrationSettings/Index',
        'ConsultationSettings/Index',
        'Pricing/PriceCheck',
        'Pricing/PriceHistory',
        'Staff/Show',
        'Staff/Create',
        'Staff/Edit',
        'Billing/Show',
        'Agents/Assistant',
        'Agents/DailySummary',
        'Inventory/TreatmentUsage',
        'Agents/Office',
        'Inventory/GovernedStock',
        'Clinical/Show',
        'Inventory/BatchHolds',
        'Inventory/Recalls',
        'Inventory/ControlledRegister',
        'Inventory/ControlledRegisterShow',
        'Inventory/StockValue',
        'Inventory/SupplierInvoices',
        'Inventory/SupplierReturns',
        'Inventory/Readiness',
        'Inventory/OpeningImport',
        'MedicalCertificates/Index',
        'Visits/History',
        'Referrals/Index',
    ],

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
    'public_pages' => [
        'PublicCheckIn/Status',
        'PublicCheckIn/Show',
    ],
];
