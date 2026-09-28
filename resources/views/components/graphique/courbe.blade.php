{{--
    Courbe d'une seule série dans le temps (aire légère sous une ligne de 2 px).

    Le SVG se met à l'échelle de sa boîte, texte compris : pour un écran
    étroit, dessiner une version plus étroite (largeur, etiquettes) plutôt que
    de laisser les libellés rétrécir jusqu'à l'illisible.

    points : [libellé de l'axe (Y-m-d) => valeur]. Survol : ligne verticale et
    infobulle sur le jour visé, avec une zone de survol de la largeur d'un jour
    entier (bien plus large que le point). Une table suit, pour les lecteurs
    d'écran et qui préfère les chiffres.
--}}
@props(['points' => [], 'unite' => '', 'largeur' => 640, 'hauteur' => 220, 'etiquettes' => 7, 'libelle' => 'Évolution'])

@php
    $valeurs = array_values($points);
    $jours = array_keys($points);
    $n = count($valeurs);
    $marge = ['haut' => 12, 'droite' => 12, 'bas' => 26, 'gauche' => 48];
    $l = $largeur - $marge['gauche'] - $marge['droite'];
    $h = $hauteur - $marge['haut'] - $marge['bas'];

    // Échelle ronde : 0 en bas, un maximum « lisible » (1, 2, 2,5, 5 × 10^k).
    $brut = max(1, ...($valeurs ?: [1]));
    $puissance = 10 ** floor(log10($brut));
    $max = collect([1, 2, 2.5, 5, 10])->map(fn ($m) => $m * $puissance)->first(fn ($m) => $m >= $brut);
    $x = fn (int $i) => $marge['gauche'] + ($n <= 1 ? $l / 2 : $i * $l / ($n - 1));
    $y = fn (float $v) => $marge['haut'] + $h - ($v / $max) * $h;
    $court = fn (float $v) => $v >= 1_000_000 ? rtrim(rtrim(number_format($v / 1_000_000, 1, ',', ''), '0'), ',').' M'
        : ($v >= 1000 ? rtrim(rtrim(number_format($v / 1000, 1, ',', ''), '0'), ',').' k' : (string) (int) $v);

    $ligne = collect($valeurs)->map(fn ($v, $i) => round($x($i), 1).','.round($y($v), 1))->implode(' ');
    $aire = $n ? 'M'.round($x(0), 1).','.($marge['haut'] + $h).' L'.str_replace(' ', ' L', $ligne).' L'.round($x($n - 1), 1).','.($marge['haut'] + $h).' Z' : '';
    // Au plus `etiquettes` dates sur l'axe : au-delà, elles se chevauchent.
    $pas = max(1, (int) ceil($n / $etiquettes));
    $jourCourt = fn (string $d) => \Carbon\CarbonImmutable::parse($d)->translatedFormat('j M');
    $donnees = collect($jours)->map(fn ($d, $i) => ['jour' => \Carbon\CarbonImmutable::parse($d)->translatedFormat('j M Y'), 'valeur' => number_format($valeurs[$i], 0, ',', ' ').($unite ? ' '.$unite : ''), 'x' => $x($i), 'y' => $y($valeurs[$i])])->values();
@endphp

<figure {{ $attributes->class('m-0') }} x-data="{ actif: null, points: @js($donnees) }">
    <div class="relative">
        <svg viewBox="0 0 {{ $largeur }} {{ $hauteur }}" class="w-full h-auto block" role="img" aria-label="{{ $libelle }}">
            @foreach ([0, 0.5, 1] as $f)
                <line x1="{{ $marge['gauche'] }}" x2="{{ $largeur - $marge['droite'] }}" y1="{{ $y($max * $f) }}" y2="{{ $y($max * $f) }}" stroke="var(--color-separateur)" stroke-width="1" />
                <text x="{{ $marge['gauche'] - 8 }}" y="{{ $y($max * $f) + 4 }}" text-anchor="end" font-size="11" fill="var(--color-muted)">{{ $court($max * $f) }}</text>
            @endforeach
            @if ($n)
                <path d="{{ $aire }}" fill="#2a78d6" fill-opacity="0.10" />
                <polyline points="{{ $ligne }}" fill="none" stroke="#2a78d6" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
            @endif
            @foreach ($jours as $i => $jour)
                @if ($i % $pas === 0 || $i === $n - 1)
                    {{-- Première et dernière dates alignées sur le bord : centrées, elles déborderaient. --}}
                    <text x="{{ $x($i) }}" y="{{ $hauteur - 8 }}" text-anchor="{{ $n > 1 && $i === 0 ? 'start' : ($n > 1 && $i === $n - 1 ? 'end' : 'middle') }}" font-size="11" fill="var(--color-muted)">{{ $jourCourt($jour) }}</text>
                @endif
            @endforeach
            <template x-if="actif !== null">
                <g>
                    <line :x1="points[actif].x" :x2="points[actif].x" y1="{{ $marge['haut'] }}" y2="{{ $marge['haut'] + $h }}" stroke="var(--color-muted)" stroke-width="1" stroke-dasharray="3 3" />
                    <circle :cx="points[actif].x" :cy="points[actif].y" r="5" fill="#2a78d6" stroke="#fff" stroke-width="2" />
                </g>
            </template>
            @foreach ($jours as $i => $jour)
                <rect x="{{ $x($i) - ($n > 1 ? $l / ($n - 1) / 2 : $l / 2) }}" y="{{ $marge['haut'] }}" width="{{ $n > 1 ? $l / ($n - 1) : $l }}" height="{{ $h }}" fill="transparent"
                      x-on:mouseenter="actif = {{ $i }}" x-on:mouseleave="actif = null" />
            @endforeach
        </svg>
        <div x-show="actif !== null" x-cloak class="pointer-events-none absolute -translate-x-1/2 -translate-y-full rounded-lg bg-ink text-white text-xs px-2.5 py-1.5 shadow whitespace-nowrap"
             :style="actif !== null && `left:${points[actif].x / {{ $largeur }} * 100}%; top:${points[actif].y / {{ $hauteur }} * 100 - 3}%`">
            <span class="block text-rail" x-text="actif !== null && points[actif].jour"></span>
            <span class="font-bold" x-text="actif !== null && points[actif].valeur"></span>
        </div>
    </div>
    <details class="mt-2 text-sm">
        <summary class="cursor-pointer text-muted">Voir les chiffres</summary>
        <table class="mt-2 w-full text-left">
            <tbody>
                @foreach ($donnees as $d)
                    <tr class="border-t border-separateur"><td class="py-1">{{ $d['jour'] }}</td><td class="py-1 text-right tabular-nums">{{ $d['valeur'] }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </details>
</figure>
