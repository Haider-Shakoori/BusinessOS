@extends('layouts.auth')
@section('title', __('auth.reset_password'))
@section('description', __('auth.password_hint'))
@section('form')
    <form method="POST" action="{{ route('password.update') }}" class="space-y-5" x-data="{ submitting: false }" x-on:submit="submitting = true">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        @error('token') <x-ui.alert type="danger">{{ $message }}</x-ui.alert> @enderror
        <x-ui.input name="email" type="email" :label="__('auth.email')" :value="old('email', is_string($email) ? $email : '')" autocomplete="username" maxlength="255" required dir="ltr" />
        <x-ui.input name="password" type="password" :label="__('auth.password')" autocomplete="new-password" minlength="12" maxlength="72" required autofocus />
        <x-ui.input name="password_confirmation" type="password" :label="__('auth.confirm_password')" autocomplete="new-password" minlength="12" maxlength="72" required />
        <x-ui.button type="submit" class="w-full" x-bind:disabled="submitting" x-bind:aria-busy="submitting">{{ __('auth.reset_password') }}</x-ui.button>
        <x-ui.button variant="ghost" :href="route('password.request')" class="w-full">{{ __('auth.send_reset_link') }}</x-ui.button>
    </form>
@endsection
