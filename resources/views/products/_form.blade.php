@php
    $product = $product ?? null;
    $editable = $editable ?? true;
    $method = $method ?? 'POST';
    $categories = $categories ?? collect();
    $units = $units ?? collect();
    $taxesEnabled = $taxesEnabled ?? false;
    $taxes = $taxes ?? collect();
    $productType = old('type', $product?->type?->value ?? 'product');
    $productCategoryId = old('category_id', $product?->category_id);
    $productUnitId = old('unit_id', $product?->unit_id);
@endphp

<form method="POST" action="{{ $action }}" class="space-y-6">
    @csrf
    @if ($method !== 'POST')
        @method($method)
    @endif

    <x-ui.card>
        <x-slot:header>
            <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('products.information') }}</h2>
        </x-slot:header>

        <div class="grid gap-x-6 gap-y-5 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <x-ui.select
                    name="type"
                    :label="__('products.type')"
                    :value="$productType"
                    :disabled="! $editable"
                    required
                >
                    @foreach (\App\Enums\ProductType::cases() as $case)
                        <option value="{{ $case->value }}" @selected($productType === $case->value)>
                            {{ __('products.types.'.$case->value) }}
                        </option>
                    @endforeach
                </x-ui.select>
            </div>

            <div>
                <x-ui.input
                    name="name"
                    :label="__('products.name')"
                    :value="old('name', $product?->name)"
                    :disabled="! $editable"
                    maxlength="100"
                    required
                />
            </div>

            <div>
                <x-ui.input
                    name="sku"
                    :label="__('products.sku')"
                    :helper="__('products.sku_helper')"
                    :value="old('sku', $product?->sku)"
                    :disabled="! $editable"
                    maxlength="50"
                    dir="ltr"
                />
            </div>

            <div>
                <x-ui.select
                    name="category_id"
                    :label="__('products.category')"
                    :placeholder="__('products.no_category')"
                    :value="$productCategoryId"
                    :disabled="! $editable"
                >
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected($productCategoryId === $category->id)>
                            {{ $category->name }}
                        </option>
                    @endforeach
                </x-ui.select>
            </div>

            <div>
                <x-ui.select
                    name="unit_id"
                    :label="__('products.unit')"
                    :placeholder="__('products.no_unit')"
                    :value="$productUnitId"
                    :disabled="! $editable"
                >
                    @foreach ($units as $unit)
                        <option value="{{ $unit->id }}" @selected($productUnitId === $unit->id)>
                            {{ $unit->name }}
                        </option>
                    @endforeach
                </x-ui.select>
            </div>

            <div>
                <x-ui.number-input
                    name="sale_price"
                    :label="__('products.sale_price')"
                    :value="old('sale_price', $product?->sale_price)"
                    :disabled="! $editable"
                    min="0"
                    step="0.0001"
                    required
                />
            </div>

            @if ($taxesEnabled)
                <div>
                    <x-ui.select
                        name="tax_id"
                        :label="__('products.tax')"
                        :placeholder="__('products.no_tax')"
                        :value="old('tax_id', $product?->tax_id)"
                        :disabled="! $editable"
                        rel="tax-selector"
                    >
                        @foreach ($taxes as $tax)
                            <option value="{{ $tax->id }}" @selected(old('tax_id', $product?->tax_id) === $tax->id)>
                                {{ $tax->name }}
                            </option>
                        @endforeach
                    </x-ui.select>
                </div>
            @endif

            <div class="sm:col-span-2">
                <x-ui.textarea
                    name="description"
                    :label="__('products.description')"
                    :helper="__('products.description_helper')"
                    :value="old('description', $product?->description)"
                    rows="3"
                    :disabled="! $editable"
                    maxlength="2000"
                />
            </div>
        </div>
    </x-ui.card>

    @if ($editable)
        <div class="flex flex-wrap items-center justify-end gap-3">
            <x-ui.button type="submit" icon="check-circle">
                {{ $method === 'PATCH' ? __('actions.update') : __('actions.create') }}
            </x-ui.button>
            <x-ui.button variant="secondary" href="{{ route('products.index') }}">
                {{ __('actions.cancel') }}
            </x-ui.button>
        </div>
    @endif
</form>