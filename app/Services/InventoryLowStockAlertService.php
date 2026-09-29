<?php

namespace App\Services;

use App\Models\BusinessMembership;
use App\Models\BusinessModule;
use App\Models\BusinessNotification;
use App\Models\InventoryReorderRule;
use App\Support\Decimal;

class InventoryLowStockAlertService
{
    public function __construct(private readonly InventoryReorderService $reorder)
    {
        //
    }

    /**
     * @return array{businesses:int,notifications:int,attention_items:int}
     */
    public function scanAll(): array
    {
        $businessIds = BusinessModule::query()
            ->where('module_key', 'inventory')
            ->where('enabled', true)
            ->distinct()
            ->pluck('business_id');

        $businesses = 0;
        $notifications = 0;
        $attentionItems = 0;

        foreach ($businessIds as $businessId) {
            $result = $this->scanBusiness((int) $businessId);
            $businesses++;
            $notifications += $result['notifications'];
            $attentionItems += $result['attention_items'];
        }

        return [
            'businesses' => $businesses,
            'notifications' => $notifications,
            'attention_items' => $attentionItems,
        ];
    }

    /**
     * @return array{notifications:int,attention_items:int,out_of_stock:int,low_stock:int}
     */
    public function scanBusiness(int $businessId): array
    {
        $rules = InventoryReorderRule::withoutGlobalScope('business')
            ->with(['warehouse', 'product', 'variant'])
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->get();

        $attention = $rules
            ->map(fn (InventoryReorderRule $rule): array => $this->reorder->row($rule))
            ->filter(function (array $row): bool {
                return in_array($row['status'], ['low', 'out_of_stock'], true)
                    && Decimal::gt($row['suggested_quantity'], '0');
            })
            ->values();

        $outOfStock = $attention->where('status', 'out_of_stock')->count();
        $lowStock = $attention->where('status', 'low')->count();
        $attentionCount = $attention->count();

        $recipients = BusinessMembership::query()
            ->where('business_id', $businessId)
            ->pluck('user_id')
            ->unique()
            ->values();

        if ($attentionCount === 0) {
            BusinessNotification::withoutGlobalScope('business')
                ->where('business_id', $businessId)
                ->where('type', 'inventory_low_stock')
                ->whereNull('read_at')
                ->update(['read_at' => now()]);

            return [
                'notifications' => 0,
                'attention_items' => 0,
                'out_of_stock' => 0,
                'low_stock' => 0,
            ];
        }

        $notifications = 0;
        $title = __('operations.inventory_intelligence.alert_title');
        $message = __('operations.inventory_intelligence.alert_message', [
            'count' => $attentionCount,
            'out' => $outOfStock,
            'low' => $lowStock,
        ]);
        $data = [
            'attention_items' => $attentionCount,
            'out_of_stock' => $outOfStock,
            'low_stock' => $lowStock,
        ];

        foreach ($recipients as $userId) {
            $existing = BusinessNotification::withoutGlobalScope('business')
                ->where('business_id', $businessId)
                ->where('user_id', $userId)
                ->where('type', 'inventory_low_stock')
                ->whereNull('read_at')
                ->latest('id')
                ->first();

            if ($existing !== null) {
                $existing->forceFill([
                    'title' => $title,
                    'message' => $message,
                    'action_url' => '/inventory/reorder',
                    'data' => $data,
                ])->save();

                continue;
            }

            $notification = new BusinessNotification([
                'user_id' => $userId,
                'type' => 'inventory_low_stock',
                'title' => $title,
                'message' => $message,
                'action_url' => '/inventory/reorder',
                'data' => $data,
            ]);
            $notification->business_id = $businessId;
            $notification->save();
            $notifications++;
        }

        return [
            'notifications' => $notifications,
            'attention_items' => $attentionCount,
            'out_of_stock' => $outOfStock,
            'low_stock' => $lowStock,
        ];
    }
}
