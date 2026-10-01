{{-- Statut en pastille douce, précédée d'un point de la même couleur. --}}
@props(['ton' => 'neutre'])
@php
    $tons = [
        'succes' => 'bg-succes-doux text-succes',
        'danger' => 'bg-danger-bg text-danger-fg',
        'essai' => 'bg-jaune-doux text-warn-fg',
        'attente' => 'bg-warn-bg text-warn-fg',
        'info' => 'bg-accent-soft text-accent',
        'neutre' => 'bg-puce text-muted',
    ];
@endphp
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-bold whitespace-nowrap '.($tons[$ton] ?? $tons['neutre'])]) }}>
    <span class="w-1.5 h-1.5 rounded-full bg-current shrink-0" aria-hidden="true"></span>{{ $slot }}
</span>
