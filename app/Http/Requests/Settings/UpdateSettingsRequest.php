<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * Whitelisted settings update (Batch 9).
 *
 * Only the explicitly listed keys may be updated: a business name, the three
 * general profile fields, the four regional fields, and the document numbering
 * overrides (Batch 13). Anything else sent by the client — including a forged
 * business_id or unknown admin keys — is never validated and never read by the
 * controller, which resolves the current business from BusinessContext instead.
 */
class UpdateSettingsRequest extends FormRequest
{
    /**
     * Prefix overrides for the five document types and the shared padding.
     * Prefixes are restricted to an unambigouous character set, and padding is
     * clamped to the range the formatting code accepts. Sending null (or
     * omitting keys, since they are `sometimes`) clears the override.
     */
    private function numberingRules(): array
    {
        return array_merge([
            'numbering.padding' => ['sometimes', 'nullable', 'integer', 'between:1,'.config('numbering.max_padding', 12)],
        ], collect(['quotation', 'invoice', 'payment', 'expense', 'purchase_order'])
            ->mapWithKeys(fn (string $type) => [
                "numbering.{$type}_prefix" => [
                    'sometimes', 'nullable', 'string',
                    'regex:'.config('numbering.prefix_pattern', '/^[A-Z0-9_-]+$/'),
                    'max:'.config('numbering.max_prefix_length', 10),
                ],
            ])->all());
    }

    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return array_merge([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'general.address' => ['nullable', 'string', 'max:500'],
            'general.phone' => ['nullable', 'string', 'max:30'],
            'general.email' => ['nullable', 'string', 'email:filter', 'max:255'],
            'general.tax_enabled' => ['sometimes', 'boolean'],
            'regional.timezone' => ['sometimes', 'required', 'string', 'timezone'],
            'regional.date_format' => ['sometimes', 'required', 'string', Rule::in(array_keys(config('settings.options.date_formats', [])))],
            'regional.time_format' => ['sometimes', 'required', 'string', Rule::in(array_keys(config('settings.options.time_formats', [])))],
            'regional.locale' => ['sometimes', 'required', 'string', Rule::in(array_keys(config('localization.supported', [])))],
            'regional.currency' => ['sometimes', 'required', 'string', 'size:3', 'alpha', Rule::exists('currencies', 'code')->where('is_active', true)],
            'attendance.enabled' => ['sometimes', 'boolean'],
            'attendance.payroll_source' => ['sometimes', 'required', 'string', Rule::in(['attendance'])],
            'attendance.auto_sync_minutes' => ['sometimes', 'required', 'integer', Rule::in(array_keys(config('settings.options.attendance_sync_intervals', [])))],
            'attendance.require_employee_mapping' => ['sometimes', 'boolean'],
            'document.invoice_theme' => ['sometimes', 'required', 'string', Rule::in(array_keys(config('document_themes.documents.invoice.themes', [])))],
            'document.accent_color' => ['sometimes', 'required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'document.header_text' => ['nullable', 'string', 'max:500'],
            'document.footer_text' => ['nullable', 'string', 'max:500'],
            'document.terms' => ['nullable', 'string', 'max:4000'],
            'document.bank_details' => ['nullable', 'string', 'max:2000'],
            'document.signature_line' => ['nullable', 'string', 'max:255'],
            'document.logo' => ['nullable', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'document.remove_logo' => ['sometimes', 'boolean'],
            'fieldpulse.enabled' => ['sometimes', 'boolean'],
            'fieldpulse.organization_key' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._-]+$/'],
        ], $this->numberingRules());
    }

    /**
     * Validation treats dotted keys as nested array paths, but this form posts
     * flat dotted names (regional.timezone). Remap flat dotted pairs into
     * nested arrays first so the whitelisted keys actually validate and land
     * in validated(). Unknown sent keys (e.g. forged business_id, admin keys)
     * are never nested under a validated group and stay absent from the result.
     */
    public function validationData(): array
    {
        $data = parent::validationData();
        $remapped = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && str_contains($key, '.')) {
                Arr::set($remapped, $key, $value);
            } else {
                $remapped[$key] = $value;
            }
        }

        return $remapped;
    }

    public function messages(): array
    {
        $messages = [
            'name.required' => __('settings.validation.name_required'),
            'name.max' => __('settings.validation.name_max'),
            'general.address.max' => __('settings.validation.address_max'),
            'general.phone.max' => __('settings.validation.phone_max'),
            'general.email.email' => __('settings.validation.email_invalid'),
            'general.email.max' => __('settings.validation.email_max'),
            'general.tax_enabled.boolean' => __('settings.validation.tax_enabled_invalid'),
            'regional.timezone.required' => __('settings.validation.timezone_required'),
            'regional.timezone.timezone' => __('settings.validation.timezone_invalid'),
            'regional.date_format.required' => __('settings.validation.date_format_required'),
            'regional.date_format.in' => __('settings.validation.date_format_invalid'),
            'regional.time_format.required' => __('settings.validation.time_format_required'),
            'regional.time_format.in' => __('settings.validation.time_format_invalid'),
            'regional.locale.required' => __('settings.validation.locale_required'),
            'regional.locale.in' => __('settings.validation.locale_invalid'),
            'regional.currency.required' => __('currencies.validation.invalid'),
            'regional.currency.size' => __('currencies.validation.invalid'),
            'regional.currency.alpha' => __('currencies.validation.invalid'),
            'regional.currency.exists' => __('currencies.validation.invalid'),
            'attendance.enabled.boolean' => __('attendance.validation.enabled'),
            'attendance.payroll_source.in' => __('attendance.validation.payroll_source'),
            'attendance.auto_sync_minutes.in' => __('attendance.validation.sync_interval'),
            'attendance.require_employee_mapping.boolean' => __('attendance.validation.mapping'),
            'document.invoice_theme.in' => __('settings.validation.document_theme_invalid'),
            'document.accent_color.regex' => __('settings.validation.document_accent_invalid'),
            'document.header_text.max' => __('settings.validation.document_header_max'),
            'document.footer_text.max' => __('settings.validation.document_footer_max'),
            'document.terms.max' => __('settings.validation.document_terms_max'),
            'document.bank_details.max' => __('settings.validation.document_bank_max'),
            'document.signature_line.max' => __('settings.validation.document_signature_max'),
            'document.logo.image' => __('settings.validation.document_logo_invalid'),
            'document.logo.mimes' => __('settings.validation.document_logo_invalid'),
            'document.logo.max' => __('settings.validation.document_logo_max'),
            'document.remove_logo.boolean' => __('settings.validation.document_remove_logo_invalid'),
            'fieldpulse.enabled.boolean' => __('FieldPulse integration switch is invalid.'),
            'fieldpulse.organization_key.max' => __('FieldPulse organization key is too long.'),
            'fieldpulse.organization_key.regex' => __('FieldPulse organization key may contain only letters, numbers, dots, underscores and dashes.'),
        ];

        foreach (['quotation', 'invoice', 'payment', 'expense', 'purchase_order'] as $type) {
            $messages["numbering.{$type}_prefix.regex"] = __('settings.validation.numbering_prefix_invalid');
            $messages["numbering.{$type}_prefix.max"] = __('settings.validation.numbering_prefix_max');
        }

        $messages['numbering.padding.integer'] = __('settings.validation.numbering_padding_invalid');
        $messages['numbering.padding.between'] = __('settings.validation.numbering_padding_invalid');

        return $messages;
    }
}
