<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->all(), ['email' => ['required', 'string', 'email', 'max:255']]);
        if ($validator->fails()) {
            return redirect()->route('password.request')->withErrors($validator)->withInput($request->only('email'));
        }

        Password::sendResetLink($request->only('email'));

        // Do not disclose whether the address exists or was broker-throttled.
        return redirect()->route('password.request')->with('status', __('auth.reset_link_sent'));
    }
}
