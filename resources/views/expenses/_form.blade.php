@php
    $expense = $expense ?? null;
    $method = $method ?? 'POST';
    $categories = $categories ?? collect();
    $currencies = $currencies ?? collect();
    $currencyBase = $currencyBase ?? null;
    $expenseCurrency = old('currency_code', $expense?->currency_code ?? $currencyBase);
@endphp

<form method="POST" action="{{ $action }}" enctype="multipart/form-data" class="space-y-5">
    @csrf
    @if ($method !== 'POST')
        @method($method)
    @endif

    <x-ui.card>
        <x-slot:header>
            <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('expenses.information') }}</h2>
        </x-slot:header>

        <div class="grid gap-x-5 gap-y-4 sm:grid-cols-2">
            <div>
                <x-ui.input
                    name="expense_date"
                    type="date"
                    :label="__('expenses.date')"
                    :value="old('expense_date', $expense?->expense_date?->format('Y-m-d'))"
                    maxlength="10"
                    required
                />
            </div>

            <div>
                <x-ui.select
                    name="currency_code"
                    :label="__('currencies.currency')"
                    :value="$expenseCurrency"
                >
                    @foreach ($currencies as $currency)
                        <option value="{{ $currency->code }}" @selected($expenseCurrency === $currency->code)>
                            {{ $currency->code }} — {{ $currency->name }}
                        </option>
                    @endforeach
                </x-ui.select>
            </div>

            <div>
                <x-ui.select
                    name="category_id"
                    :label="__('expenses.category')"
                    :placeholder="__('expenses.no_category')"
                    :value="old('category_id', $expense?->category_id)"
                >
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected(old('category_id', $expense?->category_id) === $category->id)>
                            {{ $category->name }}
                        </option>
                    @endforeach
                </x-ui.select>
            </div>

            <div>
                <x-ui.number-input
                    name="amount"
                    :label="__('expenses.amount')"
                    :value="old('amount', $expense?->amount)"
                    min="0"
                    max="999999999999.9999"
                    step="0.0001"
                    inputmode="decimal"
                    placeholder="0.00"
                    required
                />
            </div>

            <div>
                <x-ui.select
                    name="payment_method"
                    :label="__('expenses.method')"
                    :placeholder="__('expenses.no_method')"
                    :value="old('payment_method', $expense?->payment_method?->value)"
                >
                    @foreach (\App\Enums\PaymentMethod::cases() as $case)
                        <option value="{{ $case->value }}" @selected(old('payment_method', $expense?->payment_method?->value) === $case->value)>
                            {{ __('expenses.methods.'.$case->value) }}
                        </option>
                    @endforeach
                </x-ui.select>
            </div>
        </div>
    </x-ui.card>

    <x-ui.card>
        <x-slot:header>
            <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('expenses.details') }}</h2>
        </x-slot:header>

        <div class="grid gap-x-5 gap-y-4 sm:grid-cols-2">
            <div>
                <x-ui.input
                    name="vendor"
                    :label="__('expenses.vendor')"
                    :value="old('vendor', $expense?->vendor)"
                    maxlength="150"
                />
            </div>

            <div>
                <x-ui.input
                    name="reference"
                    :label="__('expenses.reference')"
                    :value="old('reference', $expense?->reference)"
                    maxlength="100"
                    dir="ltr"
                />
            </div>

            <div class="sm:col-span-2">
                <x-ui.textarea
                    name="notes"
                    :label="__('expenses.notes')"
                    :value="old('notes', $expense?->notes)"
                    rows="3"
                    maxlength="2000"
                />
            </div>
        </div>
    </x-ui.card>

    <x-ui.card>
        <x-slot:header>
            <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('expenses.receipt') }}</h2>
        </x-slot:header>

        @if ($expense?->receipt_path)
            <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 dark:border-slate-700 dark:bg-slate-800/60">
                <div class="flex min-w-0 items-center gap-3">
                    <x-ui.icon name="document-text" class="size-6 shrink-0 text-brand-600 dark:text-brand-400" aria-hidden="true" />
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium text-slate-900 dark:text-white" dir="ltr">
                            {{ basename($expense->receipt_path) }}
                        </p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ __('expenses.receipt_current') }}</p>
                    </div>
                </div>
                <x-ui.button variant="secondary" size="sm" href="{{ route('expenses.receipt', $expense) }}" icon="eye">
                    {{ __('expenses.view_receipt') }}
                </x-ui.button>
            </div>

            <x-ui.checkbox
                name="remove_receipt"
                :label="__('expenses.receipt_remove')"
                :helper="__('expenses.receipt_remove_confirm')"
                value="1"
                :checked="(bool) old('remove_receipt')"
            />
        @else
            <x-ui.file-input
                name="receipt"
                :label="__('expenses.receipt_upload')"
                :helper="__('expenses.receipt_helper')"
                accept=".jpg,.jpeg,.png,.webp,.pdf"
            />
        @endif
    </x-ui.card>

    <div class="flex flex-wrap items-center justify-end gap-3">
        <x-ui.button type="submit" icon="check-circle">
            {{ $method === 'PATCH' ? __('actions.update') : __('actions.create') }}
        </x-ui.button>
        <x-ui.button variant="secondary" href="{{ route('expenses.index') }}">
            {{ __('actions.cancel') }}
        </x-ui.button>
    </div>
</form>