<?php

return [
    'title' => 'Products & Services',
    'subtitle' => 'Manage the items and services your business sells.',
    'view_only' => 'You can view products and services. Managing them requires the “Manage products” permission.',

    'add' => 'Add product',
    'create_title' => 'New product / service',
    'edit_title' => 'Edit product / service',

    'search' => 'Search products',
    'search_placeholder' => 'Search by name or SKU…',

    'type_filter' => 'Filter by type',

    'no_products' => 'No products or services',
    'no_products_description' => 'There are no products or services in this business yet. Add your first item to get started.',
    'no_results' => 'No matching products',
    'no_results_description' => 'No products match your search or filter. Try a different term.',

    'type' => 'Type',
    'types' => [
        'all' => 'All',
        'product' => 'Product',
        'service' => 'Service',
    ],

    'name' => 'Name',
    'sku' => 'SKU',
    'sku_helper' => 'Optional. Must be unique within this business.',
    'category' => 'Category',
    'no_category' => 'No category',
    'unit' => 'Unit',
    'no_unit' => 'No unit',
    'tax' => 'Tax',
    'no_tax' => 'No tax',
    'sale_price' => 'Sale price',
    'description' => 'Description',
    'description_helper' => 'Short description of this product or service.',
    'created' => 'Added',
    'updated' => 'Updated',
    'deleted' => 'Product deleted.',

    'information' => 'Information',

    'table_caption' => 'Product / service list',
    'columns' => [
        'type' => 'Type',
        'name' => 'Name',
        'sku' => 'SKU',
        'category' => 'Category',
        'unit' => 'Unit',
        'price' => 'Price',
        'actions' => 'Actions',
    ],

    'delete_confirm_title' => 'Delete :name?',
    'delete_confirm' => 'This will remove the product or service from the list. This action cannot be undone.',
    'delete_cancel' => 'Cancel',
    'delete_submit' => 'Delete product',

    'validation' => [
        'type_required' => 'The type is required.',
        'type_enum' => 'The type must be either “product” or “service”.',
        'name_required' => 'The name is required.',
        'name_max' => 'The name may not be longer than 100 characters.',
        'sku_max' => 'The SKU may not be longer than 50 characters.',
        'sku_unique' => 'A product or service with this SKU already exists in this business.',
        'description_max' => 'The description may not be longer than 2000 characters.',
        'category_id_exists' => 'The selected category does not exist in this business.',
        'unit_id_exists' => 'The selected unit does not exist in this business.',
        'tax_id_exists' => 'The selected tax does not exist in this business.',
        'sale_price_required' => 'The sale price is required.',
        'sale_price_numeric' => 'The sale price must be a number.',
        'sale_price_min' => 'The sale price may not be negative.',
        'sale_price_max' => 'The sale price is too large.',
        'sale_price_decimal' => 'The sale price may have at most 4 decimal places.',
    ],
];
