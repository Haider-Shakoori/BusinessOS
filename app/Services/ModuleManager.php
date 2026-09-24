<?php

namespace App\Services;

use App\Models\BusinessModule;

/**
 * Current-business-aware module availability service.
 *
 * Answers "is module X available right now?" using:
 *   1. Is the module registered in the code-backed registry?
 *   2. Is the module enabled for the current business (business_modules row)?
 *
 * Module availability is NOT authorization — the caller is responsible for
 * combining module checks with permission checks (middleware or Gate).
 */
class ModuleManager
{
    /** @var array<string, bool> Per-request memoization cache */
    private array $enabledCache = [];

    public function __construct(
        private readonly BusinessContext $context,
        private readonly ModuleRegistry $registry,
    ) {}

    /**
     * Whether the module is registered AND enabled for the current business.
     */
    public function isEnabled(string $key): bool
    {
        if (array_key_exists($key, $this->enabledCache)) {
            return $this->enabledCache[$key];
        }

        $business = $this->context->current();

        if ($business === null || ! $this->registry->exists($key)) {
            return $this->enabledCache[$key] = false;
        }

        $this->enabledCache[$key] = BusinessModule::where('business_id', $business->id)
            ->where('module_key', $key)
            ->where('enabled', true)
            ->exists();

        return $this->enabledCache[$key];
    }

    /**
     * The module_keys enabled for the current business.
     *
     * @return list<string>
     */
    public function enabledModules(): array
    {
        $business = $this->context->current();

        if ($business === null) {
            return [];
        }

        return BusinessModule::where('business_id', $business->id)
            ->where('enabled', true)
            ->pluck('module_key')
            ->all();
    }

    /**
     * Whether the given key is a registered module (regardless of business state).
     */
    public function isRegistered(string $key): bool
    {
        return $this->registry->exists($key);
    }
}
