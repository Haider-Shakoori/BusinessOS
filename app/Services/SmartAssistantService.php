<?php

namespace App\Services;

use App\Models\CrmLead;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use Illuminate\Support\Facades\Gate;

class SmartAssistantService
{
    /**
     * Permission-aware, deterministic operational assistant.
     *
     * It never sends business data to an external model. Every metric is
     * calculated from the current tenant and only when the signed-in user has
     * the corresponding read permission.
     *
     * @return array{answer: string, topic: string, facts: array<string, mixed>}
     */
    public function answer(string $question): array
    {
        $question = trim($question);
        $normalized = mb_strtolower($question);

        $topic = $this->topic($normalized);
        $facts = $this->facts($topic);

        if ($facts === []) {
            return [
                'answer' => __('assistant.answers.no_permission_or_data'),
                'topic' => $topic,
                'facts' => [],
            ];
        }

        return [
            'answer' => $this->render($topic, $facts),
            'topic' => $topic,
            'facts' => $facts,
        ];
    }

    private function topic(string $question): string
    {
        return match (true) {
            str_contains($question, 'inventory'),
            str_contains($question, 'stock'),
            str_contains($question, 'موجود'),
            str_contains($question, 'مخزون') => 'inventory',

            str_contains($question, 'purchase'),
            str_contains($question, 'supplier'),
            str_contains($question, 'خرید'),
            str_contains($question, 'مشتريات') => 'purchasing',

            str_contains($question, 'lead'),
            str_contains($question, 'crm'),
            str_contains($question, 'فرصت'),
            str_contains($question, 'عميل محتمل') => 'crm',

            str_contains($question, 'production'),
            str_contains($question, 'manufactur'),
            str_contains($question, 'تولید'),
            str_contains($question, 'تصنيع') => 'manufacturing',

            str_contains($question, 'expense'),
            str_contains($question, 'cost'),
            str_contains($question, 'هزینه'),
            str_contains($question, 'مصروف') => 'expenses',

            str_contains($question, 'employee'),
            str_contains($question, 'staff'),
            str_contains($question, 'کارمند'),
            str_contains($question, 'موظف') => 'employees',

            str_contains($question, 'customer'),
            str_contains($question, 'receivable'),
            str_contains($question, 'invoice'),
            str_contains($question, 'sales'),
            str_contains($question, 'مشتری'),
            str_contains($question, 'فروش'),
            str_contains($question, 'عميل'),
            str_contains($question, 'مبيعات') => 'sales',

            default => 'overview',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function facts(string $topic): array
    {
        return match ($topic) {
            'sales' => Gate::allows('invoices.view') ? [
                'customers' => Gate::allows('customers.view') ? Customer::query()->count() : null,
                'invoices' => Invoice::query()->where('status', '!=', 'draft')->count(),
                'sales_total' => (float) Invoice::query()->where('status', '!=', 'draft')->sum('total'),
                'receivables' => (float) Invoice::query()->where('status', '!=', 'draft')->sum('amount_due'),
            ] : [],
            'inventory' => Gate::allows('inventory.view') ? [
                'products' => Gate::allows('products.view') ? Product::query()->count() : null,
                'stock_quantity' => (float) StockMovement::query()->sum('quantity'),
                'movement_count' => StockMovement::query()->count(),
                'negative_products' => StockMovement::query()
                    ->selectRaw('product_id, SUM(quantity) as qty')
                    ->groupBy('product_id')
                    ->havingRaw('SUM(quantity) < 0')
                    ->count(),
            ] : [],
            'purchasing' => Gate::allows('purchasing.view') ? [
                'orders' => PurchaseOrder::query()->count(),
                'open_orders' => PurchaseOrder::query()->where('status', '!=', 'received')->count(),
                'purchased_total' => (float) PurchaseOrder::query()->sum('total'),
            ] : [],
            'crm' => Gate::allows('crm.view') ? [
                'leads' => CrmLead::query()->count(),
                'qualified' => CrmLead::query()->where('status', 'qualified')->count(),
                'won' => CrmLead::query()->where('status', 'won')->count(),
                'pipeline_value' => (float) CrmLead::query()->whereNotIn('status', ['won', 'lost'])->sum('estimated_value'),
            ] : [],
            'manufacturing' => Gate::allows('manufacturing.view') ? [
                'orders' => ProductionOrder::query()->count(),
                'planned' => ProductionOrder::query()->where('status', 'planned')->count(),
                'completed' => ProductionOrder::query()->where('status', 'completed')->count(),
                'planned_quantity' => (float) ProductionOrder::query()->sum('planned_quantity'),
                'actual_quantity' => (float) ProductionOrder::query()->where('status', 'completed')->sum('actual_quantity'),
            ] : [],
            'expenses' => Gate::allows('expenses.view') ? [
                'expense_count' => Expense::query()->count(),
                'expense_total' => (float) Expense::query()->sum('amount'),
                'this_month' => (float) Expense::query()
                    ->whereBetween('expense_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
                    ->sum('amount'),
            ] : [],
            'employees' => Gate::allows('settings.view') ? [
                'employees' => Employee::query()->count(),
                'active_employees' => Employee::query()->where('is_active', true)->count(),
            ] : [],
            default => $this->overview(),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function overview(): array
    {
        $facts = [];

        if (Gate::allows('customers.view')) {
            $facts['customers'] = Customer::query()->count();
        }

        if (Gate::allows('invoices.view')) {
            $facts['sales_total'] = (float) Invoice::query()->where('status', '!=', 'draft')->sum('total');
            $facts['receivables'] = (float) Invoice::query()->where('status', '!=', 'draft')->sum('amount_due');
        }

        if (Gate::allows('expenses.view')) {
            $facts['expenses_total'] = (float) Expense::query()->sum('amount');
        }

        if (Gate::allows('inventory.view')) {
            $facts['stock_quantity'] = (float) StockMovement::query()->sum('quantity');
        }

        if (Gate::allows('crm.view')) {
            $facts['open_leads'] = CrmLead::query()->whereNotIn('status', ['won', 'lost'])->count();
        }

        return $facts;
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private function render(string $topic, array $facts): string
    {
        $pairs = collect($facts)
            ->reject(fn ($value): bool => $value === null)
            ->map(fn ($value, $key): string => __('assistant.fact_labels.'.$key).': '.$this->format($value))
            ->values()
            ->all();

        return __('assistant.answers.'.$topic, ['facts' => implode(' · ', $pairs)]);
    }

    private function format(mixed $value): string
    {
        if (is_float($value)) {
            return number_format($value, 2);
        }

        return (string) $value;
    }
}
