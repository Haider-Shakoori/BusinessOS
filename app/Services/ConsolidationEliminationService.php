<?php

namespace App\Services;

use App\Models\ConsolidationElimination;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConsolidationEliminationService
{
    private const TYPES = ['asset', 'liability', 'equity', 'income', 'expense'];

    public function create(array $data, array $businessIds, int $userId): ConsolidationElimination
    {
        return DB::transaction(function () use ($data, $businessIds, $userId): ConsolidationElimination {
            $debits = '0.0000';
            $credits = '0.0000';

            foreach ($data['lines'] as $index => $line) {
                if (! in_array($line['statement_type'], self::TYPES, true)) {
                    throw ValidationException::withMessages(["lines.$index.statement_type" => 'Invalid financial statement classification.']);
                }

                $debit = Decimal::normalize((string) ($line['debit'] ?? '0'));
                $credit = Decimal::normalize((string) ($line['credit'] ?? '0'));

                if (Decimal::lt($debit, '0') || Decimal::lt($credit, '0')) {
                    throw ValidationException::withMessages(["lines.$index.debit" => 'Elimination amounts cannot be negative.']);
                }

                if ((Decimal::gt($debit, '0') && Decimal::gt($credit, '0'))
                    || (! Decimal::gt($debit, '0') && ! Decimal::gt($credit, '0'))) {
                    throw ValidationException::withMessages(["lines.$index.debit" => 'Each elimination line must contain either a debit or a credit.']);
                }

                $debits = Decimal::add($debits, $debit);
                $credits = Decimal::add($credits, $credit);
            }

            if (Decimal::cmp($debits, $credits) !== 0) {
                throw ValidationException::withMessages(['lines' => 'Consolidation elimination debits and credits must balance.']);
            }

            $elimination = ConsolidationElimination::create([
                'group_key' => $this->groupKey($businessIds),
                'reference' => $data['reference'],
                'effective_date' => $data['effective_date'],
                'description' => $data['description'],
                'status' => 'posted',
                'created_by' => $userId,
            ]);

            foreach ($data['lines'] as $line) {
                $elimination->lines()->create([
                    'statement_type' => $line['statement_type'],
                    'debit' => Decimal::normalize((string) ($line['debit'] ?? '0')),
                    'credit' => Decimal::normalize((string) ($line['credit'] ?? '0')),
                    'memo' => $line['memo'] ?? null,
                ]);
            }

            return $elimination->fresh(['lines', 'creator']);
        });
    }

    public function groupKey(array $businessIds): string
    {
        $ids = collect($businessIds)->map(fn ($id): int => (int) $id)->filter()->unique()->sort()->values();

        return $ids->implode(':');
    }

    public function reverse(ConsolidationElimination $elimination, int $userId): ConsolidationElimination
    {
        if ($elimination->status === 'reversed') {
            return $elimination;
        }

        $elimination->update([
            'status' => 'reversed',
            'reversed_at' => now(),
            'reversed_by' => $userId,
        ]);

        return $elimination->fresh();
    }
}
