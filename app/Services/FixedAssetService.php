<?php

namespace App\Services;

use App\Models\AssetDepreciationEntry;
use App\Models\FixedAsset;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FixedAssetService
{
    public function __construct(
        private readonly AccountingPostingService $accounting,
        private readonly FiscalPeriodService $periods,
    ) {
        //
    }

    /**
     * @param array{
     *   asset_category_id:int,
     *   cost_center_id?:int|null,
     *   asset_number:string,
     *   name:string,
     *   serial_number?:string|null,
     *   location?:string|null,
     *   acquisition_date:string,
     *   in_service_date:string,
     *   acquisition_cost:string|int|float,
     *   salvage_value?:string|int|float|null,
     *   useful_life_months:int,
     *   depreciation_method:string,
     *   payment_method:string,
     *   notes?:string|null
     * } $data
     */
    public function acquire(array $data): FixedAsset
    {
        $this->periods->assertPostingAllowed($data['acquisition_date']);

        return DB::transaction(function () use ($data): FixedAsset {
            $cost = Decimal::normalize((string) $data['acquisition_cost']);
            $salvage = Decimal::normalize((string) ($data['salvage_value'] ?? '0'));

            if (Decimal::gt($salvage, $cost)) {
                throw new RuntimeException('Asset salvage value cannot exceed acquisition cost.');
            }

            $asset = FixedAsset::create([
                'asset_category_id' => $data['asset_category_id'],
                'cost_center_id' => $data['cost_center_id'] ?? null,
                'asset_number' => $data['asset_number'],
                'name' => $data['name'],
                'serial_number' => $data['serial_number'] ?? null,
                'location' => $data['location'] ?? null,
                'acquisition_date' => $data['acquisition_date'],
                'in_service_date' => $data['in_service_date'],
                'acquisition_cost' => $cost,
                'salvage_value' => $salvage,
                'useful_life_months' => $data['useful_life_months'],
                'depreciation_method' => $data['depreciation_method'],
                'status' => 'active',
                'accumulated_depreciation' => '0',
                'book_value' => $cost,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->accounting->postAssetAcquisition($asset, $data['payment_method']);

            return $asset->fresh(['category', 'costCenter']);
        });
    }

    public function depreciateThrough(FixedAsset $asset, string $throughDate): FixedAsset
    {
        if ($asset->status !== 'active') {
            return $asset;
        }

        $through = CarbonImmutable::parse($throughDate)->endOfMonth();
        $serviceStart = CarbonImmutable::parse($asset->in_service_date)->startOfMonth();

        if ($through->lt($serviceStart)) {
            return $asset;
        }

        return DB::transaction(function () use ($asset, $through, $serviceStart): FixedAsset {
            $locked = FixedAsset::query()
                ->with('category')
                ->whereKey($asset->id)
                ->lockForUpdate()
                ->firstOrFail();

            $nextPeriod = $locked->last_depreciated_through
                ? CarbonImmutable::parse($locked->last_depreciated_through)->addMonthNoOverflow()->startOfMonth()
                : $serviceStart;

            while ($nextPeriod->lte($through) && Decimal::gt((string) $locked->book_value, (string) $locked->salvage_value)) {
                $periodEnd = $nextPeriod->endOfMonth();

                if ($periodEnd->gt($through)) {
                    break;
                }

                $this->periods->assertPostingAllowed($periodEnd->toDateString());

                $amount = $this->periodDepreciation($locked);
                $maxDepreciation = Decimal::sub((string) $locked->book_value, (string) $locked->salvage_value);
                $amount = Decimal::max($amount, $maxDepreciation);

                if (! Decimal::gt($amount, '0')) {
                    break;
                }

                $accumulatedAfter = Decimal::add((string) $locked->accumulated_depreciation, $amount);
                $bookValueAfter = Decimal::sub((string) $locked->book_value, $amount);

                $journal = $this->accounting->postAssetDepreciation($locked, $periodEnd->toDateString(), $amount);

                AssetDepreciationEntry::create([
                    'fixed_asset_id' => $locked->id,
                    'journal_entry_id' => $journal->id,
                    'period_start' => $nextPeriod->toDateString(),
                    'period_end' => $periodEnd->toDateString(),
                    'amount' => $amount,
                    'accumulated_after' => $accumulatedAfter,
                    'book_value_after' => $bookValueAfter,
                ]);

                $locked->forceFill([
                    'accumulated_depreciation' => $accumulatedAfter,
                    'book_value' => $bookValueAfter,
                    'last_depreciated_through' => $periodEnd->toDateString(),
                ])->save();

                $nextPeriod = $nextPeriod->addMonthNoOverflow()->startOfMonth();
            }

            return $locked->fresh(['category', 'costCenter', 'depreciationEntries.journalEntry']);
        });
    }

    public function dispose(FixedAsset $asset, string $date, string $proceeds, ?string $note = null): FixedAsset
    {
        $disposalDay = CarbonImmutable::parse($date);
        $depreciationThrough = $disposalDay->isLastOfMonth()
            ? $disposalDay
            : $disposalDay->subMonthNoOverflow()->endOfMonth();

        if ($depreciationThrough->gte(CarbonImmutable::parse($asset->in_service_date)->startOfMonth())) {
            $this->depreciateThrough($asset, $depreciationThrough->toDateString());
        }

        $this->periods->assertPostingAllowed($date);

        return DB::transaction(function () use ($asset, $date, $proceeds, $note): FixedAsset {
            $locked = FixedAsset::query()->with('category')->whereKey($asset->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'disposed') {
                return $locked;
            }

            $normalizedProceeds = Decimal::normalize($proceeds);
            $this->accounting->postAssetDisposal($locked, $date, $normalizedProceeds);

            $locked->forceFill([
                'status' => 'disposed',
                'disposed_at' => $date,
                'disposal_proceeds' => $normalizedProceeds,
                'notes' => trim(implode(PHP_EOL, array_filter([$locked->notes, $note]))),
            ])->save();

            return $locked->fresh(['category', 'costCenter']);
        });
    }

    private function periodDepreciation(FixedAsset $asset): string
    {
        $depreciableBase = Decimal::sub((string) $asset->acquisition_cost, (string) $asset->salvage_value);

        if ($asset->depreciation_method === 'declining_balance') {
            $monthlyRate = bcdiv('2', (string) max(1, $asset->useful_life_months), Decimal::INTERNAL_SCALE);

            return Decimal::round(Decimal::mul((string) $asset->book_value, $monthlyRate));
        }

        return Decimal::mulDiv($depreciableBase, '1', (string) max(1, $asset->useful_life_months));
    }
}
