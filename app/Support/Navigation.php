<?php

namespace App\Support;

use App\Services\BusinessContext;
use App\Services\BusinessSettings;
use App\Services\ModuleManager;
use App\Services\ModuleRegistry;
use Illuminate\Support\Facades\Gate;

/**
 * Application navigation data source.
 *
 * The shell consumes `label`, `icon`, `route`, and `href` per item — nothing
 * else. Batch 8 replaces the Batch 3 static placeholder with module-aware,
 * permission-filtered entries resolved from the code-backed module registry
 * and the current business's enabled-module state.
 *
 * The app-preview route retains the full legacy nav (no business context
 * required) so the local-only showcase remains unchanged.
 */
final class Navigation
{
    private const GROUP_ORDER = ['overview', 'business', 'insights', 'system'];

    /**
     * Navigation sections (label + items) for the current request.
     */
    public static function items(): array
    {
        $request = request();

        // Local-only app-preview: legacy full nav (no business context needed).
        if ($request->routeIs('app.preview')) {
            return self::legacyItems();
        }

        $context = app(BusinessContext::class);
        $current = $context->current();

        // No business selected (onboarding): minimal Home-only nav.
        if ($current === null) {
            return [[
                'label' => __('navigation.overview'),
                'items' => [self::item(__('auth.home'), 'user', route: 'app.home')],
            ]];
        }

        $manager = app(ModuleManager::class);
        $allModules = app(ModuleRegistry::class)->all();

        $grouped = [];

        foreach ($allModules as $module) {
            if (! isset($module['navigation'])) {
                continue;
            }

            if (! $manager->isEnabled($module['key'])) {
                continue;
            }

            $permission = $module['permission'] ?? null;

            if ($permission !== null && ! Gate::allows($permission)) {
                continue;
            }

            $nav = $module['navigation'];
            $group = $nav['group'];
            $order = $nav['order'] ?? 0;
            $children = $nav['children'] ?? null;

            if (is_array($children)) {
                $settings = app(BusinessSettings::class);

                // Batch 12: modules that own a real registry page (a route on
                // the nav config) AND child entries (products → products.index)
                // render the parent entry first so the registry page itself is
                // reachable from the sidebar, then render the children with
                // their own permission/setting gates. Modules without a route
                // (sales, expenses, reports) keep the children-only behaviour.
                $parentRoute = $nav['route'] ?? null;

                if ($parentRoute !== null) {
                    $grouped[$group][] = [
                        'order' => $order,
                        'item' => self::item(__($module['label']), $module['icon'], route: $parentRoute),
                    ];
                }

                foreach ($children as $child) {
                    $childPermission = $child['permission'] ?? null;

                    if ($childPermission !== null && ! Gate::allows($childPermission)) {
                        continue;
                    }

                    $childSetting = $child['setting'] ?? null;

                    if ($childSetting !== null && ! (bool) $settings->get($childSetting)) {
                        continue;
                    }

                    $childRoute = $child['route'] ?? null;

                    $grouped[$group][] = [
                        'order' => $order,
                        'item' => self::item(__($child['label'] ?? $module['label']), $child['icon'] ?? $module['icon'], route: $childRoute),
                    ];
                }

                continue;
            }

            $route = $nav['route'] ?? null;
            $href = isset($nav['route']) ? route($nav['route']) : '#';

            $grouped[$group][] = [
                'order' => $order,
                'item' => self::item(__($module['label']), $module['icon'], route: $route, href: $href),
            ];
        }

        $groupLabels = [
            'overview' => __('navigation.overview'),
            'business' => __('navigation.business'),
            'insights' => __('navigation.insights'),
            'system' => __('navigation.system'),
        ];

        $sections = [];

        foreach (self::GROUP_ORDER as $groupKey) {
            if (empty($grouped[$groupKey])) {
                continue;
            }

            usort($grouped[$groupKey], fn (array $a, array $b) => $a['order'] <=> $b['order']);

            $sections[] = [
                'label' => $groupLabels[$groupKey] ?? $groupKey,
                'items' => array_column($grouped[$groupKey], 'item'),
            ];
        }

        return $sections;
    }

    /**
     * The current route name, used for active-state matching.
     */
    public static function currentRoute(): ?string
    {
        return request()->route()?->getName();
    }

    /**
     * True when the item's route matches the current route name.
     */
    public static function isActive(array $item): bool
    {
        $route = $item['route'] ?? null;

        return $route !== null && $route === self::currentRoute();
    }

    /**
     * Build a single navigation entry.
     */
    private static function item(string $label, string $icon, ?string $route = null, string $href = '#'): array
    {
        return [
            'label' => $label,
            'icon' => $icon,
            'route' => $route,
            'href' => $route !== null ? route($route) : $href,
        ];
    }

    /**
     * Legacy full navigation for the local-only app-preview route.
     *
     * @return list<array{label: string, items: list<array<string, mixed>>}>
     */
    private static function legacyItems(): array
    {
        return [
            [
                'label' => __('navigation.overview'),
                'items' => [
                    self::item(__('navigation.dashboard'), 'chart-bar', route: 'app.preview'),
                ],
            ],
            [
                'label' => __('navigation.business'),
                'items' => [
                    self::item(__('navigation.customers'), 'users'),
                    self::item(__('navigation.sales'), 'document-text'),
                    self::item(__('navigation.products'), 'banknotes'),
                    self::item(__('navigation.expenses'), 'arrow-trending-down'),
                ],
            ],
            [
                'label' => __('navigation.insights'),
                'items' => [
                    self::item(__('navigation.reports'), 'inbox'),
                ],
            ],
            [
                'label' => __('navigation.system'),
                'items' => [
                    self::item(__('navigation.settings'), 'cog'),
                ],
            ],
        ];
    }
}
