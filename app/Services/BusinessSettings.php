<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Setting;
use Illuminate\Support\Collection;

/**
 * The single authoritative, current-business-aware settings service.
 *
 * Reads and writes are scoped to the business resolved by BusinessContext —
 * never to a request-supplied business_id. Only keys defined in
 * config('settings.definitions') are readable as first-class settings or
 * persistable; unknown keys are dropped on write and resolve to the caller
 * supplied default on read.
 *
 * Defaults come from config; the database holds sparse overrides only. The
 * override set is memoized for the life of the scoped instance (reset by
 * ForgetScopedInstances between requests), so no persistent cache and no Redis
 * are involved.
 */
class BusinessSettings
{
    private bool $businessResolved = false;

    private ?Business $business = null;

    private bool $overridesResolved = false;

    private Collection $overrides;

    public function __construct(private readonly BusinessContext $context)
    {
        $this->overrides = collect();
    }

    /**
     * The resolved current business, or null when none exists.
     */
    public function business(): ?Business
    {
        if (! $this->businessResolved) {
            $this->business = $this->context->current();
            $this->businessResolved = true;
        }

        return $this->business;
    }

    /**
     * All supported settings for the current business (config defaults merged
     * with sparse database overrides), keyed by dot notation group.key.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $result = [];

        foreach ($this->definitions() as $group => $fields) {
            foreach (array_keys($fields) as $key) {
                $dot = $group.'.'.$key;
                $result[$dot] = $this->get($dot);
            }
        }

        return $result;
    }

    /**
     * Read a setting for the current business.
     *
     * Resolution order: database override, config default, caller default.
     * Unknown keys are never persisted and simply resolve to $default.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $definition = $this->definition($key);

        if ($this->overrides()->has($key)) {
            $override = $this->overrides()->get($key);

            return $this->castValue($override['value'], $override['type'] ?? 'string');
        }

        if ($definition !== null) {
            return $this->castValue($definition['default'], $definition['type']);
        }

        return $default;
    }

    /**
     * Whether the current business has an explicit database override for the
     * supported key.
     */
    public function has(string $key): bool
    {
        if ($this->definition($key) === null) {
            return false;
        }

        return $this->overrides()->has($key);
    }

    /**
     * All settings within a group for the current business, keyed by their
     * plain key (e.g. group('regional') => ['timezone' => 'UTC', ...]).
     *
     * @return array<string, mixed>
     */
    public function group(string $group): array
    {
        $result = [];

        foreach (array_keys($this->definitions()[$group] ?? []) as $key) {
            $result[$key] = $this->get($group.'.'.$key);
        }

        return $result;
    }

    /**
     * Persist a single setting override for the current business.
     *
     * Unknown keys are ignored (never silently persisted). With no current
     * business this is a no-op — settings always belong to the current
     * business.
     */
    public function set(string $key, mixed $value): void
    {
        $this->updateMany([$key => $value]);
    }

    /**
     * Persist multiple setting overrides for the current business.
     *
     * Only keys defined in config('settings.definitions') are written; every
     * other key in the payload is dropped. The business is resolved from
     * BusinessContext, never from the request.
     *
     * @param  array<string, mixed>  $values  dot-keyed group.key => value
     */
    public function updateMany(array $values): void
    {
        $business = $this->business();

        if ($business === null) {
            return;
        }

        foreach ($values as $key => $value) {
            $definition = $this->definition($key);

            if ($definition === null) {
                continue;
            }

            [$group, $name] = $this->splitKey($key);
            $type = $definition['type'] ?? 'string';

            Setting::updateOrCreate(
                ['business_id' => $business->id, 'group' => $group, 'key' => $name],
                ['value' => $this->storeValue($value, $type), 'type' => $type],
            );
        }

        // Values changed: the memoized override set is stale.
        $this->overridesResolved = false;
    }

    /**
     * The database overrides for the current business, keyed by dot notation.
     *
     * @return Collection<string, array{value: mixed, type: string}>
     */
    protected function overrides(): Collection
    {
        if ($this->overridesResolved) {
            return $this->overrides;
        }

        $this->overrides = collect();

        $business = $this->business();

        if ($business !== null) {
            $this->overrides = Setting::where('business_id', $business->id)
                ->get()
                ->mapWithKeys(function (Setting $setting): array {
                    return [
                        $setting->group.'.'.$setting->key => [
                            'value' => $setting->value,
                            'type' => $setting->type,
                        ],
                    ];
                });
        }

        $this->overridesResolved = true;

        return $this->overrides;
    }

    /**
     * The definition for a dot-keyed setting, or null when unsupported.
     *
     * @return array{default: mixed, type: string}|null
     */
    protected function definition(string $key): ?array
    {
        return config('settings.definitions.'.$key);
    }

    /**
     * The full set of supported definitions, grouped by group name.
     *
     * @return array<string, array<string, array{default: mixed, type: string}>>
     */
    protected function definitions(): array
    {
        return config('settings.definitions', []);
    }

    /**
     * Split a dot-keyed setting into [group, key].
     *
     * @return array{0: string, 1: string}
     */
    protected function splitKey(string $key): array
    {
        $parts = explode('.', $key, 2);

        return [$parts[0], $parts[1] ?? ''];
    }

    /**
     * Normalize a value for storage by its declared type.
     */
    protected function storeValue(mixed $value, string $type): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'boolean' => $value && ! in_array($value, ['false', '0', 0, false], true) ? '1' : '0',
            'integer' => (string) (int) $value,
            'json' => json_encode($value),
            default => (string) $value,
        };
    }

    /**
     * Restore a stored value to its declared type.
     */
    protected function castValue(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'boolean' => in_array($value, [true, 1, '1', 'true'], true),
            'integer' => (int) $value,
            'json' => json_decode((string) $value, true),
            default => (string) $value,
        };
    }
}
