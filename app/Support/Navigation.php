<?php

namespace App\Support;

use App\Models\BusinessNotification;
use App\Services\BusinessContext;
use App\Services\ModuleManager;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Screenshot-authoritative application navigation.
 *
 * The approved BusinessOS product images define one stable enterprise menu
 * order. This class resolves that presentation blueprint against the current
 * business, enabled modules, permissions, and implemented routes without
 * weakening route middleware or tenant isolation.
 */
final class Navigation
{
    /**
     * Navigation sections for the current request.
     *
     * @return list<array{label: string, items: list<array<string, mixed>>}>
     */
    public static function items(): array
    {
        $context = app(BusinessContext::class);
        $current = $context->current();
        $manager = app(ModuleManager::class);

        $items = [];

        foreach (config('navigation.items', []) as $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $module = $definition['module'] ?? null;
            $permission = $definition['permission'] ?? null;
            $route = $definition['route'] ?? null;
            $placeholder = $definition['placeholder'] ?? null;

            $moduleAvailable = $module === null
                || ($current !== null && $manager->isEnabled($module));

            $permissionAllowed = $permission === null || Gate::allows($permission);
            $routeAvailable = is_string($route) && Route::has($route);

            $enabled = $current !== null
                && $moduleAvailable
                && $permissionAllowed
                && ($routeAvailable || is_string($placeholder));

            $href = '#';

            if ($enabled && $routeAvailable) {
                $href = route($route);
            } elseif ($current !== null && is_string($placeholder) && Route::has('workspace.placeholder')) {
                $href = route('workspace.placeholder', ['section' => $placeholder]);
                $enabled = true;
            }

            $key = (string) ($definition['key'] ?? '');
            $badge = $definition['badge'] ?? null;

            if (
                $key === 'notifications'
                && $enabled
                && auth()->check()
            ) {
                $unread = BusinessNotification::query()
                    ->where('user_id', auth()->id())
                    ->whereNull('read_at')
                    ->count();

                $badge = $unread > 0 ? min($unread, 99) : null;
            }

            $items[] = [
                'key' => $key,
                'label' => __((string) ($definition['label'] ?? '')),
                'icon' => (string) ($definition['icon'] ?? 'cube'),
                'route' => $routeAvailable ? $route : null,
                'href' => $href,
                'depth' => (int) ($definition['depth'] ?? 0),
                'expandable' => (bool) ($definition['expandable'] ?? false),
                'active_prefixes' => $definition['active_prefixes'] ?? [],
                'placeholder' => $placeholder,
                'disabled' => ! $enabled,
                'badge' => $badge,
            ];
        }

        return [[
            'label' => __('navigation.workspace'),
            'items' => $items,
        ]];
    }

    /**
     * Current named route.
     */
    public static function currentRoute(): ?string
    {
        return request()->route()?->getName();
    }

    /**
     * Active state supports exact routes, route-prefix parent rows, and future
     * placeholder sections.
     */
    public static function isActive(array $item): bool
    {
        $currentRoute = self::currentRoute();
        $route = $item['route'] ?? null;

        if ($route !== null && $route === $currentRoute) {
            return true;
        }

        foreach ($item['active_prefixes'] ?? [] as $prefix) {
            if ($currentRoute !== null && Str::startsWith($currentRoute, (string) $prefix)) {
                return true;
            }
        }

        if ($currentRoute === 'workspace.placeholder' && isset($item['placeholder'])) {
            return request()->route('section') === $item['placeholder'];
        }

        return false;
    }
}
