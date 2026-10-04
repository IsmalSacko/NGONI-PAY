{{-- Pastille d'icône : fond doux, icône franche ; jaune quand elle est active, rouge en danger. Comme PastilleIcone de l'application. --}}
@props(['icone', 'actif' => false, 'danger' => false, 'taille' => 42])
@php($couleurs = $danger ? 'bg-danger-bg text-danger-fg' : ($actif ? 'bg-jaune text-accent shadow-[0_6px_16px_rgba(255,204,31,.45)]' : 'bg-accent-soft text-accent'))
<span {{ $attributes->merge(['class' => "inline-flex shrink-0 items-center justify-center rounded-xl $couleurs"]) }} style="width: {{ $taille }}px; height: {{ $taille }}px">
    <x-charte.icone :nom="$icone" :taille="(int) round($taille * 0.52)" />
</span>
