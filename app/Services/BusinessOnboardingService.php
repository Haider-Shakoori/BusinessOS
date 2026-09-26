<?php

namespace App\Services;

use App\Http\Requests\Business\StoreBusinessRequest;
use App\Models\Business;
use App\Models\BusinessModule;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class BusinessOnboardingService
{
    public function __construct(
        private readonly BusinessContext $context,
        private readonly BusinessSettings $settings,
    ) {
        //
    }

    public function create(StoreBusinessRequest $request, User $user): Business
    {
        $validated = $request->validated();

        $business = DB::transaction(function () use ($validated, $user): Business {
            $business = Business::create(['name' => $validated['name']]);
            $membership = $user->memberships()->create(['business_id' => $business->id]);

            $roles = $business->provisionDefaultRoles();
            $ownerRole = $roles[config('roles.owner_role', 'owner')] ?? null;

            if ($ownerRole !== null) {
                $membership->assignRole($ownerRole);
            }

            $business->provisionDefaultModules();

            $selectedModules = array_values(array_unique(array_merge(
                ['dashboard', 'settings'],
                $validated['modules'] ?? [],
            )));

            foreach ($selectedModules as $moduleKey) {
                BusinessModule::updateOrCreate(
                    ['business_id' => $business->id, 'module_key' => $moduleKey],
                    ['enabled' => true],
                );
            }

            return $business;
        });

        $this->context->switchTo($business->id);
        $this->settings->resetResolvedContext();

        $defaults = config('onboarding.defaults', []);

        $this->settings->updateMany([
            'general.address' => $validated['address'] ?? null,
            'general.phone' => $validated['phone'] ?? null,
            'general.email' => $validated['email'] ?? null,
            'general.industry' => $validated['industry'] ?? null,
            'general.country' => $validated['country'] ?? ($defaults['country'] ?? null),
            'general.tax_enabled' => (bool) ($validated['tax_enabled'] ?? false),
            'regional.timezone' => $validated['timezone'] ?? ($defaults['timezone'] ?? 'UTC'),
            'regional.locale' => $validated['locale'] ?? ($defaults['locale'] ?? null),
            'regional.currency' => strtoupper((string) ($validated['currency'] ?? ($defaults['currency'] ?? 'AFN'))),
            'ui.appearance' => $validated['appearance'] ?? ($defaults['appearance'] ?? 'light'),
        ]);

        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store('business-documents/'.$business->id, 'public');
            $this->settings->set('document.logo_path', $path);
        }

        return $business;
    }
}
