@php
    $devise = $boutique->devise;
    $m = fn ($v) => \App\Support\Money\Montant::format((int) $v).' '.$devise;
    // Les courbes tracent des unités (francs, euros), pas des centimes.
    $unites = fn ($v) => $v / (10 ** \App\Support\Money\Currencies::decimals($devise));
    $variation = function (?int $v, bool $inverse = false) {
        if ($v === null) return '<span class="text-muted">—</span>';
        $bon = $inverse ? $v <= 0 : $v >= 0;
        return '<span class="font-bold '.($bon ? 'text-succes' : 'text-danger-fg').'">'.($v >= 0 ? '↑ +' : '↓ ').$v.' %</span>';
    };
    $pct = fn (?float $v) => $v === null ? '—' : str_replace(',0', '', number_format($v, 1, ',', ' ')).' %';
    $ind = $comparaison['indicateurs'];
    $joursSemaine = [1 => 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
    $carte = 'bg-white rounded-[20px] shadow-carte p-5';
@endphp

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-display font-extrabold text-2xl md:text-3xl text-accent">Statistiques</h1>
            <p class="text-sm text-muted">
                Du {{ $du->translatedFormat('j F') }} au {{ $au->translatedFormat('j F Y') }},
                comparé au {{ \Carbon\Carbon::parse($comparaison['precedente']['du'])->translatedFormat('j F') }} – {{ \Carbon\Carbon::parse($comparaison['precedente']['au'])->translatedFormat('j F') }}
            </p>
        </div>
        <div class="flex flex-wrap gap-2" role="group" aria-label="Période">
            @foreach ($periodes as $code => $libelle)
                <button type="button" wire:click="$set('periode', '{{ $code }}')" aria-pressed="{{ $periode === $code ? 'true' : 'false' }}"
                        class="inline-flex items-center gap-1 h-10 px-4 rounded-full text-sm font-bold {{ $periode === $code ? 'bg-jaune text-accent' : 'bg-puce text-accent hover:bg-accent-soft' }}">@if ($periode === $code)<x-charte.icone nom="check" :taille="18" />@endif{{ $libelle }}</button>
            @endforeach
        </div>
    </div>

    {{-- L'essentiel, pour tous les plans : où en est-on, par rapport à avant ? Le chiffre en carte héros, comme le Pilotage. --}}
    <x-charte.carte-heros icone="payments" titre="Chiffre d’affaires" :valeur="$m($ind['chiffre_affaires']['actuel'])" wire:loading.class="opacity-60">
        <span class="self-start rounded-full bg-white px-3 py-0.5 text-xs">{!! $variation($ind['chiffre_affaires']['variation']) !!} <span class="text-muted font-semibold">vs période précédente</span></span>
    </x-charte.carte-heros>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 md:gap-4" wire:loading.class="opacity-60">
        @foreach ([
            ['Tickets', number_format($ind['tickets']['actuel'], 0, ',', ' '), $ind['tickets']['variation'], null],
            ['Panier moyen', $m($ind['panier_moyen']['actuel']), $ind['panier_moyen']['variation'], null],
            ['Bénéfice', $ind['taux_marge']['actuel'] === null ? 'À renseigner' : $m($ind['marge']['actuel']), $ind['marge']['variation'],
                $ind['taux_marge']['actuel'] === null ? 'Prix d’achat manquants' : $pct($ind['taux_marge']['actuel']).' du chiffre'],
        ] as [$libelle, $valeur, $var, $precision])
            <div class="{{ $carte }} min-w-0 !p-4 md:!p-5">
                <p class="flex items-center gap-2 text-sm font-semibold text-muted"><x-charte.pastille :icone="['Tickets' => 'receipt_long', 'Panier moyen' => 'shopping_basket', 'Bénéfice' => 'trending_up'][$libelle] ?? 'insights'" :taille="34" />{{ $libelle }}</p>
                <p class="mt-1 font-display font-extrabold text-lg md:text-2xl tabular-nums truncate" title="{{ $valeur }}">{{ $valeur }}</p>
                @if ($precision)<p class="text-xs font-bold">{{ $precision }}</p>@endif
                <p class="mt-1 text-xs text-muted">{!! $variation($var) !!} vs période précédente</p>
            </div>
        @endforeach
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 items-start" wire:loading.class="opacity-60">
        <section class="{{ $carte }} lg:col-span-2">
            <x-charte.en-tete-section icone="show_chart" titre="Chiffre d’affaires par jour" />
            @php($courbe = collect($comparaison['par_jour']))
            <x-graphique.courbe class="mt-3 hidden sm:block" :unite="$devise" :legende="['Cette période', 'Période précédente']"
                :points="$courbe->mapWithKeys(fn ($j) => [$j['date'] => $unites($j['total'])])->all()"
                :comparaison="$courbe->map(fn ($j) => $unites($j['precedent']))->all()" libelle="Chiffre d’affaires par jour, comparé à la période précédente" />
            <x-graphique.courbe class="mt-3 sm:hidden" :unite="$devise" :largeur="340" :hauteur="200" :etiquettes="4" :legende="['Cette période', 'Période précédente']"
                :points="$courbe->mapWithKeys(fn ($j) => [$j['date'] => $unites($j['total'])])->all()"
                :comparaison="$courbe->map(fn ($j) => $unites($j['precedent']))->all()" libelle="Chiffre d’affaires par jour, comparé à la période précédente" />
        </section>

        <section class="{{ $carte }}">
            <x-charte.en-tete-section icone="payments" titre="Moyens de paiement" />
            <x-graphique.repartition class="mt-4" :parts="collect($rapport['par_moyen'])->values()->map(fn ($p) => [
                'libelle' => $p['libelle'],
                'valeur' => $p['total'],
                'couleur' => \App\Support\CouleursGraphiques::moyenDePaiement($p['moyen']),
                'detail' => $m($p['total']),
            ])->all()" />
            <dl class="mt-5 pt-4 border-t border-separateur grid grid-cols-2 gap-3 text-sm">
                <div><dt class="text-muted text-xs">Articles vendus</dt><dd class="font-bold tabular-nums">{{ number_format($rapport['ventes']['articles'], 0, ',', ' ') }}</dd></div>
                <div><dt class="text-muted text-xs">Remises</dt><dd class="font-bold tabular-nums">{{ $m($rapport['ventes']['remises']) }}</dd></div>
                <div><dt class="text-muted text-xs">Annulations</dt><dd class="font-bold tabular-nums">{{ $rapport['annulees']['nombre'] }}</dd></div>
                <div><dt class="text-muted text-xs">Crédit accordé</dt><dd class="font-bold tabular-nums">{{ $m($rapport['credit']['accorde']) }}</dd></div>
            </dl>
        </section>
    </div>

    @if (! $disponible)
        {{-- Ce que le Pro apporterait : montré, pas caché. --}}
        <section class="rounded-[20px] bg-jaune-doux shadow-carte p-6 md:p-8">
            <p class="text-xs font-bold uppercase tracking-wide text-accent">Plan Pro</p>
            <h2 class="mt-1 font-display font-extrabold text-xl">Allez plus loin dans vos chiffres</h2>
            <ul class="mt-4 grid sm:grid-cols-2 gap-x-8 gap-y-2 text-sm">
                <li>✓ Vos heures et jours d’affluence : quand renforcer la caisse</li>
                <li>✓ La marge de chaque article et de chaque catégorie</li>
                <li>✓ Les moins vendus, et l’argent qu’ils bloquent</li>
                <li>✓ Ce qui sera bientôt fini, avant la rupture</li>
                <li>✓ Vos clients fidèles, les nouveaux, et qui vous doit quoi</li>
                <li>✓ Le chiffre et les écarts de caisse de chaque vendeur</li>
            </ul>
            <p class="mt-5 text-sm text-muted">Pour passer au Pro : dans l’application, <span class="font-bold text-ink">Plus › Abonnement</span>.</p>
        </section>
    @else
        @php($a = $analyse)

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 items-start" wire:loading.class="opacity-60">
            <section class="{{ $carte }} lg:col-span-2">
                <x-charte.en-tete-section icone="schedule" titre="Affluence" />
                <p class="text-xs text-muted">Tickets par jour et par heure, heure de {{ str_replace('_', ' ', \Illuminate\Support\Str::after($a['affluence']['fuseau'], '/')) }}</p>
                @if ($a['affluence']['heure_pointe'] !== null)
                    <p class="mt-3 text-sm">Le plus de monde : <span class="font-bold">le {{ $joursSemaine[$a['affluence']['jour_pointe']] }}</span>, et <span class="font-bold">vers {{ $a['affluence']['heure_pointe'] }} h</span>.</p>
                @endif
                <x-graphique.affluence class="mt-3" :grille="$a['affluence']['grille']" />
            </section>

            <section class="{{ $carte }}">
                <x-charte.en-tete-section icone="category" titre="Par catégorie" />
                <x-graphique.repartition class="mt-4" :parts="collect($a['categories'])->map(fn ($c) => [
                    'libelle' => $c['nom'],
                    'valeur' => $c['total'],
                    'couleur' => \App\Support\CouleursGraphiques::categorie($c['couleur']),
                    'detail' => $m($c['total']),
                ])->all()" />
                <ul class="mt-4 pt-3 border-t border-separateur flex flex-col gap-1 text-xs text-muted">
                    @foreach ($a['categories'] as $c)
                        @if ($c['marge'] !== null && $c['total'] > 0)
                            <li class="flex justify-between gap-3"><span class="truncate">Marge {{ $c['nom'] }}</span><span class="tabular-nums">{{ $m($c['marge']) }} · {{ $pct($c['marge'] * 100 / $c['total']) }}</span></li>
                        @endif
                    @endforeach
                </ul>
            </section>
        </div>

        <section class="{{ $carte }}" wire:loading.class="opacity-60">
            <x-charte.en-tete-section icone="inventory_2" titre="Articles" />
            <div class="mt-3 grid grid-cols-1 xl:grid-cols-3 gap-6">
                <div class="xl:col-span-2 overflow-x-auto">
                    <p class="text-xs text-muted mb-2">Les plus vendus</p>
                    <table class="w-full text-sm">
                        <thead class="text-left text-muted text-xs"><tr><th class="pb-2">Article</th><th class="pb-2 text-right">Qté</th><th class="pb-2 text-right">Vendu</th><th class="pb-2 text-right">Marge</th></tr></thead>
                        <tbody>
                            @forelse ($a['produits']['meilleurs'] as $p)
                                <tr class="border-t border-separateur">
                                    <td class="py-2 font-bold max-w-56 truncate" title="{{ $p['nom'] }}">{{ $p['nom'] }}</td>
                                    <td class="py-2 text-right tabular-nums">{{ number_format($p['quantite'], 0, ',', ' ') }}</td>
                                    <td class="py-2 text-right tabular-nums whitespace-nowrap">{{ $m($p['total']) }}</td>
                                    <td class="py-2 text-right tabular-nums whitespace-nowrap {{ ($p['taux_marge'] ?? 100) < 10 ? 'text-danger-fg' : '' }}">
                                        {{ $pct($p['taux_marge']) }}
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="py-6 text-center text-muted">Aucune vente d’article sur la période.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                    @if ($a['produits']['libres']['nombre'] > 0)
                        <p class="mt-2 text-xs text-muted">Plus {{ $a['produits']['libres']['nombre'] }} montant(s) libre(s) : {{ $m($a['produits']['libres']['total']) }}.</p>
                    @endif
                </div>
                <div class="flex flex-col gap-5">
                    <div>
                        <p class="text-xs text-muted mb-2">Ceux qui rapportent le plus</p>
                        <ul class="flex flex-col gap-1.5 text-sm">
                            @forelse ($a['produits']['plus_rentables'] as $p)
                                <li class="flex justify-between gap-3"><span class="truncate">{{ $p['nom'] }}</span><span class="font-bold tabular-nums text-succes whitespace-nowrap">+{{ $m($p['marge']) }}</span></li>
                            @empty
                                <li class="text-muted">Renseignez les prix d’achat pour voir la marge.</li>
                            @endforelse
                        </ul>
                    </div>
                    @if ($a['produits']['a_surveiller'])
                        <div class="rounded-xl bg-danger-bg p-3">
                            <p class="text-xs font-bold text-danger-fg mb-1.5">Marge faible ou négative : prix à revoir ?</p>
                            <ul class="flex flex-col gap-1 text-sm">
                                @foreach ($a['produits']['a_surveiller'] as $p)
                                    <li class="flex justify-between gap-3"><span class="truncate">{{ $p['nom'] }}</span><span class="font-bold tabular-nums whitespace-nowrap">{{ $pct($p['taux_marge']) }}</span></li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            </div>
        </section>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 items-start" wire:loading.class="opacity-60">
            <section class="{{ $carte }}">
                <x-charte.en-tete-section icone="inventory" titre="Bientôt fini" />
                <p class="text-xs text-muted">Il en reste pour moins de {{ \App\Services\Statistiques::JOURS_COUVERTURE_MIN }} jours</p>
                <ul class="mt-3 divide-y divide-separateur text-sm">
                    @forelse ($a['stock']['a_racheter'] as $p)
                        <li class="py-2 flex items-center justify-between gap-3">
                            <span class="min-w-0"><span class="font-bold truncate block">{{ $p['nom'] }}</span><span class="text-xs text-muted">{{ $p['stock'] }} en stock · {{ str_replace('.', ',', (string) $p['vendus_par_jour']) }} vendu(s) par jour</span></span>
                            <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-bold {{ $p['jours_restants'] <= 2 ? 'bg-danger-bg text-danger-fg' : 'bg-warn-bg text-warn-fg' }}">
                                {{ $p['jours_restants'] === 0 ? 'Moins d’un jour' : $p['jours_restants'].' j' }}
                            </span>
                        </li>
                    @empty
                        <li class="py-6 text-center text-muted">Rien ne va manquer dans la semaine.</li>
                    @endforelse
                </ul>
            </section>

            <section class="{{ $carte }}">
                <x-charte.en-tete-section icone="trending_down" titre="Moins vendus" />
                <p class="text-xs text-muted">Aucune vente depuis {{ \App\Services\Statistiques::JOURS_DORMANT }} jours</p>
                <p class="mt-3 text-sm">Argent bloqué : <span class="font-display font-extrabold text-xl tabular-nums">{{ $m($a['stock']['valeur_dormante']) }}</span></p>
                <ul class="mt-2 divide-y divide-separateur text-sm">
                    @forelse ($a['stock']['dormants'] as $p)
                        <li class="py-2 flex items-center justify-between gap-3">
                            <span class="min-w-0"><span class="font-bold truncate block">{{ $p['nom'] }}</span><span class="text-xs text-muted">{{ $p['stock'] }} en stock</span></span>
                            <span class="shrink-0 tabular-nums" title="{{ $p['valeur_estimee'] ? 'Compté au prix de vente : prix d’achat non renseigné' : 'Au prix d’achat' }}">{{ $m($p['valeur']) }}{{ $p['valeur_estimee'] ? '*' : '' }}</span>
                        </li>
                    @empty
                        <li class="py-6 text-center text-muted">
                            @if (($a['stock']['moins_vendus_dans'] ?? 0) > 0)
                                Revenez dans {{ $a['stock']['moins_vendus_dans'] }} jour{{ $a['stock']['moins_vendus_dans'] > 1 ? 's' : '' }} : il faut un mois de ventes pour repérer les articles qui ne partent pas.
                            @else
                                Tous vos articles se vendent.
                            @endif
                        </li>
                    @endforelse
                </ul>
                @if (collect($a['stock']['dormants'])->contains('valeur_estimee', true))
                    <p class="mt-2 text-xs text-muted">* Compté au prix de vente : le prix d’achat n’est pas renseigné.</p>
                @endif
            </section>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 items-start" wire:loading.class="opacity-60">
            <section class="{{ $carte }}">
                <x-charte.en-tete-section icone="groups" titre="Clients" />
                @php($c = $a['clients'])
                <div class="mt-3 grid grid-cols-3 gap-3 text-center">
                    @foreach ([['Actifs', $c['actifs']], ['Nouveaux', $c['nouveaux']], ['Revenus 2 fois ou +', $c['fideles']]] as [$libelle, $valeur])
                        <div class="rounded-xl bg-fond-tableau p-3"><p class="font-display font-extrabold text-xl tabular-nums">{{ $valeur }}</p><p class="text-xs text-muted">{{ $libelle }}</p></div>
                    @endforeach
                </div>
                @if ($c['part_identifiee'] !== null)
                    <p class="mt-3 text-xs text-muted">{{ $c['part_identifiee'] }} % du chiffre d’affaires vient de clients enregistrés. Enregistrer le client à la vente, c’est savoir qui revient.</p>
                @endif
                <ul class="mt-3 divide-y divide-separateur text-sm">
                    @foreach ($c['meilleurs'] as $client)
                        <li class="py-2 flex justify-between gap-3"><span class="truncate font-bold">{{ $client['nom'] }}</span><span class="text-muted tabular-nums whitespace-nowrap">{{ $client['visites'] }} achat(s) · {{ $m($client['total']) }}</span></li>
                    @endforeach
                </ul>
            </section>

            <section class="{{ $carte }}">
                <x-charte.en-tete-section icone="handshake" titre="Crédit clients" />
                <p class="mt-2 text-sm">Encours : <span class="font-display font-extrabold text-xl tabular-nums">{{ $m($c['credit']['encours']) }}</span>
                    <span class="text-muted">· {{ $c['credit']['debiteurs'] }} client(s)</span></p>
                <ul class="mt-2 divide-y divide-separateur text-sm">
                    @forelse ($c['credit']['plus_gros'] as $d)
                        <li class="py-2 flex items-center justify-between gap-3">
                            <span class="min-w-0"><span class="font-bold truncate block">{{ $d['nom'] }}</span>
                                <span class="text-xs text-muted">{{ $d['dernier_reglement'] ? 'Dernier règlement le '.\Carbon\Carbon::parse($d['dernier_reglement'])->translatedFormat('j M') : 'Aucun règlement' }}</span></span>
                            <span class="font-bold tabular-nums whitespace-nowrap">{{ $m($d['solde_du']) }}</span>
                        </li>
                    @empty
                        <li class="py-6 text-center text-muted">Personne ne vous doit rien.</li>
                    @endforelse
                </ul>
                <div class="mt-4 pt-4 border-t border-separateur grid grid-cols-3 gap-3 text-sm">
                    <div><p class="text-xs text-muted">Achats fournisseurs</p><p class="font-bold tabular-nums">{{ $m($a['achats']['total']) }}</p></div>
                    <div><p class="text-xs text-muted">Payé</p><p class="font-bold tabular-nums">{{ $m($a['achats']['paye']) }}</p></div>
                    <div><p class="text-xs text-muted">Dû aux fournisseurs</p><p class="font-bold tabular-nums">{{ $m($a['achats']['du_fournisseurs']) }}</p></div>
                </div>
            </section>
        </div>

        <section class="{{ $carte }}" wire:loading.class="opacity-60">
            <x-charte.en-tete-section icone="badge" titre="Équipe" />
            {{-- Téléphone : une fiche par vendeur ; six colonnes n'y tiennent pas. --}}
            <ul class="mt-3 sm:hidden divide-y divide-separateur text-sm">
                @foreach ($a['equipe'] as $u)
                    <li class="py-3">
                        <div class="flex justify-between gap-3"><span class="font-bold">{{ $u['nom'] }}</span><span class="font-bold tabular-nums">{{ $m($u['total']) }}</span></div>
                        <p class="mt-0.5 text-xs text-muted">{{ $u['tickets'] }} ticket(s) · panier {{ $m($u['panier_moyen']) }}
                            · <span class="{{ $u['annulations'] > 0 ? 'text-warn-fg font-bold' : '' }}">{{ $u['annulations'] }} annulation(s)</span></p>
                        @if ($u['ecart'] !== null)
                            <p class="text-xs {{ $u['ecart'] < 0 ? 'text-danger-fg font-bold' : 'text-muted' }}">Écart de caisse : {{ ($u['ecart'] > 0 ? '+' : '').$m($u['ecart']) }} sur {{ $u['seances'] }} séance(s)</p>
                        @endif
                    </li>
                @endforeach
            </ul>
            <div class="mt-3 hidden sm:block overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-muted text-xs"><tr><th class="pb-2">Vendeur</th><th class="pb-2 text-right">Tickets</th><th class="pb-2 text-right">Encaissé</th><th class="pb-2 text-right">Panier moyen</th><th class="pb-2 text-right">Annulations</th><th class="pb-2 text-right">Écart de caisse</th></tr></thead>
                    <tbody>
                        @forelse ($a['equipe'] as $u)
                            <tr class="border-t border-separateur">
                                <td class="py-2 font-bold">{{ $u['nom'] }}</td>
                                <td class="py-2 text-right tabular-nums">{{ $u['tickets'] }}</td>
                                <td class="py-2 text-right tabular-nums whitespace-nowrap">{{ $m($u['total']) }}</td>
                                <td class="py-2 text-right tabular-nums whitespace-nowrap">{{ $m($u['panier_moyen']) }}</td>
                                <td class="py-2 text-right tabular-nums {{ $u['annulations'] > 0 ? 'text-warn-fg font-bold' : '' }}">{{ $u['annulations'] }}</td>
                                <td class="py-2 text-right tabular-nums whitespace-nowrap {{ ($u['ecart'] ?? 0) < 0 ? 'text-danger-fg font-bold' : '' }}">
                                    {{ $u['ecart'] === null ? '—' : ($u['ecart'] > 0 ? '+' : '').$m($u['ecart']).' ('.$u['seances'].' séance'.($u['seances'] > 1 ? 's' : '').')' }}
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-6 text-center text-muted">Aucune vente sur la période.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</div>
