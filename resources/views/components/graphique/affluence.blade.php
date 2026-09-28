{{--
    Grille d'affluence : jours de la semaine × heures, un ton de bleu par
    niveau (rampe séquentielle : clair = peu, foncé = beaucoup ; vide = aucun
    ticket). Chaque case dit son chiffre au survol, et le total de chaque jour
    est écrit en bout de ligne : la couleur n'est jamais seule.

    grille : [jour 0 (lundi) … 6][heure 0 … 23] => nombre de tickets.
--}}
@props(['grille' => []])

@php
    $jours = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
    $max = max(1, ...array_map(fn ($l) => max($l ?: [0]), $grille ?: [[0]]));
    // Heures montrées : celles où il s'est vendu quelque chose, élargies à 7 h – 21 h.
    $actives = array_keys(array_filter(range(0, 23), fn ($h) => array_sum(array_column($grille, $h)) > 0));
    $de = min([7, ...$actives]);
    $a = max([21, ...$actives]);
    $rampe = ['#e8edf5', '#b7d3f6', '#6da7ec', '#2a78d6', '#184f95'];
    $niveau = fn (int $n) => $n === 0 ? 0 : min(4, 1 + (int) floor(($n / $max) * 3.999));
@endphp

<div {{ $attributes }}>
    <div class="overflow-x-auto">
        <table class="border-separate border-spacing-[2px] text-[11px]" aria-label="Tickets par jour et par heure">
            <thead>
                <tr>
                    <th></th>
                    @for ($h = $de; $h <= $a; $h++)
                        <th class="font-normal text-muted w-6 text-center">{{ $h % 3 === 0 ? $h.'h' : '' }}</th>
                    @endfor
                    <th class="font-normal text-muted pl-2 text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($jours as $j => $jour)
                    <tr>
                        <th class="font-normal text-muted pr-2 text-left">{{ $jour }}</th>
                        @for ($h = $de; $h <= $a; $h++)
                            @php($n = $grille[$j][$h] ?? 0)
                            <td class="h-6 w-6 rounded-[4px]" style="background: {{ $rampe[$niveau($n)] }}"
                                title="{{ $jour }} {{ $h }}h–{{ $h + 1 }}h : {{ $n }} ticket{{ $n > 1 ? 's' : '' }}"></td>
                        @endfor
                        <td class="pl-2 text-right tabular-nums font-bold">{{ array_sum($grille[$j] ?? []) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="mt-2 flex items-center gap-1.5 text-xs text-muted">
        Moins
        @foreach ($rampe as $couleur)
            <span class="w-4 h-3 rounded-[3px]" style="background: {{ $couleur }}"></span>
        @endforeach
        Plus
    </div>
</div>
