@php
    $invoice = $invoice ?? null;
    $method = $method ?? 'POST';
    $customers = $customers ?? collect();
    $products = $products ?? collect();
    $taxesEnabled = $taxesEnabled ?? false;
    $taxes = $taxes ?? collect();
    $currencies = $currencies ?? collect();
    $currencyBase = $currencyBase ?? null;

    // Re-populate from a failed validation, else from the persisted invoice
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
    } elseif ($invoice && $invoice->items->isNotEmpty()) {
        $initialRows = $invoice->items->map(fn ($item) => [
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

    $invoiceCustomerId = old('customer_id', $invoice?->customer_id);
    $invoiceDate = old('date', $invoice?->date?->format('Y-m-d') ?? now()->format('Y-m-d'));
    $invoiceCurrency = old('currency_code', $invoice?->currency_code ?? $currencyBase);
    $invoiceStatus = old('status', $invoice?->status?->value ?? \App\Enums\InvoiceStatus::Draft->value);
    $invoiceDiscountType = old('discount_type', $invoice?->discount_type);
    $invoiceDiscountAmount = old('discount_amount', $invoice?->discount_amount);
    $invoiceNotes = old('notes', $invoice?->notes);
    $settableStatuses = \App\Http\Requests\Invoice\StoreInvoiceRequest::settableStatuses();
    $itemErrors = collect($errors->getMessages())->filter(fn ($messages, $key) => str_starts_with($key, 'items.'))->flatten();
@endphp

<form
    method="POST"
    action="{{ $action }}"
    class="space-y-5"
    x-data="quotationEditor({{ Js::from($catalog) }}, {{ $taxesEnabled ? 'true' : 'false' }}, {{ Js::from($initialRows) }})"
>
    @csrf
    @if ($method !== 'POST')
        @method($method)
    @endif

    <x-ui.card>
        <x-slot:header>
            <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('invoices.information') }}</h2>
        </x-slot:header>

        <div class="grid gap-x-5 gap-y-4 sm:grid-cols-2">
            <div>
                <x-ui.select name="customer_id" :label="__('invoices.customer')" :value="$invoiceCustomerId" required>
                    <option value="" disabled @selected($invoiceCustomerId === null)>{{ __('invoices.no_customer') }}</option>
                    @foreach ($customers as $customer)
                        <option value="{{ $customer->id }}" @selected((int) $invoiceCustomerId === $customer->id)>
                            {{ $customer->name }}
                            @if ($customer->company_name)
                                ({{ $customer->company_name }})
                            @endif
                        </option>
                    @endforeach
                </x-ui.select>
            </div>

            <div>
                <x-ui.select name="status" :label="__('invoices.status')" :value="$invoiceStatus" required>
                    @foreach ($settableStatuses as $value)
                        <option value="{{ $value }}" @selected($invoiceStatus === $value)>
                            {{ __('invoices.statuses.'.$value) }}
                        </option>
                    @endforeach
                </x-ui.select>
            </div>

            <div>
                <x-ui.input type="date" name="date" :label="__('invoices.date')" :value="$invoiceDate" required />
            </div>

            <div>
                <x-ui.select name="currency_code" :label="__('currencies.currency')" :value="$invoiceCurrency">
                    @foreach ($currencies as $currency)
                        <option value="{{ $currency->code }}" @selected($invoiceCurrency === $currency->code)>
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
                <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('invoices.line_items') }}</h2>
                <x-ui.button type="button" variant="secondary" size="sm" icon="plus" x-on:click="addRow">
                    {{ __('invoices.action_add_line') }}
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
            <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-700">
                <thead>
                    <tr>
                        <x-ui.th>{{ __('invoices.product') }}</x-ui.th>
                        <x-ui.th>{{ __('invoices.description') }}</x-ui.th>
                        <x-ui.th>{{ __('invoices.quantity') }}</x-ui.th>
                        <x-ui.th>{{ __('invoices.unit_price') }}</x-ui.th>
                        @if ($taxesEnabled)
                            <x-ui.th>{{ __('invoices.tax') }}</x-ui.th>
                        @endif
                        <x-ui.th class="text-end">
                            <span class="sr-only">{{ __('invoices.action_remove_line') }}</span>
                        </x-ui.th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <template x-for="(row, index) in rows" :key="index">
                        <tr>
                            <td class="py-3 pe-3 align-top">
                                <select
                                    :id="`item-${index}-product`"
                                    :name="`items[${index}][product_id]`"
                                    x-model="row.product_id"
                                    x-on:change="pickProduct(row)"
                                    class="block w-full min-w-40 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm transition-colors duration-150 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:focus:border-brand-400"
                                >
                                    <option value="">{{ __('invoices.no_product') }}</option>
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
                                    :placeholder="`{{ __('invoices.line_placeholder') }}`"
                                    class="block w-full min-w-52 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm transition-colors duration-150 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:focus:border-brand-400"
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
                                    class="block w-28 rounded-lg border border-slate-300 bg-white px-3 py-2 text-end tabular-nums text-sm text-slate-900 shadow-sm transition-colors duration-150 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:focus:border-brand-400"
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
                                    class="block w-32 rounded-lg border border-slate-300 bg-white px-3 py-2 text-end tabular-nums text-sm text-slate-900 shadow-sm transition-colors duration-150 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:focus:border-brand-400"
                                />
                            </td>
                            @if ($taxesEnabled)
                                <td class="py-3 pe-3 align-top">
                                    <select
                                        :id="`item-${index}-tax`"
                                        :name="`items[${index}][tax_id]`"
                                        x-model="row.tax_id"
                                        class="block w-full min-w-36 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm transition-colors duration-150 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:focus:border-brand-400"
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
                                    :aria-label="'{{ __('invoices.action_remove_line') }}'"
                                    class="inline-flex size-8 shrink-0 items-center justify-center rounded-lg border border-slate-300 bg-white text-slate-700 shadow-sm transition-colors duration-150 hover:bg-slate-50 disabled:pointer-events-none disabled:opacity-50 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700"
                                >
                                    <x-ui.icon name="trash" class="size-4" />
                                </button>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <p class="mt-3 text-xs text-slate-500 dark:text-slate-400" x-show="rows.length <= 1">
            {{ __('invoices.validation.items_min') }}
        </p>
    </x-ui.card>

    <x-ui.card>
        <x-slot:header>
            <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('invoices.total') }}</h2>
        </x-slot:header>

        <div class="grid gap-x-5 gap-y-4 sm:grid-cols-2">
            <div>
                <x-ui.select name="discount_type" :label="__('invoices.discount_type')" :placeholder="__('invoices.no_discount')" :value="$invoiceDiscountType">
                    <option value="percentage" @selected($invoiceDiscountType === 'percentage')>
                        {{ __('invoices.discount_types.percentage') }}
                    </option>
                    <option value="fixed" @selected($invoiceDiscountType === 'fixed')>
                        {{ __('invoices.discount_types.fixed') }}
                    </option>
                </x-ui.select>
            </div>

            <div>
                <x-ui.number-input
                    name="discount_amount"
                    :label="__('invoices.discount_amount')"
                    :value="$invoiceDiscountAmount ?? ''"
                    min="0"
                    step="0.0001"
                />
            </div>

            <div class="sm:col-span-2">
                <x-ui.textarea
                    name="notes"
                    :label="__('invoices.notes')"
                    :value="$invoiceNotes"
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
        <x-ui.button variant="secondary" href="{{ route('invoices.index') }}">
            {{ __('actions.cancel') }}
        </x-ui.button>
    </div>
</form>