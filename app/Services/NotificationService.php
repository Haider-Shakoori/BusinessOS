<?php

namespace App\Services;

use App\Enums\ProductType;
use App\Models\BusinessNotification;
use App\Models\CrmLead;
use App\Models\PosShift;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\Warehouse;

class NotificationService
{
    /**
     * Refresh deterministic operational alerts without creating duplicates.
     */
    public function refreshOperationalAlerts(): void
    {
        $seen = [];

        $this->refreshLowStock($seen);
        $this->refreshOverduePurchases($seen);
        $this->refreshCrmFollowUps($seen);
        $this->refreshProductionDueDates($seen);
        $this->refreshLongOpenShifts($seen);

        BusinessNotification::query()
            ->where('is_active', true)
            ->whereIn('type', ['low_stock', 'purchase_overdue', 'crm_follow_up', 'production_overdue', 'pos_shift_long'])
            ->when($seen !== [], fn ($query) => $query->whereNotIn('dedupe_key', $seen))
            ->update([
                'is_active' => false,
                'resolved_at' => now(),
            ]);
    }

    /**
     * @param  list<string>  $seen
     */
    private function refreshLowStock(array &$seen): void
    {
        $threshold = 5.0;
        $warehouses = Warehouse::query()->where('is_active', true)->get()->keyBy('id');
        $products = Product::query()
            ->where('type', ProductType::Product->value)
            ->get()
            ->keyBy('id');

        $balances = StockMovement::query()
            ->selectRaw('warehouse_id, product_id, SUM(quantity) as quantity')
            ->groupBy('warehouse_id', 'product_id')
            ->havingRaw('SUM(quantity) <= ?', [$threshold])
            ->get();

        foreach ($balances as $balance) {
            $warehouse = $warehouses->get($balance->warehouse_id);
            $product = $products->get($balance->product_id);

            if (! $warehouse || ! $product) {
                continue;
            }

            $key = 'low-stock:'.$warehouse->id.':'.$product->id;
            $seen[] = $key;

            $this->upsert($key, [
                'type' => 'low_stock',
                'level' => (float) $balance->quantity <= 0 ? 'danger' : 'warning',
                'title' => __('productivity.notifications.low_stock_title', ['product' => $product->name]),
                'message' => __('productivity.notifications.low_stock_message', [
                    'warehouse' => $warehouse->name,
                    'quantity' => number_format((float) $balance->quantity, 4, '.', ''),
                ]),
                'action_url' => route('inventory.index'),
                'data' => [
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => (float) $balance->quantity,
                ],
            ]);
        }
    }

    /**
     * @param  list<string>  $seen
     */
    private function refreshOverduePurchases(array &$seen): void
    {
        foreach (PurchaseOrder::query()
            ->with('supplier')
            ->whereNotNull('expected_date')
            ->whereDate('expected_date', '<', today())
            ->where('status', '!=', 'received')
            ->get() as $order) {
            $key = 'purchase-overdue:'.$order->id;
            $seen[] = $key;

            $this->upsert($key, [
                'type' => 'purchase_overdue',
                'level' => 'warning',
                'title' => __('productivity.notifications.purchase_overdue_title', ['number' => $order->number]),
                'message' => __('productivity.notifications.purchase_overdue_message', [
                    'supplier' => $order->supplier?->name ?? '—',
                    'date' => $order->expected_date?->format('Y-m-d') ?? '—',
                ]),
                'action_url' => route('purchasing.index'),
                'data' => ['purchase_order_id' => $order->id],
            ]);
        }
    }

    /**
     * @param  list<string>  $seen
     */
    private function refreshCrmFollowUps(array &$seen): void
    {
        foreach (CrmLead::query()
            ->whereNotNull('next_follow_up_at')
            ->where('next_follow_up_at', '<=', now())
            ->whereNotIn('status', ['won', 'lost'])
            ->get() as $lead) {
            $key = 'crm-follow-up:'.$lead->id;
            $seen[] = $key;

            $this->upsert($key, [
                'type' => 'crm_follow_up',
                'level' => 'info',
                'title' => __('productivity.notifications.crm_follow_up_title', ['lead' => $lead->name]),
                'message' => __('productivity.notifications.crm_follow_up_message', [
                    'time' => $lead->next_follow_up_at?->format('Y-m-d H:i') ?? '—',
                ]),
                'action_url' => route('crm.index'),
                'data' => ['crm_lead_id' => $lead->id],
            ]);
        }
    }

    /**
     * @param  list<string>  $seen
     */
    private function refreshProductionDueDates(array &$seen): void
    {
        foreach (ProductionOrder::query()
            ->with('product')
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', today())
            ->where('status', '!=', 'completed')
            ->get() as $order) {
            $key = 'production-overdue:'.$order->id;
            $seen[] = $key;

            $this->upsert($key, [
                'type' => 'production_overdue',
                'level' => 'warning',
                'title' => __('productivity.notifications.production_overdue_title', ['number' => $order->number]),
                'message' => __('productivity.notifications.production_overdue_message', [
                    'product' => $order->product?->name ?? '—',
                    'date' => $order->due_date?->format('Y-m-d') ?? '—',
                ]),
                'action_url' => route('manufacturing.index'),
                'data' => ['production_order_id' => $order->id],
            ]);
        }
    }

    /**
     * @param  list<string>  $seen
     */
    private function refreshLongOpenShifts(array &$seen): void
    {
        foreach (PosShift::query()
            ->with(['register', 'user'])
            ->where('status', 'open')
            ->where('opened_at', '<=', now()->subHours(16))
            ->get() as $shift) {
            $key = 'pos-shift-long:'.$shift->id;
            $seen[] = $key;

            $this->upsert($key, [
                'type' => 'pos_shift_long',
                'level' => 'warning',
                'title' => __('productivity.notifications.pos_shift_title', ['register' => $shift->register?->name ?? '—']),
                'message' => __('productivity.notifications.pos_shift_message', [
                    'user' => $shift->user?->name ?? '—',
                    'time' => $shift->opened_at?->format('Y-m-d H:i') ?? '—',
                ]),
                'action_url' => route('pos.index', ['register' => $shift->pos_register_id]),
                'data' => ['pos_shift_id' => $shift->id],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function upsert(string $dedupeKey, array $attributes): void
    {
        $notification = BusinessNotification::query()->where('dedupe_key', $dedupeKey)->first();

        if ($notification === null) {
            BusinessNotification::create([
                'dedupe_key' => $dedupeKey,
                'is_active' => true,
                ...$attributes,
            ]);

            return;
        }

        $wasResolved = ! $notification->is_active || $notification->resolved_at !== null;

        $notification->fill([
            ...$attributes,
            'is_active' => true,
            'resolved_at' => null,
        ]);

        if ($wasResolved) {
            $notification->read_at = null;
        }

        $notification->save();
    }
}
