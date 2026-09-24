<?php

namespace App\Services\Imports;

use App\Enums\ProductType;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tax;
use App\Models\Unit;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Create-only product/service CSV import mapper (Batch 20).
 *
 * Field set mirrors StoreProductRequest: `name`, `type` and `sale_price` are
 * required; SKU uniqueness is enforced both within the file and against the
 * existing products of the CURRENT business (soft-deleted rows excluded, same
 * semantics as the UniqueInBusiness rule the manual form uses).
 *
 * Categories, units and taxes arrive as human-readable names, resolved by
 * exact (case-insensitive, trimmed) match against the CURRENT business only.
 * Nothing is ever auto-created, and a value for a disabled tax feature is a
 * row error — the mapping table is authoritative, never guessed at.
 */
final class ProductImportMapper extends ImportMapper
{
    private ?array $categoryIndex = null;

    private ?array $unitIndex = null;

    private ?array $taxIndex = null;

    /** @var array<string, true> SKUs seen in this walk (within-file duplicates) */
    private array $seenSkus = [];

    public function __construct(private readonly bool $taxEnabled)
    {
        //
    }

    public function resourceKey(): string
    {
        return 'products';
    }

    /**
     * @return list<ImportColumn>
     */
    protected function columns(): array
    {
        return [
            new ImportColumn('name', 'products.name', requiredInHeader: true, requiredPerRow: true),
            new ImportColumn('type', 'products.type', requiredInHeader: true, requiredPerRow: true),
            new ImportColumn('sku', 'products.sku'),
            new ImportColumn('sale_price', 'products.sale_price', requiredInHeader: true, requiredPerRow: true),
            new ImportColumn('description', 'products.description'),
            new ImportColumn('category', 'products.category'),
            new ImportColumn('unit', 'products.unit'),
            new ImportColumn('tax', 'products.tax'),
        ];
    }

    /**
     * @param  array<string, string|null>  $values
     * @return list<string>
     */
    protected function validateRow(array &$values, int $rowNumber, int $businessId): array
    {
        $errors = [];

        if (mb_strlen((string) ($values['name'] ?? '')) > 100) {
            $errors[] = __('products.validation.name_max');
        }

        $type = strtolower(trim((string) ($values['type'] ?? '')));

        if ($type === '') {
            $errors[] = __('products.validation.type_required');
        } elseif (! in_array($type, [ProductType::Product->value, ProductType::Service->value], true)) {
            $errors[] = __('products.validation.type_enum');
        } else {
            $values['type'] = $type;
        }

        $sku = $values['sku'] ?? null;

        if ($sku !== null) {
            if (mb_strlen((string) $sku) > 50) {
                $errors[] = __('products.validation.sku_max');
            } else {
                $errors = array_merge($errors, $this->validateSku((string) $sku, $businessId));
            }
        }

        if (($values['description'] ?? null) !== null && mb_strlen((string) $values['description']) > 2000) {
            $errors[] = __('products.validation.description_max');
        }

        $errors = array_merge($errors, $this->validateSalePrice($values));

        if (($values['category'] ?? null) !== null) {
            $id = $this->categoryIndex($businessId)[mb_strtolower(trim((string) $values['category']))] ?? null;

            if ($id === null) {
                $errors[] = __('imports.reference_not_found', ['label' => __('products.category')]);
            } else {
                $values['category_id'] = $id;
            }
        }

        if (($values['unit'] ?? null) !== null) {
            $id = $this->unitIndex($businessId)[mb_strtolower(trim((string) $values['unit']))] ?? null;

            if ($id === null) {
                $errors[] = __('imports.reference_not_found', ['label' => __('products.unit')]);
            } else {
                $values['unit_id'] = $id;
            }
        }

        $tax = $values['tax'] ?? null;

        if ($tax !== null) {
            if (! $this->taxEnabled) {
                $errors[] = __('imports.tax_disabled');
            } else {
                $id = $this->taxIndex($businessId)[mb_strtolower(trim((string) $tax))] ?? null;

                if ($id === null) {
                    $errors[] = __('imports.reference_not_found', ['label' => __('products.tax')]);
                } else {
                    $values['tax_id'] = $id;
                }
            }
        }

        return $errors;
    }

    /**
     * SKU uniqueness: within this file first, then against the business's
     * existing (non-deleted) products.
     *
     * @return list<string>
     */
    private function validateSku(string $sku, int $businessId): array
    {
        if (isset($this->seenSkus[$sku])) {
            return [__('imports.sku_duplicate')];
        }

        $exists = Product::query()
            ->where('business_id', $businessId)
            ->whereNull('deleted_at')
            ->where('sku', $sku)
            ->exists();

        if ($exists) {
            return [__('products.validation.sku_unique')];
        }

        $this->seenSkus[$sku] = true;

        return [];
    }

    /**
     * Exact DECIMAL(16,4) sale price. Validation mirrors the store-form rule
     * set with the same error vocabulary: required, numeric, non-negative,
     * ≤ 12 integer digits, ≤ 4 decimal digits, and no float/scientific forms.
     *
     * @param  array<string, string|null>  $values
     * @return list<string>
     */
    private function validateSalePrice(array &$values): array
    {
        $price = trim((string) ($values['sale_price'] ?? ''));

        if ($price === '') {
            return [__('products.validation.sale_price_required')];
        }

        if (! is_numeric($price)) {
            return [__('products.validation.sale_price_numeric')];
        }

        if (str_starts_with($price, '-')) {
            return [__('products.validation.sale_price_min')];
        }

        if (preg_match('/^[0-9]+(\.[0-9]+)?$/', $price) !== 1) {
            return [__('products.validation.sale_price_decimal')];
        }

        [$int, $frac] = array_pad(explode('.', $price, 2), 2, '');

        if (strlen(ltrim($int, '0')) > 12) {
            return [__('products.validation.sale_price_max')];
        }

        if (strlen($frac) > 4) {
            return [__('products.validation.sale_price_decimal')];
        }

        $values['sale_price'] = Decimal::normalize($price);

        return [];
    }

    /**
     * @return array<string, int|null> lowercase name => id (null = ambiguous)
     */
    private function categoryIndex(int $businessId): array
    {
        return $this->categoryIndex ??= $this->indexByName(
            Category::query()->where('business_id', $businessId)->get(['id', 'name']),
        );
    }

    /**
     * @return array<string, int|null>
     */
    private function unitIndex(int $businessId): array
    {
        return $this->unitIndex ??= $this->indexByName(
            Unit::query()->where('business_id', $businessId)->get(['id', 'name']),
        );
    }

    /**
     * @return array<string, int|null>
     */
    private function taxIndex(int $businessId): array
    {
        return $this->taxIndex ??= $this->indexByName(
            Tax::query()->where('business_id', $businessId)->get(['id', 'name']),
        );
    }

    /**
     * Build a case-insensitive exact name => id map. Two master records with
     * the same normalized name make the name deliberately unresolvable (null),
     * because import must never silently pick one of them.
     *
     * @param  Collection<int, Model>  $rows
     * @return array<string, int|null>
     */
    private function indexByName(Collection $rows): array
    {
        $index = [];

        foreach ($rows as $row) {
            $key = mb_strtolower(trim((string) $row->name));

            if (array_key_exists($key, $index)) {
                $index[$key] = null;
            } else {
                $index[$key] = (int) $row->id;
            }
        }

        return $index;
    }

    /**
     * @param  array<string, string|null>  $values
     */
    public function createRow(array $values, int $businessId): void
    {
        $product = new Product($this->cleanValues($values));
        $product->business_id = $businessId;
        $product->save();
    }

    /**
     * Drop null values; keep only the product fields the mapper produced.
     *
     * @param  array<string, string|null>  $values
     * @return array<string, string|int>
     */
    private function cleanValues(array $values): array
    {
        $allowed = ['type', 'name', 'sku', 'description', 'category_id', 'unit_id', 'tax_id', 'sale_price'];

        return array_filter(
            array_intersect_key($values, array_flip($allowed)),
            static fn ($value) => $value !== null,
        );
    }
}
