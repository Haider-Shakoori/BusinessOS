<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

class StoreBusinessRequest extends FormRequest
{
    /**
     * Only authenticated users may create businesses.
     */
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * Business creation accepts only the business name. The authenticated user
     * is attached as a member server-side by the controller — never from the
     * request (no user_id field is accepted).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * Localized validation messages for the onboarding form.
     */
    public function messages(): array
    {
        return [
            'name.required' => __('business.validation.name_required'),
            'name.max' => __('business.validation.name_max'),
        ];
    }
}
