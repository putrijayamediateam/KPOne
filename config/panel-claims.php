<?php

return [
    // PNL-28a: create a Prepared claim when a Panel bill is approved. Tests that need an approved bill with no claim switch it off.
    'auto_create' => (bool) env('PANEL_AUTO_CLAIMS', true),
];
