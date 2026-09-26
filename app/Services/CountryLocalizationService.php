<?php

namespace App\Services;

final class CountryLocalizationService
{
    /**
     * @return array<string, mixed>
     */
    public function profiles(): array
    {
        return config('countries.profiles', []);
    }

    /**
     * @return array<string, mixed>
     */
    public function profile(?string $country): array
    {
        $profiles = $this->profiles();
        $code = strtoupper((string) ($country ?: config('countries.default', 'AF')));

        return $profiles[$code]
            ?? $profiles[config('countries.default', 'AF')]
            ?? [];
    }

    /**
     * @return array<string, string>
     */
    public function countryOptions(): array
    {
        return collect($this->profiles())
            ->mapWithKeys(fn (array $profile, string $code): array => [
                $code => (string) ($profile['name'] ?? $code),
            ])
            ->all();
    }

    /**
     * Values safe to apply as workspace defaults.
     *
     * @return array<string, mixed>
     */
    public function workspaceDefaults(?string $country): array
    {
        $profile = $this->profile($country);

        return [
            'country' => strtoupper((string) ($country ?: config('countries.default', 'AF'))),
            'currency' => $profile['currency'] ?? 'USD',
            'timezone' => $profile['timezone'] ?? 'UTC',
            'locale' => $profile['locale'] ?? 'en',
            'tax_enabled' => (bool) ($profile['tax_enabled'] ?? false),
            'date_format' => $profile['date_format'] ?? 'Y-m-d',
            'time_format' => $profile['time_format'] ?? 'H:i',
        ];
    }
}
