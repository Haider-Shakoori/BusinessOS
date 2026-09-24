<?php

namespace App\Enums;

/**
 * Product / service kind (Batch 12).
 *
 * Products and services share ONE registry — a single `products` table whose
 * `type` column is the only discriminator. The enum value is stored verbatim
 * and re-cast on read, and `Rule::enum(ProductType::class)` guarantees an
 * unsupported value can never be persisted.
 *
 * Both kinds support identical catalog behaviour (category, unit, tax, SKU,
 * sale price). There is no inventory/stock distinction at this stage: stock,
 * variants, purchasing etc. belong to the dedicated inventory batches.
 */
enum ProductType: string
{
    case Product = 'product';

    case Service = 'service';
}
