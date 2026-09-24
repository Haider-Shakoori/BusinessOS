<?php

return [
    'title' => 'Categories',
    'subtitle' => 'Organize products into groups for easier management.',
    'view_only' => 'You can view categories. Managing them requires the “Manage categories” permission.',

    'add' => 'Add category',
    'create_title' => 'New category',
    'edit_title' => 'Edit category',

    'search' => 'Search categories',
    'search_placeholder' => 'Search categories…',

    'no_categories' => 'No categories',
    'no_categories_description' => 'There are no categories in this business yet. Add your first category to get started.',
    'no_results' => 'No matching categories',
    'no_results_description' => 'No categories match your search. Try a different term.',

    'name' => 'Name',
    'description' => 'Description',
    'description_helper' => 'Short description of what belongs in this category.',
    'created' => 'Added',
    'updated' => 'Updated',
    'deleted' => 'Category deleted.',

    'information' => 'Information',

    'table_caption' => 'Category list',
    'columns' => [
        'name' => 'Category',
        'description' => 'Description',
        'created' => 'Added',
        'actions' => 'Actions',
    ],

    'delete_confirm_title' => 'Delete :name?',
    'delete_confirm' => 'This will remove the category from the category list. This action cannot be undone.',
    'delete_cancel' => 'Cancel',
    'delete_submit' => 'Delete category',

    'validation' => [
        'name_required' => 'The category name is required.',
        'name_max' => 'The category name may not be longer than 100 characters.',
        'name_unique' => 'A category with this name already exists in this business.',
        'description_max' => 'The description may not be longer than 500 characters.',
    ],
];
