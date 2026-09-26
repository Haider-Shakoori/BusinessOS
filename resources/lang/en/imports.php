<?php

return [
    'import_customers' => 'Import customers',
    'import_customers_subtitle' => 'Create customers in bulk from a CSV file. Each row becomes a new customer — nothing is updated.',
    'import_products' => 'Import products',
    'import_products_subtitle' => 'Create products and services in bulk from a CSV file. Each row becomes a new item — nothing is updated.',
    'import_suppliers' => 'Import suppliers',
    'import_suppliers_subtitle' => 'Create suppliers in bulk from a CSV file. Each row becomes a new supplier — nothing is updated.',

    'upload_title' => 'Upload a CSV file',
    'upload' => 'Upload CSV',
    'csv_file' => 'CSV file',
    'csv_helper' => 'UTF-8 CSV with a header row, matching the template. Files are limited to :max_kb KB and :max_rows rows.',
    'download_template' => 'Download template',
    'instructions_title' => 'Before you upload',
    'instruction_template' => 'Start from the template: it contains the exact columns this import accepts, nothing more.',
    'instruction_encoding' => 'Save the file as UTF-8 CSV. A header row is required; unicode text and leading zeros are preserved.',
    'instruction_preview' => 'You will always see a preview and a full validation report before anything is created.',
    'instruction_all_or_nothing' => 'If any row is invalid, no rows are created at all — correct the errors and upload again.',

    'preview_title' => 'Preview import',
    'preview_subtitle' => 'Review the parsed rows before creating anything.',
    'total_rows' => 'Total rows',
    'valid_rows' => 'Valid rows',
    'invalid_rows' => 'Invalid rows',
    'row_label' => 'Row',
    'row_error' => 'Row :row — :message',

    'header' => [
        'invalid' => 'Invalid CSV header',
        'invalid_description' => 'Correct the header and upload the file again.',
        'empty' => 'The file is empty: a header row is required.',
        'blank' => 'A header column is blank.',
        'unknown' => 'Unknown column ":column".',
        'duplicate' => 'Duplicate column ":column".',
        'missing' => 'Missing required column ":column".',
    ],
    'header_invalid' => 'Invalid CSV header',
    'header_invalid_description' => 'Correct the header and upload the file again.',

    'rows_invalid' => ':count of :total rows cannot be imported.',
    'rows_invalid_description' => 'Correct the marked rows in your CSV and upload it again. Nothing has been created.',
    'errors_title' => 'Row errors',
    'no_errors' => 'No errors.',
    'more_errors' => 'And :count more…',

    'row' => [
        'required' => 'The field ":label" is required.',
        'column_count' => 'The row has :actual columns; :expected were expected.',
    ],

    'preview_ready' => ':count rows are ready to import.',
    'preview_ready_description' => 'The file is fully valid. Confirm below to create every row in one step.',
    'preview_rows' => 'Showing the first :count of :total rows.',
    'confirm' => 'Confirm import',
    'confirm_note' => 'Confirming creates :count new records. Nothing is ever updated by an import.',
    'cancel' => 'Cancel',
    'back_to_upload' => 'Upload a different file',

    'completed' => 'Import completed. :count records created.',
    'queued' => 'Import started in the background. :count records will be created.',
    'failed' => 'Import failed',
    'execution_guard' => 'The file was re-validated just before writing and no longer passed. Nothing was created.',
    'cancelled' => 'Import cancelled. The uploaded file was discarded.',
    'session_expired' => 'This import session expired or is no longer available. Please start again.',

    'sku_duplicate' => 'Duplicate SKU in the same file.',
    'reference_not_found' => 'Related :label not found in this business.',
    'tax_disabled' => 'Tax is not enabled for this business.',

    'too_many_rows' => 'The file has more than :limit rows. Split it and upload again.',

    'validation' => [
        'file_required' => 'Please choose a CSV file to upload.',
        'file_mimes' => 'The file must be a .csv file.',
        'file_extensions' => 'The uploaded file must have a .csv extension.',
        'file_max' => 'The CSV file may not be larger than :max kilobytes. Split it and upload again.',
    ],
];
