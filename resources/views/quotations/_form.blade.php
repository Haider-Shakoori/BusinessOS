@php
    $quotation = $quotation ?? null;
    $method = $method ?? 'POST';
    $customers = $customers ?? collect();
    $products = $products ?? collect();
    $taxesEnabled = $taxesEnabled ?? false;
    $taxes = $taxes ?? collect();
    $currencies = $currencies ?? collect();
    $currencyBase = $currencyBase ?? null;

    // Re-populate from a failed validation, else from the persisted quotation
    // (edit), else a single blank line for a fresh form (create).
    $submittedItems = old('items');
    if (is_array($submittedItems) && $submittedItems !== []) {
        $initialRows = array_map(fn ($row) => [
            'product_id' => $row['product_id'] ?? '',
            'description' => $row['description'] ?? '',
            'quantity' => $row['quantity'] ?? '1',
            'unit_price' => $row['unit_price'] ?? '',
            'tax_id' => $row['tax_id'] ?? '',
            'tax_rate' => $row['tax_rate'] ?? '',
        ], array_values($submittedItems));
        $initialRows = $initialRows !== [] ? $initialRows : [[
            'product_id' => '', 'description' => '', 'quantity' => '1', 'unit_price' => '', 'tax_id' => '', 'tax_rate' => '',
        ]];
    } elseif ($quotation && $quotation->items->isNotEmpty()) {
        $initialRows = $quotation->items->map(fn ($item) => [
            'product_id' => (string) ($item->product_id ?? ''),
            'description' => $item->description,
            'quantity' => $item->quantity,
            'unit_price' => $item->unit_price,
            'tax_id' => (string) ($item->tax_id ?? ''),
            'tax_rate' => (string) ($item->tax_rate ?? ''),
        ])->values()->all();
    } else {
        $initialRows = [[
            'product_id' => '', 'description' => '', 'quantity' => '1', 'unit_price' => '', 'tax_id' => '', 'tax_rate' => '',
        ]];
    }

    $catalog = $products->map(fn ($product) => [
        'id' => $product->id,
        'name' => $product->name,
        'sale_price' => $product->sale_price,
        'tax_id' => $product->tax_id,
    ])->values()->all();

    $quotationCustomerId = old('customer_id', $quotation?->customer_id);
    $quotationDate = old('date', $quotation?->date?->format('Y-m-d') ?? now()->format('Y-m-d'));
    $quotationCurrency = old('currency_code', $quotation?->currency_code ?? $currencyBase);
    $quotationExpiryDate = old('expiry_date', $quotation?->expiry_date?->format('Y-m-d'));
    $quotationStatus = old('status', $quotation?->status?->value ?? \App\Enums\QuotationStatus::Draft->value);
    $quotationDiscountType = old('discount_type', $quotation?->discount_type);
    $quotationDiscountAmount = old('discount_amount', $quotation?->discount_amount);
    $quotationNotes = old('notes', $quotation?->notes);
    $quotationTerms = old('terms', $quotation?->terms);
    $settableStatuses = \App\Http\Requests\Quotation\StoreQuotationRequest::settableStatuses();
    $itemErrors = collect($errors->getMessages())->filter(fn ($messages, $key) => str_starts_with($key, 'items.'))->flatten();
@endphp

<form
    method="POST"
    action="{{ $action }}"
    class="space-y-6"
    x-data="quotationEditor({{ Js::from($catalog) }}, {{ $taxesEnabled ? 'true' : 'false' }}, {{ Js::from($initialRows) }})"
>
    @csrf
    @if ($method !== 'POST')
        @method($method)
    @endif

    <x-ui.card>
        <x-slot:header>
            <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('quotations.information') }}</h2>
        </x-slot:header>

        <div class="grid gap-x-6 gap-y-5 sm:grid-cols-2">
            <div>
                <x-ui.select name="customer_id" :label="__('quotations.customer')" :value="$quotationCustomerId" required>
                    <option value="" disabled @selected($quotationCustomerId === null)>{{ __('quotations.no_customer') }}</option>
                    @foreach ($customers as $customer)
                        <option value="{{ $customer->id }}" @selected((int) $quotationCustomerId === $customer->id)>
                            {{ $customer->name }}
                            @if ($customer->company_name)
                                ({{ $customer->company_name }})
                            @endif
                        </option>
                    @endforeach
                </x-ui.select>
            </div>

            <div>
                <x-ui.select name="status" :label="__('quotations.status')" :value="$quotationStatus" required>
                    @foreach ($settableStatuses as $value)
                        <option value="{{ $value }}" @selected($quotationStatus === $value)>
                            {{ __('quotations.statuses.'.$value) }}
                        </option>
                    @endforeach
                </x-ui.select>
            </div>

            <div>
                <x-ui.input type="date" name="date" :label="__('quotations.date')" :value="$quotationDate" required />
            </div>

            <div>
                <x-ui.input type="date" name="expiry_date" :label="__('quotations.expiry_date')" :value="$quotationExpiryDate" />
            </div>

            <div>
                <x-ui.select name="currency_code" :label="__('currencies.currency')" :value="$quotationCurrency">
                    @foreach ($currencies as $currency)
                        <option value="{{ $currency->code }}" @selected($quotationCurrency === $currency->code)>
                            {{ $currency->code }} — {{ $currency->name }}
                        </option>
                    @endforeach
                </x-ui.select>
            </div>
        </div>
    </x-ui.card>

    <x-ui.card>
        <x-slot:header>
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('quotations.line_items') }}</h2>
                <x-ui.button type="button" variant="secondary" size="sm" icon="plus" x-on:click="addRow">
                    {{ __('quotations.action_add_line') }}
                </x-ui.button>
            </div>
        </x-slot:header>

        @if ($itemErrors->isNotEmpty())
            <div class="mb-4">
                <x-ui.alert type="danger">
                    <ul class="list-inside list-disc space-y-1">
                        @foreach ($itemErrors as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                </x-ui.alert>
            </div>
        @endif

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead>
                    <tr>
                        <x-ui.th>{{ __('quotations.product') }}</x-ui.th>
                        <x-ui.th>{{ __('quotations.description') }}</x-ui.th>
                        <x-ui.th>{{ __('quotations.quantity') }}</x-ui.th>
                        <x-ui.th>{{ __('quotations.unit_price') }}</x-ui.th>
                        @if ($taxesEnabled)
                            <x-ui.th>{{ __('quotations.tax') }}</x-ui.th>
                        @endif
                        <x-ui.th class="text-end">
                            <span class="sr-only">{{ __('quotations.action_remove_line') }}</span>
                        </x-ui.th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    <template x-for="(row, index) in rows" :key="index">
                        <tr>
                            <td class="py-3 pe-3 align-top">
                                <select
                                    :id="`item-${index}-product`"
                                    :name="`items[${index}][product_id]`"
                                    x-model="row.product_id"
                                    x-on:change="pickProduct(row)"
                                    class="block w-full min-w-40 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm transition-colors duration-150 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:focus:border-brand-400"
                                >
                                    <option value="">{{ __('quotations.no_product') }}</option>
                                    <template x-for="product in availableProducts" :key="product.id">
                                        <option :value="product.id" x-text="product.name"></option>
                                    </template>
                                </select>
                            </td>
                            <td class="py-3 pe-3 align-top">
                                <input
                                    type="text"
                                    :id="`item-${index}-description`"
                                    :name="`items[${index}][description]`"
                                    x-model="row.description"
                                    maxlength="500"
                                    required
                                    :placeholder="`{{ __('quotations.line_placeholder') }}`"
                                    class="block w-full min-w-52 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm transition-colors duration-150 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:focus:border-brand-400"
                                />
                            </td>
                            <td class="py-3 pe-3 align-top">
                                <input
                                    type="number"
                                    :id="`item-${index}-quantity`"
                                    :name="`items[${index}][quantity]`"
                                    x-model="row.quantity"
                                    min="0.0001"
                                    step="0.0001"
                                    inputmode="decimal"
                                    required
                                    class="block w-28 rounded-lg border border-gray-300 bg-white px-3 py-2 text-end tabular-nums text-sm text-gray-900 shadow-sm transition-colors duration-150 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:focus:border-brand-400"
                                />
                            </td>
                            <td class="py-3 pe-3 align-top">
                                <input
                                    type="number"
                                    :id="`item-${index}-unit-price`"
                                    :name="`items[${index}][unit_price]`"
                                    x-model="row.unit_price"
                                    min="0"
                                    step="0.0001"
                                    inputmode="decimal"
                                    required
                                    class="block w-32 rounded-lg border border-gray-300 bg-white px-3 py-2 text-end tabular-nums text-sm text-gray-900 shadow-sm transition-colors duration-150 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:focus:border-brand-400"
                                />
                            </td>
                            @if ($taxesEnabled)
                                <td class="py-3 pe-3 align-top">
                                    <select
                                        :id="`item-${index}-tax`"
                                        :name="`items[${index}][tax_id]`"
                                        x-model="row.tax_id"
                                        class="block w-full min-w-36 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm transition-colors duration-150 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:focus:border-brand-400"
                                    >
                                        <option value="">{{ __('products.no_tax') }}</option>
                                        @foreach ($taxes as $tax)
                                            <option value="{{ $tax->id }}">{{ $tax->name }}</option>
                                        @endforeach
                                    </select>
                                </td>
                            @endif
                            <td class="py-3 align-top text-end">
                                <button
                                    type="button"
                                    x-on:click="removeRow(index)"
                                    :disabled="rows.length <= 1"
                                    :aria-label="'{{ __('quotations.action_remove_line') }}'"
                                    class="inline-flex size-8 shrink-0 items-center justify-center rounded-lg border border-gray-300 bg-white text-gray-700 shadow-sm transition-colors duration-150 hover:bg-gray-50 disabled:pointer-events-none disabled:opacity-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:hover:bg-gray-700"
                                >
                                    <x-ui.icon name="trash" class="size-4" />
                                </button>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400" x-show="rows.length <= 1">
            {{ __('quotations.validation.items_min') }}
        </p>
    </x-ui.card>

    <x-ui.card>
        <x-slot:header>
            <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('quotations.total') }}</h2>
        </x-slot:header>

        <div class="grid gap-x-6 gap-y-5 sm:grid-cols-2">
            <div>
                <x-ui.select name="discount_type" :label="__('quotations.discount_type')" :placeholder="__('quotations.no_discount')" :value="$quotationDiscountType">
                    <option value="percentage" @selected($quotationDiscountType === 'percentage')>
                        {{ __('quotations.discount_types.percentage') }}
                    </option>
                    <option value="fixed" @selected($quotationDiscountType === 'fixed')>
                        {{ __('quotations.discount_types.fixed') }}
                    </option>
                </x-ui.select>
            </div>

            <div>
                <x-ui.number-input
                    name="discount_amount"
                    :label="__('quotations.discount_amount')"
                    :value="$quotationDiscountAmount ?? ''"
                    min="0"
                    step="0.0001"
                />
            </div>

            <div class="sm:col-span-2">
                <x-ui.textarea
                    name="notes"
                    :label="__('quotations.notes')"
                    :value="$quotationNotes"
                    rows="3"
                    maxlength="2000"
                />
            </div>

            <div class="sm:col-span-2">
                <x-ui.textarea
                    name="terms"
                    :label="__('quotations.terms')"
                    :value="$quotationTerms"
                    rows="3"
                    maxlength="2000"
                />
            </div>
        </div>
    </x-ui.card>

    <div class="flex flex-wrap items-center justify-end gap-3">
        <x-ui.button type="submit" icon="check-circle">
            {{ $method === 'PATCH' ? __('actions.update') : __('actions.create') }}
        </x-ui.button>
        <x-ui.button variant="secondary" href="{{ route('quotations.index') }}">
            {{ __('actions.cancel') }}
        </x-ui.button>
    </div>
</form>