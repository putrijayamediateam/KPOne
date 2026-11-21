<?php

return [
    /*
     | TRIAL-DATA: allows `php artisan kpone:trial-data` to fill a trial server with fictional demo data.
     | Off everywhere by default. Set KPONE_TRIAL_DATA_ALLOWED=true only on a trial server that holds
     | fictional data, run the command by hand, then switch it off again. See docs/design/TRIAL-DATA-COMMAND.md.
     */
    'allowed' => filter_var(env('KPONE_TRIAL_DATA_ALLOWED', false), FILTER_VALIDATE_BOOL),
];
