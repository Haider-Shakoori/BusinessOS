@extends('layouts.auth')
@section('title', __('auth.forgot_password'))
@section('description', __('auth.forgot_description'))
@section('form')
    <form method="POST" action="{{ route('password.email') }}" class="space-y-5" x-data="{ submitting: false }" x-on:submit="submitting = true">
        @csrf
        <x-ui.input name="email" type="email" :label="__('auth.email')" autocomplete="username" maxlength="255" required autofocus dir="ltr" />
        <x-ui.button type="submit" class="w-full" x-bind:disabled="submitting" x-bind:aria-busy="submitting">{{ __('auth.send_reset_link') }}</x-ui.button>
        <x-ui.button variant="ghost" :href="route('login')" class="w-full">{{ __('auth.back_to_login') }}</x-ui.button>
    </form>
@endsection
