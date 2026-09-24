<?php

namespace App\Services;

/**
 * The single authoritative source for registered application modules.
 *
 * Module definitions live in config/modules.php (code-backed, stable keys).
 * Business-specific enablement lives in the business_modules table (Batch 8).
 * This class provides read-only access to the registry.
 */
class ModuleRegistry
{
    private array $modules;

    public function __construct()
    {
        $this->modules = config('modules.registry', []);
    }

    /**
     * All registered modules keyed by their stable key.
     *
     * @return array<string, array{key: string, label: string, icon: string, navigation?: array, permission?: string}>
     */
    public function all(): array
    {
        return $this->modules;
    }

    /**
     * Retrieve a single module definition, or null if unregistered.
     */
    public function find(string $key): ?array
    {
        return $this->modules[$key] ?? null;
    }

    /**
     * Whether the given key is a registered module.
     */
    public function exists(string $key): bool
    {
        return isset($this->modules[$key]);
    }
}
