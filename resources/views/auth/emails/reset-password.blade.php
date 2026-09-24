<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ App\Http\Middleware\SetLocale::direction(app()->getLocale()) }}">
<head><meta charset="utf-8"><title>{{ __('auth.reset_password') }}</title></head>
<body>
    <h1>{{ config('app.name') }}</h1>
    <p>{{ __('auth.mail_intro') }}</p>
    <p><a href="{{ $url }}">{{ __('auth.reset_password') }}</a></p>
    <p>{{ __('auth.mail_expiry', ['minutes' => $minutes]) }}</p>
    <p>{{ __('auth.mail_ignore') }}</p>
</body>
</html>
