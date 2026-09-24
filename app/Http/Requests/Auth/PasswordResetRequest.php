<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class PasswordResetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'max:72', 'confirmed', function ($attribute, $value, $fail) {
                if (is_string($value) && strlen($value) > 72) {
                    $fail(__('auth.password_bytes'));
                }
            }],
        ];
    }

    protected function getRedirectUrl(): string
    {
        return route('password.reset', [
            'token' => is_string($this->input('token')) && $this->input('token') !== '' ? $this->input('token') : 'invalid',
            'email' => is_string($this->input('email')) ? $this->input('email') : '',
        ]);
    }
}
