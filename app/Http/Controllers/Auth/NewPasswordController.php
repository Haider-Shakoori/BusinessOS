<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\PasswordResetRequest;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class NewPasswordController extends Controller
{
    public function create(Request $request, string $token): Response
    {
        return response()->view('auth.reset-password', ['token' => $token, 'email' => $request->query('email', '')])
            ->header('Referrer-Policy', 'no-referrer')->header('Cache-Control', 'no-store');
    }

    public function store(PasswordResetRequest $request): RedirectResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            return redirect()->route('password.reset', $request->only('token', 'email'))
                ->withErrors(['email' => __('passwords.token')])->withInput($request->only('email'));
        }

        $locale = app()->getLocale();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put(config('localization.session_key'), $locale);

        return redirect()->route('login')->with('status', __($status));
    }
}
