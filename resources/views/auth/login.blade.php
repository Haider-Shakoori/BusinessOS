@extends('layouts.auth')
@section('title', __('auth.sign_in'))
@section('description', __('auth.login_description'))
@section('form')
    <form method="POST" action="{{ route('login') }}" class="space-y-5" x-data="{ submitting: false }" x-on:submit="submitting = true">
        @csrf
        <x-ui.input name="email" type="email" :label="__('auth.email')" autocomplete="username" maxlength="255" required autofocus dir="ltr" />
        <x-ui.input name="password" type="password" :label="__('auth.password')" autocomplete="current-password" required />
        <x-ui.checkbox name="remember" :label="__('auth.remember_me')" />
        <x-ui.button type="submit" class="w-full" x-bind:disabled="submitting" x-bind:aria-busy="submitting">{{ __('auth.sign_in') }}</x-ui.button>
        <x-ui.button variant="ghost" :href="route('password.request')" class="w-full">{{ __('auth.forgot_password') }}</x-ui.button>
    </form>
@endsection
