{{-- Pastille ronde aux initiales : l'ancre visuelle d'une ligne « personne ». --}}
@props(['nom' => null, 'taille' => 'w-10 h-10 text-sm'])
@php
    $mots = preg_split('/\s+/u', trim((string) $nom), -1, PREG_SPLIT_NO_EMPTY);
    $initiales = mb_strtoupper(collect($mots)->take(2)->map(fn ($m) => mb_substr($m, 0, 1))->implode('')) ?: '?';
    // Une teinte stable par personne, prise dans la palette (pas de couleur au hasard).
    $teintes = ['bg-accent-soft text-accent', 'bg-jaune-doux text-warn-fg', 'bg-succes-doux text-succes', 'bg-puce text-nuit-clair'];
    $teinte = $teintes[abs(crc32((string) $nom)) % count($teintes)];
@endphp
<span aria-hidden="true" {{ $attributes->merge(['class' => "$taille $teinte shrink-0 rounded-full inline-flex items-center justify-center font-display font-bold ring-2 ring-white"]) }}>{{ $initiales }}</span>
