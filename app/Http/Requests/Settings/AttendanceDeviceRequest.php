<?php

namespace App\Http\Requests\Settings;

use App\Services\BusinessContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AttendanceDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'attendance_bridge_id' => ['nullable', 'integer', Rule::exists('attendance_bridges', 'id')->where('business_id', app(BusinessContext::class)->currentId())],
            'name' => ['required', 'string', 'max:255'],
            'brand' => ['required', 'string', Rule::in(array_keys(config('attendance.brands', [])))],
            'model' => ['nullable', 'string', 'max:255'],
            'connection_type' => ['required', 'string', Rule::in(array_keys(config('attendance.connections', [])))],
            'host' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'base_url' => ['nullable', 'url:http,https', 'max:1000'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:1000'],
            'api_key' => ['nullable', 'string', 'max:2000'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'timezone' => ['nullable', 'string', 'timezone'],
            'tls_verify' => ['sometimes', 'boolean'],
            'timeout_seconds' => ['nullable', 'integer', 'between:1,60'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $brand = $this->input('brand');
                $connection = $this->input('connection_type');
                $brandDefinition = config('attendance.brands.'.$brand, []);
                $allowed = $brandDefinition['connections'] ?? [];

                if ($brand && $connection && ! in_array($connection, $allowed, true)) {
                    $validator->errors()->add('connection_type', __('attendance.validation.connection_brand'));
                }

                $definition = config('attendance.connections.'.$connection, []);

                foreach ($definition['requires'] ?? [] as $field) {
                    $value = $this->input($field);

                    if ($value === null || trim((string) $value) === '') {
                        $validator->errors()->add($field, __('attendance.validation.required_for_connection', [
                            'field' => __('attendance.fields.'.$field),
                        ]));
                    }
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'enabled' => $this->boolean('enabled'),
            'tls_verify' => $this->boolean('tls_verify', true),
        ]);
    }
}
