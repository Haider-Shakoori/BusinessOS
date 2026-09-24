<?php

return [
    'title' => 'Taxes',
    'subtitle' => 'Manage the tax rates your business can apply.',
    'view_only' => 'You can view taxes. Managing them requires the “Manage taxes” permission.',

    'add' => 'Add tax',
    'create_title' => 'New tax',
    'edit_title' => 'Edit tax',

    'search' => 'Search taxes',
    'search_placeholder' => 'Search taxes…',

    'no_taxes' => 'No taxes',
    'no_taxes_description' => 'There are no taxes in this business yet. Add your first tax to get started.',
    'no_results' => 'No matching taxes',
    'no_results_description' => 'No taxes match your search. Try a different term.',

    'name' => 'Name',
    'rate' => 'Rate',
    'rate_helper' => 'Percentage applied to amounts, for example 5.25 for 5.25%.',
    'created' => 'Added',
    'updated' => 'Updated',
    'deleted' => 'Tax deleted.',

    'information' => 'Information',

    'table_caption' => 'Tax list',
    'columns' => [
        'name' => 'Tax',
        'rate' => 'Rate',
        'created' => 'Added',
        'actions' => 'Actions',
    ],

    'delete_confirm_title' => 'Delete :name?',
    'delete_confirm' => 'This will remove the tax from the tax list. This action cannot be undone.',
    'delete_cancel' => 'Cancel',
    'delete_submit' => 'Delete tax',

    'validation' => [
        'name_required' => 'The tax name is required.',
        'name_max' => 'The tax name may not be longer than 100 characters.',
        'name_unique' => 'A tax with this name already exists in this business.',
        'rate_required' => 'The rate is required.',
        'rate_numeric' => 'The rate must be a number.',
        'rate_min' => 'The rate may not be less than 0.',
        'rate_max' => 'The rate may not be greater than 100.',
        'rate_decimal' => 'The rate may have at most 4 decimal places.',
    ],
];
