<?php

return [
    'title' => 'Units',
    'subtitle' => 'Manage the units of measure used in your catalog.',
    'view_only' => 'You can view units. Managing them requires the “Manage units” permission.',

    'add' => 'Add unit',
    'create_title' => 'New unit',
    'edit_title' => 'Edit unit',

    'search' => 'Search units',
    'search_placeholder' => 'Search units…',

    'no_units' => 'No units',
    'no_units_description' => 'There are no units in this business yet. Add your first unit to get started.',
    'no_results' => 'No matching units',
    'no_results_description' => 'No units match your search. Try a different term.',

    'name' => 'Name',
    'short_name' => 'Short name',
    'short_name_helper' => 'Abbreviation shown next to quantities (for example “pcs”).',
    'created' => 'Added',
    'updated' => 'Updated',
    'deleted' => 'Unit deleted.',

    'information' => 'Information',

    'table_caption' => 'Unit list',
    'columns' => [
        'name' => 'Unit',
        'short_name' => 'Short name',
        'created' => 'Added',
        'actions' => 'Actions',
    ],

    'delete_confirm_title' => 'Delete :name?',
    'delete_confirm' => 'This will remove the unit from the unit list. This action cannot be undone.',
    'delete_cancel' => 'Cancel',
    'delete_submit' => 'Delete unit',

    'validation' => [
        'name_required' => 'The unit name is required.',
        'name_max' => 'The unit name may not be longer than 100 characters.',
        'name_unique' => 'A unit with this name already exists in this business.',
        'short_name_max' => 'The short name may not be longer than 20 characters.',
    ],
];
