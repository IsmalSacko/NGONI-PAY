@php($m = fn ($v) => \App\Support\Money\Montant::format((int) $v))
<div class="flex flex-col gap-5" wire:poll.visible.30s>
    {{-- Même habit que le Pilotage de l'application. --}}
    <div class="flex flex-col gap-1">
        <h1 class="font-display font-extrabold text-2xl md:text-3xl text-accent">Tableau de bord</h1>
        <span class="text-muted text-sm">{{ $boutique->nom }} · {{ now()->translatedFormat('l j F') }}</span>
    </div>

    <x-charte.carte-heros icone="payments" titre="Chiffre d’affaires · Aujourd’hui" :valeur="$m($total).' '.$boutique->devise">
        @if ($jour && ($jour['credit']['encore_du'] ?? 0) > 0)
            <span class="text-white">Encaissé {{ $m($jour['encaisse']['total']) }} {{ $boutique->devise }}</span>
            <a href="{{ route('clients.index') }}" class="text-[#ffb4a9] no-underline hover:underline">Reste à encaisser −{{ $m($jour['credit']['encore_du']) }} {{ $boutique->devise }} ›</a>
        @endif
    </x-charte.carte-heros>

    {{-- Objectif du mois et série de jours de vente, comme la carte Élan du Pilotage. --}}
    @if ($elan)
        @php($o = $elan['objectif'])
        @php($serie = $elan['serie'])
        <x-charte.carte class="p-5 flex flex-col gap-3">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <x-charte.en-tete-section icone="flag" titre="Objectif du mois"
                    :sous-titre="$serie['jours'] > 0 ? $serie['jours'].' jour'.($serie['jours'] > 1 ? 's' : '').' de vente d’affilée'.($serie['aujourdhui'] ? '' : ' : une vente aujourd’hui garde la série') : 'Une vente aujourd’hui lance la série.'" />
                @can('boutique.update')
                    <button wire:click="ouvrirObjectif" class="h-10 px-4 rounded-xl {{ $o['montant'] ? 'bg-white border border-border-strong text-accent' : 'bg-accent text-white' }} font-bold text-sm">
                        {{ $o['montant'] ? 'Modifier l’objectif' : 'Fixer un objectif du mois' }}
                    </button>
                @endcan
            </div>
            @if ($o['montant'])
                @php($pct = min(100, (int) $o['pourcentage']))
                <div class="flex items-baseline justify-between gap-2">
                    <span class="font-display font-extrabold text-2xl text-ink tabular-nums">{{ $m($o['realise']) }} <span class="text-base text-muted font-semibold">/ {{ $m($o['montant']) }} {{ $boutique->devise }}</span></span>
                    <span class="font-extrabold {{ $o['pourcentage'] >= 100 ? 'text-succes-fonce' : 'text-accent' }}">{{ $o['pourcentage'] }} %</span>
                </div>
                <div class="h-3 rounded-full bg-accent-soft overflow-hidden" role="progressbar" aria-valuenow="{{ $pct }}" aria-valuemin="0" aria-valuemax="100">
                    <div class="h-full rounded-full {{ $o['pourcentage'] >= 100 ? 'bg-succes' : 'bg-jaune' }}" style="width: {{ $pct }}%"></div>
                </div>
                <p class="text-xs text-muted">{{ $o['pourcentage'] >= 100 ? 'Objectif atteint, bravo !' : 'Encore '.$m($o['montant'] - $o['realise']).' '.$boutique->devise.' pour l’atteindre.' }}</p>
            @endif
        </x-charte.carte>
    @endif

    @if ($objectifOuvert)
        <div class="fixed inset-0 bg-black/40 flex items-end md:items-center justify-center z-50 md:p-4" wire:click.self="$set('objectifOuvert', false)">
            <form wire:submit="enregistrerObjectif" class="bg-paper rounded-t-[24px] md:rounded-[24px] p-6 shadow-carte-haute w-full md:max-w-sm flex flex-col gap-3">
                <h2 class="font-display font-extrabold text-xl text-accent">Objectif du mois</h2>
                <label class="text-sm font-semibold">Chiffre d’affaires visé ce mois-ci ({{ $boutique->devise }})
                    <input wire:model="objectif" type="text" inputmode="decimal" placeholder="ex. 1 500 000" class="mt-1 w-full h-11 px-3 rounded-xl bg-white border border-border-strong font-normal">
                </label>
                @error('objectif') <p class="text-sm text-danger-fg">{{ $message }}</p> @enderror
                <p class="text-xs text-muted">Laissez vide pour retirer l’objectif.</p>
                <div class="flex gap-3 mt-2">
                    <button type="button" wire:click="$set('objectifOuvert', false)" class="flex-1 h-11 rounded-xl bg-white border border-border-strong font-bold">Annuler</button>
                    <button type="submit" class="flex-1 h-11 rounded-xl bg-accent text-white font-bold">Enregistrer</button>
                </div>
            </form>
        </div>
    @endif

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        @foreach ([['Tickets', $tickets, 'receipt_long'], ['Panier moyen', $m($panierMoyen).' '.$boutique->devise, 'shopping_basket'], ['TVA collectée', $m($tva).' '.$boutique->devise, 'percent']] as [$libelle, $valeur, $icone])
            <x-charte.carte class="p-4 flex flex-col gap-2">
                <span class="flex items-center gap-2"><x-charte.pastille :icone="$icone" :taille="34" /><span class="text-sm font-semibold text-muted">{{ $libelle }}</span></span>
                <span class="font-display font-extrabold text-2xl text-ink tabular-nums">{{ $valeur }}</span>
            </x-charte.carte>
        @endforeach
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <x-charte.carte class="lg:col-span-2 p-5 flex flex-col gap-3">
            <x-charte.en-tete-section icone="schedule" titre="Ventes par heure" sous-titre="La barre jaune : l’heure la plus forte." />
            <div class="flex items-end gap-1 md:gap-2 h-40">
                @foreach ($ventesParHeure as $point)
                    <div class="flex-1 min-w-0 flex flex-col items-center justify-end h-full gap-1">
                        <div class="w-full rounded-t-md {{ $point['total'] === $maxHeure && $point['total'] > 0 ? 'bg-jaune' : 'bg-nuit-clair' }}"
                             style="height: {{ $maxHeure > 0 ? max(2, round($point['total'] / $maxHeure * 100)) : 0 }}%"
                             title="{{ $point['heure'] }}h : {{ $m($point['total']) }}"></div>
                        {{-- Téléphone : une heure sur trois, sinon les libellés se chevauchent. --}}
                        <span class="text-[10px] text-muted {{ $loop->index % 3 === 0 ? '' : 'invisible md:visible' }}">{{ $point['heure'] }}h</span>
                    </div>
                @endforeach
            </div>
        </x-charte.carte>

        <x-charte.carte class="p-5 flex flex-col gap-3">
            <x-charte.en-tete-section icone="payments" titre="Moyens de paiement" />
            @forelse ($parMoyen as $moyen)
                <div class="flex flex-col gap-1">
                    <div class="flex justify-between text-sm">
                        <span class="font-semibold text-ink">{{ $moyen['label'] }}</span>
                        <span class="font-bold text-accent">{{ $moyen['pct'] }} % <span class="text-muted font-normal">· {{ $m($moyen['total']) }}</span></span>
                    </div>
                    <div class="h-2 rounded-full bg-puce"><div class="h-2 rounded-full bg-accent" style="width: {{ $moyen['pct'] }}%"></div></div>
                </div>
            @empty
                <x-charte.etat-vide icone="payments" titre="Aucune vente aujourd’hui." class="!py-4" />
            @endforelse
        </x-charte.carte>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <x-charte.carte class="p-5 flex flex-col gap-2">
            <x-charte.en-tete-section icone="star" titre="Meilleures ventes du jour" />
            <div>
                @forelse ($topProduits as $p)
                    <div class="flex items-center gap-3 py-2 border-b border-separateur last:border-b-0">
                        <span class="w-8 h-8 shrink-0 rounded-full {{ $loop->first ? 'bg-jaune' : 'bg-accent-soft' }} text-accent text-sm font-extrabold flex items-center justify-center">{{ $loop->iteration }}</span>
                        <span class="flex-1 min-w-0 font-semibold text-ink truncate">{{ $p->nom_produit }} <span class="text-muted font-normal">× {{ \App\Support\Quantite::formater($p->quantite) }}</span></span>
                        <span class="font-extrabold text-accent tabular-nums">{{ $m($p->total) }}</span>
                    </div>
                @empty
                    <x-charte.etat-vide icone="star" titre="Aucune vente aujourd’hui." class="!py-4" />
                @endforelse
            </div>
        </x-charte.carte>

        <x-charte.carte class="p-5 flex flex-col gap-2">
            <x-charte.en-tete-section icone="inventory_2" titre="Alertes stock">
                <x-slot:action><a href="{{ route('stocks.index') }}" class="text-sm font-bold text-accent no-underline hover:underline">Tout voir ›</a></x-slot:action>
            </x-charte.en-tete-section>
            <div>
                @forelse ($alertes as $produit)
                    <div class="flex items-center gap-3 py-2 border-b border-separateur last:border-b-0">
                        <x-charte.pastille :icone="$produit->estEnRupture() ? 'remove_shopping_cart' : 'trending_down'" :danger="$produit->estEnRupture()" :taille="36" />
                        <span class="flex-1 min-w-0 flex flex-col">
                            <span class="text-sm font-bold text-ink truncate">{{ $produit->nom }}</span>
                            <span class="text-xs text-muted">{{ \App\Support\Quantite::formaterStock($produit->stock, $produit->unite, $produit->paliers) }} restant(s) · seuil {{ \App\Support\Quantite::formaterStock($produit->seuil_alerte, $produit->unite, $produit->paliers) }}</span>
                        </span>
                        <x-charte.puce :ton="$produit->estEnRupture() ? 'danger' : 'warn'">{{ $produit->estEnRupture() ? 'Rupture' : 'Stock bas' }}</x-charte.puce>
                    </div>
                @empty
                    <x-charte.etat-vide icone="verified" titre="Aucune alerte." class="!py-4" />
                @endforelse
            </div>
        </x-charte.carte>
    </div>
</div>
