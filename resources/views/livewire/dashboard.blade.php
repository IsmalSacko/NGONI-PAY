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
