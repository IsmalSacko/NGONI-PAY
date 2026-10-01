{{-- Pictogramme dans une pastille douce : l'ancre visuelle d'une boutique, d'un plan… --}}
@props(['nom', 'ton' => 'info', 'taille' => 'w-10 h-10'])
@php
    $tons = [
        'info' => 'bg-accent-soft text-accent',
        'jaune' => 'bg-jaune-doux text-warn-fg',
        'jaune-fort' => 'bg-jaune text-accent',
        'nuit' => 'bg-accent text-white',
        'succes' => 'bg-succes-doux text-succes',
        'danger' => 'bg-danger-bg text-danger-fg',
        'neutre' => 'bg-puce text-muted',
    ];
@endphp
<span aria-hidden="true" {{ $attributes->merge(['class' => "$taille shrink-0 rounded-xl inline-flex items-center justify-center ".($tons[$ton] ?? $tons['info'])]) }}>
    <x-plateforme.picto :nom="$nom" class="w-[45%] h-[45%] min-w-4 min-h-4" />
</span>
