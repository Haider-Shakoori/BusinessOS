<?php

namespace App\Services;

use App\Models\Business;
use App\Models\BusinessSubscription;
use App\Models\Product;
use App\Models\SaasPlan;

class SaasUsageService
{
    /**
     * @return array<string, int>
     */
    public function usage(Business $business): array
    {
        return [
            'members' => $business->memberships()->count(),
            'products' => Product::query()
                ->withoutGlobalScope('business')
                ->where('business_id', $business->id)
                ->count(),
            'enabled_modules' => $business->modules()
                ->where('enabled', true)
                ->count(),
        ];
    }

    /**
     * @return array<string, int|null>
     */
    public function limits(Business $business): array
    {
        $subscription = $business->subscription()
            ->with('plan')
            ->first();

        $limits = $subscription?->plan?->limits ?? [];

        return [
            'members' => $this->integerLimit($limits['members'] ?? null),
            'products' => $this->integerLimit($limits['products'] ?? null),
            'enabled_modules' => $this->integerLimit($limits['enabled_modules'] ?? null),
        ];
    }

    public function isOperational(Business $business): bool
    {
        $subscription = $business->subscription()->first();

        if (! $subscription) {
            return true;
        }

        if (! in_array($subscription->status, ['trialing', 'active'], true)) {
            return false;
        }

        return $subscription->status !== 'trialing'
            || $subscription->trial_ends_at === null
            || $subscription->trial_ends_at->isFuture();
    }

    public function canAdd(Business $business, string $resource, int $amount = 1): bool
    {
        $limits = $this->limits($business);
        $limit = $limits[$resource] ?? null;

        if ($limit === null) {
            return true;
        }

        $usage = $this->usage($business)[$resource] ?? 0;

        return $usage + max(1, $amount) <= $limit;
    }

    public function subscription(Business $business): ?BusinessSubscription
    {
        return $business->subscription()->with('plan')->first();
    }

    public function provisionDefaultSubscription(Business $business): ?BusinessSubscription
    {
        $plan = SaasPlan::query()
            ->where('is_active', true)
            ->where('is_default', true)
            ->orderBy('sort_order')
            ->first();

        if (! $plan) {
            return null;
        }

        return BusinessSubscription::query()->firstOrCreate(
            ['business_id' => $business->id],
            [
                'saas_plan_id' => $plan->id,
                'status' => 'active',
                'current_period_starts_at' => now(),
            ],
        );
    }

    private function integerLimit(mixed $value): ?int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $limit = (int) $value;

        return $limit < 0 ? null : $limit;
    }
}
