<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\BusinessSubscription;
use App\Models\SaasPlan;
use App\Services\SaasUsageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SaasAdminController extends Controller
{
    public function index(SaasUsageService $usage): View
    {
        $businesses = Business::query()
            ->with(['subscription.plan'])
            ->withCount('memberships')
            ->orderBy('name')
            ->get();

        return view('platform.saas', [
            'plans' => SaasPlan::query()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'businesses' => $businesses,
            'usage' => $businesses->mapWithKeys(
                fn (Business $business): array => [
                    $business->id => $usage->usage($business),
                ],
            ),
            'statuses' => BusinessSubscription::STATUSES,
        ]);
    }

    public function storePlan(Request $request): RedirectResponse
    {
        $data = $this->validatePlan($request);

        DB::transaction(function () use ($data): void {
            if ($data['is_default']) {
                SaasPlan::query()->update(['is_default' => false]);
            }

            SaasPlan::query()->create($this->planAttributes($data));
        });

        return back()->with('status', __('saas.plan_created'));
    }

    public function updatePlan(Request $request, SaasPlan $saasPlan): RedirectResponse
    {
        $data = $this->validatePlan($request, $saasPlan);

        DB::transaction(function () use ($data, $saasPlan): void {
            if ($data['is_default']) {
                SaasPlan::query()
                    ->whereKeyNot($saasPlan->id)
                    ->update(['is_default' => false]);
            }

            $saasPlan->update($this->planAttributes($data));
        });

        return back()->with('status', __('saas.plan_updated'));
    }

    public function updateSubscription(
        Request $request,
        Business $business,
    ): RedirectResponse {
        $data = $request->validate([
            'saas_plan_id' => ['nullable', 'integer', Rule::exists('saas_plans', 'id')],
            'status' => ['required', Rule::in(BusinessSubscription::STATUSES)],
            'trial_ends_at' => ['nullable', 'date'],
            'current_period_ends_at' => ['nullable', 'date'],
        ]);

        $subscription = BusinessSubscription::query()->updateOrCreate(
            ['business_id' => $business->id],
            [
                'saas_plan_id' => $data['saas_plan_id'] ?? null,
                'status' => $data['status'],
                'trial_ends_at' => $data['trial_ends_at'] ?? null,
                'current_period_starts_at' => now(),
                'current_period_ends_at' => $data['current_period_ends_at'] ?? null,
                'canceled_at' => $data['status'] === 'canceled' ? now() : null,
            ],
        );

        if ($subscription->status !== 'canceled' && $subscription->canceled_at !== null) {
            $subscription->forceFill(['canceled_at' => null])->save();
        }

        return back()->with('status', __('saas.subscription_updated', [
            'business' => $business->name,
        ]));
    }

    private function validatePlan(Request $request, ?SaasPlan $plan = null): array
    {
        return $request->validate([
            'key' => [
                'required',
                'string',
                'max:80',
                'regex:/^[a-z0-9][a-z0-9_-]*$/',
                Rule::unique('saas_plans', 'key')->ignore($plan?->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:4000'],
            'monthly_price' => ['nullable', 'numeric', 'min:0'],
            'annual_price' => ['nullable', 'numeric', 'min:0'],
            'currency_code' => ['required', 'string', 'size:3'],
            'limit_members' => ['nullable', 'integer', 'min:1'],
            'limit_products' => ['nullable', 'integer', 'min:1'],
            'limit_enabled_modules' => ['nullable', 'integer', 'min:1'],
            'features' => ['nullable', 'string', 'max:8000'],
            'is_active' => ['required', 'boolean'],
            'is_default' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:10000'],
        ]);
    }

    private function planAttributes(array $data): array
    {
        return [
            'key' => strtolower($data['key']),
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'monthly_price' => $data['monthly_price'] ?? null,
            'annual_price' => $data['annual_price'] ?? null,
            'currency_code' => strtoupper($data['currency_code']),
            'limits' => array_filter([
                'members' => $data['limit_members'] ?? null,
                'products' => $data['limit_products'] ?? null,
                'enabled_modules' => $data['limit_enabled_modules'] ?? null,
            ], fn (mixed $value): bool => $value !== null),
            'features' => collect(preg_split('/\r\n|\r|\n/', (string) ($data['features'] ?? '')))
                ->map(fn (string $feature): string => trim($feature))
                ->filter()
                ->values()
                ->all(),
            'is_active' => (bool) $data['is_active'],
            'is_default' => (bool) $data['is_default'],
            'sort_order' => (int) $data['sort_order'],
        ];
    }
}
