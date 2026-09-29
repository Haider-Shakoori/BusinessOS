<?php

namespace App\Console\Commands;

use App\Services\InventoryLowStockAlertService;
use Illuminate\Console\Command;

class SendInventoryReorderAlerts extends Command
{
    protected $signature = 'inventory:reorder-alerts';

    protected $description = 'Create or refresh low-stock notifications from active inventory reorder rules.';

    public function handle(InventoryLowStockAlertService $alerts): int
    {
        $result = $alerts->scanAll();

        $this->info(__('operations.inventory_intelligence.command_summary', [
            'businesses' => $result['businesses'],
            'notifications' => $result['notifications'],
            'items' => $result['attention_items'],
        ]));

        return self::SUCCESS;
    }
}
