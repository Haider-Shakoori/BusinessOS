<?php

namespace App\Traits;

use App\Services\BusinessContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Foundation for every tenant-owned (business-owned) model.
 *
 * Use on any model whose table carries a `business_id` column.
 *
 * Scoping convention (deliberate and documented):
 * - A global `business` scope filters every query to the CURRENT business of
 *   the authenticated user, resolved from the authoritative BusinessContext.
 * - When no current business exists (console commands, jobs, unit tests, or
 *   unauthenticated code) the scope is a NO-OP. This is intentional: it must
 *   never silently hide every record in the table. Such contexts are expected
 *   to scope explicitly with ->whereBelongsTo() style joins when needed.
 * - business_id is auto-filled on create from the trusted current context only
 *   when it is still null, so future modules never accept a business_id from
 *   the request. Trusted internal paths may still set it explicitly.
 *
 * Security rule: business_id must come from the current authenticated context,
 * never from user-controlled request input. Do NOT add business_id to a
 * model's $fillable.
 */
trait BelongsToBusiness
{
    public static function bootBelongsToBusiness(): void
    {
        static::addGlobalScope('business', function (Builder $query) {
            $currentId = app(BusinessContext::class)->currentId();

            if ($currentId === null) {
                // No trusted current business: never hide the whole table.
                return;
            }

            $query->where($query->getModel()->qualifyColumn('business_id'), $currentId);
        });

        static::creating(function (Model $model) {
            if ($model->business_id === null) {
                $currentId = app(BusinessContext::class)->currentId();

                if ($currentId !== null) {
                    $model->business_id = $currentId;
                }
            }
        });
    }
}
