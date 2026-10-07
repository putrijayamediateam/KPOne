<?php

// PRANK: presentation joke only. Never merge this branch. Off unless PRANK_ENABLED=true in the demo env.
return [
    'enabled' => filter_var(env('PRANK_ENABLED', false), FILTER_VALIDATE_BOOL),
];
