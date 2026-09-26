<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use RuntimeException;

class ProductVariantService
{
    public function resolve(Product $product, ?int $variantId): ?ProductVariant
    {
        $activeVariants = $product->variants()->where('is_active', true);

        if ($variantId !== null) {
            $variant = (clone $activeVariants)->whereKey($variantId)->first();

            if ($variant === null) {
                throw new RuntimeException(__('products.variants.invalid_selection'));
            }

            return $variant;
        }

        if ((clone $activeVariants)->exists()) {
            throw new RuntimeException(__('products.variants.required_for_stock'));
        }

        return null;
    }

    public function stockKey(int $productId, ?int $variantId): string
    {
        return $productId.':'.($variantId ?? 0);
    }
}
