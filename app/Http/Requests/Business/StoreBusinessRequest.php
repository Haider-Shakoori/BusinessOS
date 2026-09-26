<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBusinessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'industry' => ['nullable', 'string', Rule::in(array_keys(config('onboarding.industries', [])))],
            'country' => ['required', 'string', Rule::in(array_keys(config('onboarding.countries', [])))],
            'currency' => ['required', 'string', 'size:3', Rule::exists('currencies', 'code')->where('is_active', true)],
            'timezone' => ['required', 'string', Rule::in(array_keys(config('onboarding.timezones', [])))],
            'address' => ['nullable', 'string', 'max:1000'],
            'phone' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'logo' => ['nullable', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'locale' => ['required', 'string', Rule::in(array_keys(config('localization.supported', [])))],
            'appearance' => ['required', 'string', Rule::in(['light', 'dark', 'system'])],
            'tax_enabled' => ['nullable', 'boolean'],
            'modules' => ['nullable', 'array'],
            'modules.*' => ['string', Rule::in(array_keys(config('onboarding.modules', [])))],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => __('business.validation.name_required'),
            'name.max' => __('business.validation.name_max'),
            'country.required' => __('onboarding.validation.country_required'),
            'currency.required' => __('onboarding.validation.currency_required'),
            'currency.exists' => __('onboarding.validation.currency_invalid'),
            'timezone.required' => __('onboarding.validation.timezone_required'),
            'locale.required' => __('onboarding.validation.locale_required'),
            'logo.image' => __('onboarding.validation.logo_invalid'),
            'logo.mimes' => __('onboarding.validation.logo_invalid'),
            'logo.max' => __('onboarding.validation.logo_max'),
        ];
    }
}
