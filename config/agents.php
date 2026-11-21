<?php

return [
    /*
     | Approval that prepares work (docs/agents/CONTRACT.md section 15). While false, every approval is decision only
     | and no agent suggestion prepares a stock request or purchase request. The owner turns it on; it stays off in
     | week one of the pilot. Set AGENTS_PREPARE_ENABLED in the server environment, never in code.
     */
    'prepare_enabled' => (bool) env('AGENTS_PREPARE_ENABLED', false),
];
