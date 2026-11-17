<?php

return [
    'manager_password' => env('DEMO_MANAGER_PASSWORD'),
    'staff_password' => env('DEMO_STAFF_PASSWORD'),
    'extraction_enabled' => (bool) env('STOCKROOM_EXTRACTION_ENABLED', false),
    'openai_key' => env('OPENAI_API_KEY'),
    'extraction_attempt_limit' => max(0, (int) env('STOCKROOM_EXTRACTION_ATTEMPT_LIMIT', 0)),
];
