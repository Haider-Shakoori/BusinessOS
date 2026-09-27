<?php

namespace App\Services;

use App\Models\Account;
use App\Models\FxRevaluation;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class FxRevaluationService
{
    public function __construct(
        private readonly CurrencyService $currencies,
        private readonly FiscalPeriodService $periods,
    ) {}

    public function revalueOpenReceivables(string $date): int
    {
        $this->periods->assertPostingAllowed($date);
        $baseCurrency = $this->currencies->baseCurrency();
        $count = 0;

        Invoice::query()
            ->whereNotIn('status', ['draft', 'paid'])
            ->where('amount_due', '>', 0)
            ->where('currency_code', '!=', $baseCurrency)
            ->orderBy('id')
            ->chunkById(100, function ($invoices) use ($date, &$count): void {
                foreach ($invoices as $invoice) {
                    if (FxRevaluation::query()->where('invoice_id', $invoice->id)->whereDate('revaluation_date', $date)->exists()) {
                        continue;
                    }

                    $closingRate = $this->currencies->resolveOrFail((string) $invoice->currency_code, $date);
                    $foreignBalance = Decimal::normalize((string) $invoice->amount_due);
                    $historicalBase = Decimal::round(Decimal::mul($foreignBalance, (string) $invoice->exchange_rate));
                    $revaluedBase = Decimal::round(Decimal::mul($foreignBalance, $closingRate));
                    $adjustment = Decimal::sub($revaluedBase, $historicalBase);

                    if (Decimal::isZero($adjustment)) {
                        continue;
                    }

                    $this->post($invoice, $date, $closingRate, $foreignBalance, $historicalBase, $revaluedBase, $adjustment);
                    $count++;
                }
            });

        return $count;
    }

    private function post(
        Invoice $invoice,
        string $date,
        string $closingRate,
        string $foreignBalance,
        string $historicalBase,
        string $revaluedBase,
        string $adjustment,
    ): void {
        DB::transaction(function () use ($invoice, $date, $closingRate, $foreignBalance, $historicalBase, $revaluedBase, $adjustment): void {
            $ar = $this->account('AUTO-AR', 'Accounts Receivable', 'asset');
            $gain = $this->account('AUTO-FX-UNREALIZED-GAIN', 'Unrealized Foreign Exchange Gain', 'income');
            $loss = $this->account('AUTO-FX-UNREALIZED-LOSS', 'Unrealized Foreign Exchange Loss', 'expense');
            $amount = Decimal::normalize(ltrim($adjustment, '-'));
            $number = 'FXR-'.$invoice->invoice_number.'-'.$date;

            $entry = JournalEntry::create([
                'number' => mb_substr($number, 0, 80),
                'entry_date' => $date,
                'status' => 'posted',
                'description' => 'FX revaluation '.$invoice->invoice_number.' at '.$date,
                'source_type' => FxRevaluation::class,
                'source_id' => $invoice->id,
            ]);

            if (Decimal::gt($adjustment, '0')) {
                $entry->lines()->create(['account_id' => $ar->id, 'debit' => $amount, 'credit' => '0']);
                $entry->lines()->create(['account_id' => $gain->id, 'debit' => '0', 'credit' => $amount]);
            } else {
                $entry->lines()->create(['account_id' => $loss->id, 'debit' => $amount, 'credit' => '0']);
                $entry->lines()->create(['account_id' => $ar->id, 'debit' => '0', 'credit' => $amount]);
            }

            $reversalDate = CarbonImmutable::parse($date)->addDay()->toDateString();
            $this->periods->assertPostingAllowed($reversalDate);
            $reversal = JournalEntry::create([
                'number' => mb_substr('REV-'.$number, 0, 80),
                'entry_date' => $reversalDate,
                'status' => 'posted',
                'description' => 'Automatic reversal of '.$number,
                'source_type' => 'reversal:'.FxRevaluation::class,
                'source_id' => $invoice->id,
            ]);

            foreach ($entry->lines()->get() as $line) {
                $reversal->lines()->create([
                    'account_id' => $line->account_id,
                    'debit' => $line->credit,
                    'credit' => $line->debit,
                ]);
            }

            FxRevaluation::create([
                'invoice_id' => $invoice->id,
                'revaluation_date' => $date,
                'currency_code' => $invoice->currency_code,
                'closing_rate' => $closingRate,
                'foreign_balance' => $foreignBalance,
                'historical_base_balance' => $historicalBase,
                'revalued_base_balance' => $revaluedBase,
                'adjustment' => $adjustment,
                'journal_entry_id' => $entry->id,
                'reversal_journal_entry_id' => $reversal->id,
            ]);
        });
    }

    private function account(string $code, string $name, string $type): Account
    {
        return Account::firstOrCreate(['code' => $code], ['name' => $name, 'type' => $type, 'is_active' => true]);
    }
}
