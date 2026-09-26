@props(['subscription'])

{{-- État lisible d'un abonnement : « expiré le … » dès que la date de fin est
     passée, « jusqu'au … » seulement tant qu'il donne encore accès. --}}
@php
    $endsAt = $subscription?->ends_at
        ? \Illuminate\Support\Carbon::parse($subscription->ends_at)->format('d/m/Y')
        : null;
@endphp

@if (! $subscription)
    <span class="text-xs text-slate-400">aucun abonnement</span>
@elseif (! $subscription->is_active)
    <span class="inline-flex items-center rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-medium text-red-700">désactivé</span>
@elseif (! $subscription->isCurrentlyActive())
    <span class="inline-flex items-center rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-medium text-red-700">expiré le {{ $endsAt }}</span>
@elseif ($endsAt)
    <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">actif jusqu'au {{ $endsAt }}</span>
@else
    <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">actif à vie</span>
@endif
