<?php

namespace App\Console\Commands;

use App\Services\InventoryReservationService;
use Illuminate\Console\Command;

class ExpireInventoryReservations extends Command
{
    protected $signature = 'inventory:reservations-expire';

    protected $description = 'Mark due inventory reservations as expired so stock becomes available again.';

    public function handle(InventoryReservationService $reservations): int
    {
        $count = $reservations->expireDue();

        $this->info(__('operations.reservations.command_summary', ['count' => $count]));

        return self::SUCCESS;
    }
}
