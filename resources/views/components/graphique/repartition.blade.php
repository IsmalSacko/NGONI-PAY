{{--
    Part de chaque élément dans un tout : une barre empilée horizontale (des
    parts proches se comparent mieux en longueur qu'en angle), puis la légende,
    chaque ligne avec son libellé, sa valeur et sa part — la couleur n'est
    jamais seule à porter l'information.

    parts : liste de ['libelle' => …, 'valeur' => nombre, 'couleur' => #hex, 'detail' => texte ?].
--}}
@props(['parts' => [], 'vide' => 'Aucune donnée sur la période.'])

@php
    $total = array_sum(array_column($parts, 'valeur'));
@endphp

<div {{ $attributes }}>
    @if ($total <= 0)
        <p class="text-sm text-muted py-6 text-center">{{ $vide }}</p>
    @else
        {{-- 2 px de fond entre deux parts : elles restent distinctes, même en noir et blanc. --}}
        <div class="flex h-3 w-full gap-[2px] overflow-hidden rounded" role="img"
             aria-label="{{ collect($parts)->map(fn ($p) => $p['libelle'].' '.round($p['valeur'] / $total * 100).' %')->implode(', ') }}">
            @foreach ($parts as $p)
                @if ($p['valeur'] > 0)
                    <div class="h-full first:rounded-l last:rounded-r" style="width: {{ $p['valeur'] / $total * 100 }}%; background: {{ $p['couleur'] }}"
                         title="{{ $p['libelle'] }} : {{ $p['detail'] ?? number_format($p['valeur'], 0, ',', ' ') }} ({{ round($p['valeur'] / $total * 100) }} %)"></div>
                @endif
            @endforeach
        </div>
        <ul class="mt-4 flex flex-col gap-2 text-sm">
            @foreach ($parts as $p)
                <li class="flex items-center gap-2.5">
                    <span class="w-2.5 h-2.5 rounded-full shrink-0" style="background: {{ $p['couleur'] }}"></span>
                    <span class="grow min-w-0 truncate">{{ $p['libelle'] }}</span>
                    <span class="text-muted tabular-nums">{{ $p['detail'] ?? number_format($p['valeur'], 0, ',', ' ') }}</span>
                    <span class="w-10 text-right font-bold tabular-nums">{{ round($p['valeur'] / $total * 100) }} %</span>
                </li>
            @endforeach
        </ul>
    @endif
</div>
