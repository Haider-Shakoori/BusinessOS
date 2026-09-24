<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Expenses Translation Strings (English)
    |--------------------------------------------------------------------------
    |
    | Batch 17 — expense tracking. All module strings resolve here; the UI
    | never renders raw validation messages (those live under `validation`).
    |
    */

    'title' => 'Expenses',
    'subtitle' => 'Record, categorize and track your business expenses.',
    'view_only' => 'You can view expenses. Recording or editing them requires the “Manage expenses” permission.',

    'add' => 'Add expense',
    'create_title' => 'New expense',
    'edit_title' => 'Edit expense',

    'search' => 'Search expenses',
    'search_placeholder' => 'Search by expense number, reference or vendor…',

    'category_filter' => 'Category',
    'all_categories' => 'All categories',
    'date_from' => 'From date',
    'date_to' => 'To date',

    'no_expenses' => 'No expenses',
    'no_expenses_description' => 'There are no expenses in this business yet. Add your first expense to get started.',
    'no_results' => 'No matching expenses',
    'no_results_description' => 'No expenses match your filters. Try a different combination.',

    'created' => 'Expense created.',
    'updated' => 'Expense updated.',
    'deleted' => 'Expense deleted.',

    'information' => 'Information',
    'details' => 'Details',
    'number' => 'Number',
    'category' => 'Category',
    'no_category' => 'No category',
    'date' => 'Date',
    'amount' => 'Amount',
    'method' => 'Payment method',
    'no_method' => 'Not specified',
    'reference' => 'Reference',
    'no_reference' => '—',
    'vendor' => 'Vendor / payee',
    'no_vendor' => '—',
    'notes' => 'Notes',

    'receipt' => 'Receipt',
    'receipt_helper' => 'JPG, PNG, WEBP or PDF, up to 5 MB.',
    'receipt_upload' => 'Upload receipt',
    'receipt_replace' => 'Replace receipt',
    'receipt_current' => 'Current receipt',
    'receipt_none' => 'No receipt',
    'view_receipt' => 'Open receipt',
    'receipt_remove' => 'Remove receipt',
    'receipt_remove_confirm' => 'Remove the current receipt? This deletes the stored file and cannot be undone.',

    'recorded_by' => 'Recorded by',
    'recorded_at' => 'Recorded on',

    'methods' => [
        'cash' => 'Cash',
        'bank_transfer' => 'Bank transfer',
        'card' => 'Card',
        'cheque' => 'Cheque',
        'mobile' => 'Mobile money',
        'other' => 'Other',
    ],

    'report' => 'Expense report',
    'report_subtitle' => 'Operational summary of expenses across a date range.',
    'report_generate' => 'Generate report',
    'report_total' => 'Total expenses',
    'report_count' => ':count expense|:count expenses',
    'report_empty' => 'No expenses in the selected range.',
    'report_empty_description' => 'Adjust the filters to see expenses and the report total.',

    'table_caption' => 'Expenses',
    'columns' => [
        'number' => 'Number',
        'date' => 'Date',
        'category' => 'Category',
        'vendor' => 'Vendor',
        'amount' => 'Amount',
        'receipt' => 'Receipt',
        'actions' => 'Actions',
    ],

    'delete_confirm_title' => 'Delete :name?',
    'delete_confirm' => 'The expense will be removed from the list. The record, its number and any receipt are kept for financial history.',
    'delete_cancel' => 'Cancel',
    'delete_submit' => 'Delete expense',

    'validation' => [
        'category_id_exists' => 'The selected category does not exist in this business.',
        'expense_date_required' => 'The expense date is required.',
        'expense_date_date' => 'The expense date must be a valid date.',
        'amount_required' => 'The expense amount is required.',
        'amount_numeric' => 'The expense amount must be a number.',
        'amount_min' => 'The expense amount must be greater than zero.',
        'amount_max' => 'The expense amount is too large.',
        'amount_decimal' => 'The expense amount may have at most 4 decimal places.',
        'payment_method_in' => 'The selected payment method is not supported.',
        'reference_max' => 'The reference may not be longer than 100 characters.',
        'vendor_max' => 'The vendor may not be longer than 150 characters.',
        'notes_max' => 'The notes may not be longer than 2000 characters.',
        'receipt_file' => 'The receipt must be a file.',
        'receipt_mimes' => 'The receipt must be a JPG, PNG, WEBP or PDF file.',
        'receipt_max' => 'The receipt may not be larger than 5 MB.',
    ],
];
