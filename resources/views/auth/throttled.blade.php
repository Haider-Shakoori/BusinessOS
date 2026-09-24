@extends('layouts.auth')
@section('title', __('auth.try_later'))
@section('description', __('auth.throttle', ['seconds' => $seconds]))
@section('form')
    <x-ui.button :href="route('login')">{{ __('auth.back_to_login') }}</x-ui.button>
@endsection
