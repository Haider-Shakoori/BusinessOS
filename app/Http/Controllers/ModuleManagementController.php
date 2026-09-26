<?php

namespace App\Http\Controllers;

use App\Models\BusinessModule;
use App\Services\BusinessContext;
use App\Services\BusinessNotificationService;
use App\Services\ModuleRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ModuleManagementController extends Controller
{
    public function index(
        ModuleRegistry $registry,
        BusinessContext $context,
    ): View {
        $businessId = $context->currentId();

        abort_unless($businessId, 404);

        $enabled = BusinessModule::query()
            ->where('business_id', $businessId)
            ->where('enabled', true)
            ->pluck('module_key')
            ->all();

        return view('system.modules', [
            'modules' => collect($registry->all()),
            'enabled' => $enabled,
            'coreModules' => ['dashboard', 'settings'],
        ]);
    }

    public function toggle(
        Request $request,
        ModuleRegistry $registry,
        BusinessContext $context,
        BusinessNotificationService $notifications,
    ): RedirectResponse {
        $businessId = $context->currentId();

        abort_unless($businessId, 404);

        $data = $request->validate([
            'module_key' => ['required', 'string', Rule::in(array_keys($registry->all()))],
            'enabled' => ['required', 'boolean'],
        ]);

        if (in_array($data['module_key'], ['dashboard', 'settings'], true) && ! $data['enabled']) {
            return back()->withErrors(['module_key' => __('system.modules.core_required')]);
        }

        BusinessModule::query()->updateOrCreate(
            ['business_id' => $businessId, 'module_key' => $data['module_key']],
            ['enabled' => (bool) $data['enabled']],
        );

        $definition = $registry->find($data['module_key']);
        $label = __($definition['label'] ?? 'modules.'.$data['module_key']);

        $notifications->create(
            __('system.notifications.module_title'),
            __('system.notifications.module_message', [
                'module' => $label,
                'state' => $data['enabled'] ? __('system.modules.enabled') : __('system.modules.disabled'),
            ]),
            route('system.modules.index'),
            null,
            'module',
            ['module_key' => $data['module_key'], 'enabled' => (bool) $data['enabled']],
        );

        return back()->with('status', __('system.modules.updated'));
    }
}
