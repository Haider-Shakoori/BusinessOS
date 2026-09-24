@props([
    'status' => null,
    'label' => null,
    'dot' => true,
])

@php
    $tones = [
        'paid' => 'success',
        'completed' => 'success',
        'active' => 'success',
        'positive' => 'success',
        'accepted' => 'success',
        'converted' => 'success',

        'pending' => 'warning',
        'processing' => 'warning',
        'partially_paid' => 'warning',
        'refunded' => 'warning',
        'expired' => 'warning',

        'overdue' => 'danger',
        'cancelled' => 'danger',
        'failed' => 'danger',
        'rejected' => 'danger',
        'reversed' => 'danger',

        'sent' => 'info',
        'issued' => 'info',

        'draft' => 'neutral',
        'inactive' => 'neutral',
        'archived' => 'neutral',
        'void' => 'neutral',
    ];

    $tone = $tones[$status] ?? 'neutral';
    $humanLabel = $label ?? (str($status)->replace('_', ' ')->ucfirst()->toString() ?: 'Unknown');
@endphp

<x-ui.badge :tone="$tone" :dot="$dot">{{ $humanLabel }}</x-ui.badge>