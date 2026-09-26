<?php

return [
    'enabled' => (bool) env('FIELD_PULSE_INTEGRATION_ENABLED', false),
    'token' => env('FIELD_PULSE_INTEGRATION_TOKEN'),
    'page_size' => (int) env('FIELD_PULSE_INTEGRATION_PAGE_SIZE', 200),
    'max_events_per_request' => (int) env('FIELD_PULSE_INTEGRATION_MAX_EVENTS', 200),
];
