<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Business Context
    |--------------------------------------------------------------------------
    |
    | The session key used to remember the currently selected business for an
    | authenticated user. This value is NEVER trusted directly: the
    | BusinessContext service re-validates it against the user's memberships on
    | every resolution and discards it when it no longer belongs to the user.
    |
    */

    'context' => [
        'session_key' => 'current_business',
    ],

    /*
    |--------------------------------------------------------------------------
    | CSV Imports (Batch 20)
    |--------------------------------------------------------------------------
    |
    | Safety limits for the create-only CSV import feature. These are practical
    | defaults for shared hosting: uploads are capped both by Laravel's upload
    | limits and by an explicit row count, so a single Preview/Execute request
    | cannot be flooded with an unbounded CSV.
    |
    | max_file_kb      - same 2 MB cap used by the expense receipt upload.
    | max_rows         - hard row cap after the header (1-indexed data rows).
    | preview_rows     - how many rows the Preview page shows in its table.
    | queue_threshold  - imports with MORE than this many rows are dispatched to
    |                    the (database) queue; smaller ones run synchronously in
    |                    the request transaction.
    | temp_ttl_minutes - how long an uploaded staging file may live before its
    |                    token is treated as expired.
    |
    */

    'import' => [
        'max_file_kb' => 2048,
        'max_rows' => 2000,
        'preview_rows' => 10,
        'queue_threshold' => 500,
        'temp_ttl_minutes' => 120,
    ],
];
