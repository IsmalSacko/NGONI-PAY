{{-- Puce de couleur : succes (vert), warn (ambre), danger (rouge), accent (bleu doux), jaune. --}}
@props(['ton' => 'accent', 'icone' => null])
@php($couleurs = match ($ton) {
    'succes' => 'bg-succes-doux text-succes-fonce',
    'warn' => 'bg-warn-bg text-warn-fg',
    'danger' => 'bg-danger-bg text-danger-fg',
    'jaune' => 'bg-jaune-doux text-accent',
    default => 'bg-accent-soft text-accent',
})
<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-extrabold whitespace-nowrap $couleurs"]) }}>
    @if ($icone)<x-charte.icone :nom="$icone" :taille="15" />@endif{{ $slot }}
</span>
