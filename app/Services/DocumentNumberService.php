<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Models\DocumentNumberSequence;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Concurrency-safe, per-business / per-document-type numbering (Batch 13).
 *
 * Each business has one monotonic sequence per DocumentType that never resets
 * and never reuses a number. Every allocation runs inside a DB transaction and
 * locks the sequence row (SELECT ... FOR UPDATE) before incrementing, so
 * concurrent requests receive distinct, ordered numbers:
 *
 *   - existing row  -> lock it, increment it, format the result;
 *   - first use     -> seed a zeroed row; a concurrent loser of the unique
 *                      (business_id, document_type) constraint catches the
 *                      integrity error and re-reads the winner's locked row
 *                      (a single bounded retry, no MAX()+1 scanning);
 *   - overflow      -> the counter is BIGINT; at the limit the service refuses
 *                      to issue a number rather than wrap.
 *
 * Gaps are possible and accepted: a document whose outer transaction rolls
 * back also rolls back the counter increment (Laravel nests transactions as
 * savepoints), and numbers are never reused.
 *
 * The current business is resolved ONLY from BusinessContext. The service is
 * scoped (per request lifecycle) and the only authority over the
 * document_number_sequences table.
 */
class DocumentNumberService
{
    public function __construct(
        private readonly BusinessContext $context,
        private readonly BusinessSettings $settings,
    ) {
        //
    }

    /**
     * Allocate and return the next number for the current business + type.
     *
     * @throws RuntimeException when there is no current business, the
     *                          configured prefix/padding is invalid, or the
     *                          sequence is exhausted.
     */
    public function next(DocumentType $type): string
    {
        $businessId = $this->context->currentId();

        if ($businessId === null) {
            throw new RuntimeException('A current business is required to allocate a document number.');
        }

        return DB::transaction(function () use ($type, $businessId) {
            $sequence = $this->lockedSequence($businessId, $type);

            if ($sequence->last_number >= PHP_INT_MAX) {
                throw new RuntimeException('Document number sequence exhausted for "'.$type->value.'".');
            }

            $sequence->increment('last_number');

            return $this->format($type, $sequence->last_number);
        });
    }

    /**
     * The row for business + type, locked for update. Seeds a zeroed row on
     * first use, retrying once when a concurrent writer won the insert.
     */
    private function lockedSequence(int $businessId, DocumentType $type): DocumentNumberSequence
    {
        $sequence = DocumentNumberSequence::query()
            ->where('business_id', $businessId)
            ->where('document_type', $type->value)
            ->lockForUpdate()
            ->first();

        if ($sequence !== null) {
            return $sequence;
        }

        try {
            $created = new DocumentNumberSequence;
            $created->business_id = $businessId;
            $created->document_type = $type->value;
            $created->last_number = 0;
            $created->save();

            return $created;
        } catch (QueryException $e) {
            if (! str_starts_with((string) $e->getCode(), '23')) {
                throw $e;
            }

            $sequence = DocumentNumberSequence::query()
                ->where('business_id', $businessId)
                ->where('document_type', $type->value)
                ->lockForUpdate()
                ->first();

            if ($sequence === null) {
                throw $e;
            }

            return $sequence;
        }
    }

    /**
     * Render the machine number as {@code PREFIX-000001}, honouring per-business
     * settings overrides and falling back to config defaults. An invalid
     * override is a configuration error: refuse loudly instead of emitting a
     * malformed or ambiguous document number.
     */
    private function format(DocumentType $type, int $number): string
    {
        $prefix = $this->settings->get("numbering.{$type->value}_prefix")
            ?? config('numbering.prefixes.'.$type->value);

        $padding = $this->settings->get('numbering.padding')
            ?? (int) config('numbering.padding', 6);

        if (! is_string($prefix)
            || preg_match((string) config('numbering.prefix_pattern', '/^[A-Z0-9_-]+$/'), $prefix) !== 1
            || mb_strlen($prefix) > (int) config('numbering.max_prefix_length', 10)) {
            throw new RuntimeException('Invalid document number prefix configured for "'.$type->value.'".');
        }

        if (! is_int($padding) || $padding < 1 || $padding > (int) config('numbering.max_padding', 12)) {
            throw new RuntimeException('Invalid document number padding configured.');
        }

        return $prefix.'-'.str_pad((string) $number, $padding, '0', STR_PAD_LEFT);
    }
}
