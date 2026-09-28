@php
    $fcfa = fn (int $v) => number_format($v, 0, ',', ' ').' F';
    $variation = function (?int $v) {
        if ($v === null) return '';
        $classe = $v >= 0 ? 'text-succes' : 'text-danger-fg';
        return '<span class="font-bold '.$classe.'">'.($v >= 0 ? '↑ +' : '↓ ').$v.' %</span>';
    };
@endphp

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-display font-extrabold text-2xl md:text-3xl">Tableau de bord</h1>
            <p class="text-muted text-sm">Vue d’ensemble de la plateforme · {{ $periodeLibelle }}</p>
        </div>
        <div class="flex gap-1 rounded-xl bg-white border border-border p-1" role="group" aria-label="Période">
            @foreach ($choix as $code => $libelle)
                <button type="button" wire:click="$set('periode', '{{ $code }}')"
                        class="h-9 px-3.5 rounded-lg text-sm font-bold {{ $periode === $code ? 'bg-accent text-white' : 'text-muted hover:text-ink' }}"
                        aria-pressed="{{ $periode === $code ? 'true' : 'false' }}">{{ $libelle }}</button>
            @endforeach
        </div>
    </div>

    {{-- Indicateurs : la variation compare à la période précédente de même durée. --}}
    <div class="grid grid-cols-2 xl:grid-cols-4 gap-3 md:gap-4" wire:loading.class="opacity-60">
        @foreach ([
            ['Boutiques', number_format($indicateurs['boutiques']['valeur'], 0, ',', ' '), $indicateurs['boutiques']['detail'], $indicateurs['boutiques']['variation']],
            ['Utilisateurs actifs', number_format($indicateurs['utilisateurs']['valeur'], 0, ',', ' '), $indicateurs['utilisateurs']['detail'], null],
            ['Encaissements (FCFA)', $fcfa($indicateurs['encaissements']['valeur']), 'vs période précédente', $indicateurs['encaissements']['variation']],
            ['Ventes enregistrées', number_format($indicateurs['ventes']['valeur'], 0, ',', ' '), 'vs période précédente', $indicateurs['ventes']['variation']],
        ] as [$libelle, $valeur, $detail, $var])
            <div class="bg-white border border-border rounded-2xl p-4 md:p-5 min-w-0">
                <p class="text-sm text-muted">{{ $libelle }}</p>
                <p class="mt-1 font-display font-extrabold text-xl md:text-3xl tabular-nums truncate">{{ $valeur }}</p>
                <p class="mt-1 text-xs text-muted">{!! $variation($var) !!} {{ $detail }}</p>
            </div>
        @endforeach
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 items-start">
        <livewire:plateforme.en-ligne />

        <section class="lg:col-span-2 bg-white border border-border rounded-2xl p-5" wire:loading.class="opacity-60">
            <div class="flex items-center justify-between gap-3">
                <h2 class="font-display font-extrabold text-lg">Encaissements par boutique</h2>
                <a href="{{ route('plateforme.comptes') }}" class="text-sm font-bold text-accent hover:underline">Toutes les boutiques →</a>
            </div>

            <div class="mt-4">
                <p class="text-sm text-muted">Total encaissé</p>
                <p class="font-display font-extrabold text-2xl tabular-nums">{{ $fcfa($total) }}
                    <span class="text-sm align-middle">{!! $variation($indicateurs['encaissements']['variation']) !!}</span></p>
                @if ($horsFcfa)
                    <p class="text-xs text-muted mt-1">Hors total, sans taux fixe :
                        {{ collect($horsFcfa)->map(fn ($m, $d) => \App\Support\Money\Montant::format($m, $d).' '.$d)->implode(' · ') }}</p>
                @endif
                <x-graphique.courbe class="mt-3 hidden sm:block" :points="$parJour" unite="F" libelle="Encaissements par jour, en francs CFA" />
                <x-graphique.courbe class="mt-3 sm:hidden" :points="$parJour" unite="F" :largeur="340" :hauteur="200" :etiquettes="4" libelle="Encaissements par jour, en francs CFA" />
            </div>

            {{-- Barres à l'échelle de la première boutique : les écarts se voient ; le % exact est écrit à côté. --}}
            @php($plusGrand = max(1, (int) $parBoutique->max('fcfa')))
            <div class="mt-5 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-muted text-xs">
                        <tr><th class="pb-2 w-6">#</th><th class="pb-2">Boutique</th><th class="pb-2 text-right">Encaissé</th><th class="pb-2 pl-4 sm:w-2/5">% du total</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($parBoutique as $i => $b)
                            <tr class="border-t border-separateur">
                                <td class="py-2 pr-2 text-muted align-top">{{ $i + 1 }}</td>
                                <td class="py-2 font-bold max-w-48 truncate" title="{{ $b->nom }}">{{ $b->nom }}<span class="block text-xs font-normal text-muted">{{ $b->ventes }} vente(s) · {{ \App\Services\Plateforme\Encaissements::libellePays($b->pays) }}</span></td>
                                <td class="py-2 text-right tabular-nums whitespace-nowrap">
                                    {{ $b->fcfa !== null ? $fcfa($b->fcfa) : \App\Support\Money\Montant::format($b->montant, $b->devise).' '.$b->devise }}
                                </td>
                                <td class="py-2 pl-4">
                                    @if ($b->fcfa !== null && $total > 0)
                                        <div class="flex items-center gap-2">
                                            <span class="w-12 text-xs tabular-nums text-right">{{ number_format($b->fcfa / $total * 100, 1, ',', '') }} %</span>
                                            <span class="hidden sm:block grow h-2 rounded bg-puce"><span class="block h-full rounded bg-[#2a78d6]" style="width: {{ $b->fcfa / $plusGrand * 100 }}%"></span></span>
                                        </div>
                                    @else
                                        <span class="text-xs text-muted">hors FCFA</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-6 text-center text-muted">Aucune vente sur la période.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 items-start" wire:loading.class="opacity-60">
        <section class="bg-white border border-border rounded-2xl p-5">
            <h2 class="font-display font-extrabold text-lg">Boutiques les plus actives</h2>
            <p class="text-xs text-muted">Au nombre de ventes, quelle que soit la devise</p>
            <ol class="mt-3 divide-y divide-separateur">
                @forelse ($plusActives as $i => $b)
                    <li class="py-2.5 flex items-center gap-3 text-sm">
                        <span class="w-5 text-muted">{{ $i + 1 }}</span>
                        <span class="grow min-w-0 truncate font-bold">{{ $b->nom }}</span>
                        <span class="tabular-nums text-muted">{{ $b->ventes }} ventes</span>
                        <span class="w-14 text-right text-xs">{!! $variation($b->variation) ?: '<span class="text-muted">nouveau</span>' !!}</span>
                    </li>
                @empty
                    <li class="py-6 text-sm text-muted text-center">Aucune vente sur la période.</li>
                @endforelse
            </ol>
        </section>

        <section class="bg-white border border-border rounded-2xl p-5">
            <h2 class="font-display font-extrabold text-lg">Encaissements par pays</h2>
            <p class="text-xs text-muted mb-4">Pays des boutiques, en francs CFA</p>
            <x-graphique.repartition :parts="$parPays" />
        </section>

        <section class="bg-white border border-border rounded-2xl p-5">
            <h2 class="font-display font-extrabold text-lg">Appareils utilisés</h2>
            <p class="text-xs text-muted mb-4">Utilisateurs vus sur la période</p>
            <x-graphique.repartition :parts="$parAppareil" />
            @if ($utilisateursParPays->isNotEmpty())
                @php($pays = $utilisateursParPays->keys()->first())
                <div class="mt-5 pt-4 border-t border-separateur">
                    <p class="text-xs text-muted">Pays le plus actif</p>
                    <p class="font-bold">{{ \App\Services\Plateforme\Encaissements::libellePays($pays) }}</p>
                    <p class="text-xs text-muted">{{ round($utilisateursParPays->first() / max(1, $utilisateursParPays->sum()) * 100) }} % des utilisateurs actifs</p>
                </div>
            @endif
        </section>
    </div>

    {{-- Abonnements : ce que le tableau montrait déjà, en bandeau. --}}
    <div class="flex flex-wrap items-center gap-x-6 gap-y-2 bg-white border border-border rounded-2xl px-5 py-4 text-sm">
        <span><span class="font-bold text-accent">{{ $abonnements['actifs'] }}</span> abonnements actifs</span>
        <span><span class="font-bold text-danger-fg">{{ $abonnements['expires'] }}</span> expirés (lecture seule)</span>
        @if ($abonnements['demandes'] > 0)
            <a href="{{ route('plateforme.demandes') }}" class="ml-auto h-10 px-4 rounded-xl bg-accent text-white font-bold inline-flex items-center">
                Traiter les {{ $abonnements['demandes'] }} demande(s) en attente
            </a>
        @endif
    </div>
</div>
