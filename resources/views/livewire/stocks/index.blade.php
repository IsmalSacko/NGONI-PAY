<div class="flex flex-col gap-5" wire:poll.visible.30s>
    {{-- Même habit que l'écran Stocks de l'application : titre, carte héros, recherche, puces, cartes. --}}
    <div class="flex flex-col gap-1">
        <h1 class="font-display font-extrabold text-2xl md:text-3xl text-accent">Stocks &amp; inventaire</h1>
        <span class="text-muted text-sm">Ajustez le stock à la suite d’un inventaire, d’une casse ou d’un réassort.</span>
    </div>

    @if ($valeur)
        <x-charte.carte-heros icone="inventory_2" titre="Votre stock">
            <x-slot:pied>
                <div class="grid grid-cols-3 gap-3">
                    <div class="flex flex-col min-w-0">
                        <span class="text-white/70 text-sm font-semibold">Prix d’achat</span>
                        <span class="font-display font-extrabold text-xl md:text-2xl tabular-nums truncate">{{ \App\Support\Money\Montant::format($valeur['achat']) }}</span>
                    </div>
                    <div class="flex flex-col min-w-0">
                        <span class="text-white/70 text-sm font-semibold">Prix de vente</span>
                        <span class="font-display font-extrabold text-xl md:text-2xl tabular-nums truncate">{{ \App\Support\Money\Montant::format($valeur['vente']) }}</span>
                    </div>
                    <div class="flex flex-col min-w-0 {{ $valeur['benefice'] < 0 ? 'text-[#ffb4a9]' : 'text-jaune' }}">
                        <span class="text-white/70 text-sm font-semibold">Bénéfice prévu</span>
                        <span class="font-display font-extrabold text-xl md:text-2xl tabular-nums truncate">{{ \App\Support\Money\Montant::format($valeur['benefice']) }}</span>
                        @if ($valeur['taux'] !== null)<span class="text-sm font-bold">{{ $valeur['taux'] }} %</span>@endif
                    </div>
                </div>
                @if ($valeur['sans_prix_achat'] > 0)
                    <p class="mt-3 text-xs text-white/70">* {{ $valeur['sans_prix_achat'] }} article{{ $valeur['sans_prix_achat'] > 1 ? 's' : '' }} sans prix d’achat : compté{{ $valeur['sans_prix_achat'] > 1 ? 's' : '' }} au prix de vente, sans bénéfice.</p>
                @endif
            </x-slot:pied>
        </x-charte.carte-heros>
    @endif

    <div class="flex flex-col gap-3">
        <label class="flex items-center gap-2 h-12 px-4 rounded-2xl bg-white shadow-carte focus-within:ring-2 focus-within:ring-accent">
            <x-charte.icone nom="search" class="text-muted" />
            <input wire:model.live.debounce.300ms="recherche" type="text" placeholder="{{ $pharmacie ? 'Rechercher : nom, molécule, code-barres…' : 'Rechercher : nom, code-barres…' }}"
                   class="flex-1 min-w-0 bg-transparent outline-none">
        </label>
        <div class="flex flex-wrap gap-2">
            @foreach (['tous' => ['Tous', $nTous], 'bas' => ['Stock bas', $nBas], 'rupture' => ['Ruptures', $nRupture]] + ($pharmacie ? ['peremption' => ['Péremption', null]] : []) as $cle => [$label, $nombre])
                <button wire:click="$set('filtre', '{{ $cle }}')"
                        class="inline-flex items-center gap-1.5 h-10 px-4 rounded-full text-sm font-bold {{ $filtre === $cle ? 'bg-jaune text-accent' : 'bg-puce text-accent hover:bg-accent-soft' }}">
                    @if ($filtre === $cle)<x-charte.icone nom="check" :taille="18" />@endif
                    {{ $label }}@if ($nombre !== null) <span class="opacity-70">{{ $nombre }}</span>@endif
                </button>
            @endforeach
        </div>
    </div>

    @if ($filtre === 'peremption' && $pharmacie)
        {{-- Pharmacie : ce qui périme, avec ce qu'il en reste et ce que ça a coûté. --}}
        <x-charte.carte class="overflow-hidden">
            @forelse ($lots as $lot)
                @php($jours = (int) now()->startOfDay()->diffInDays($lot->peremption, false))
                <div class="flex flex-wrap items-center gap-3 px-4 py-3 border-b border-separateur last:border-b-0">
                    <x-charte.pastille :icone="$jours < 0 ? 'dangerous' : 'event'" :danger="$jours < 0" :taille="40" />
                    <div class="flex-1 min-w-0 flex flex-col">
                        <span class="font-bold text-ink truncate">{{ $lot->produit->nom }}</span>
                        <span class="text-xs text-muted">{{ $lot->numero ? 'Lot '.$lot->numero.' · ' : '' }}{{ $jours < 0 ? 'périmé le' : 'périme le' }} {{ $lot->peremption->format('d/m/Y') }} · reste {{ \App\Support\Quantite::formaterStock($lot->quantite, $lot->produit->unite, $lot->produit->paliers) }}</span>
                    </div>
                    <div class="flex flex-col items-end gap-1">
                        <x-charte.puce :ton="$jours < 0 || $jours <= 30 ? 'danger' : 'warn'">{{ $jours < 0 ? 'Périmé' : 'Dans '.$jours.' j' }}</x-charte.puce>
                        <span class="font-extrabold text-accent tabular-nums">{{ \App\Support\Money\Montant::format((int) round($lot->quantite * ($lot->produit->prix_achat ?? $lot->produit->prix_vente))) }}</span>
                    </div>
                </div>
            @empty
                <x-charte.etat-vide icone="verified" titre="Aucun lot ne périme dans les 90 jours." />
            @endforelse
        </x-charte.carte>
    @else
        <x-charte.carte class="overflow-hidden">
            @forelse ($produits as $produit)
                {{-- Une ligne par article : vignette, nom et prix, l'état du stock en couleur, Ajuster. --}}
                <div class="flex items-center gap-3 px-4 py-3 border-b border-separateur last:border-b-0" wire:key="stock-{{ $produit->id }}">
                    @if ($produit->photo_url)
                        <img src="{{ $produit->vignette_url }}" alt="" loading="lazy" class="w-12 h-12 rounded-xl object-contain bg-white shadow-carte shrink-0">
                    @else
                        <span class="w-12 h-12 shrink-0 rounded-xl bg-accent-soft text-accent font-extrabold text-sm flex items-center justify-center">{{ mb_strtoupper(mb_substr($produit->nom, 0, 2)) }}</span>
                    @endif
                    <div class="flex-1 min-w-0 flex flex-col">
                        <span class="font-bold text-ink truncate">{{ $produit->nom }}@if ($produit->format) <span class="font-normal text-muted">· {{ $produit->format }}</span>@endif</span>
                        <span class="text-xs text-muted truncate">{{ \App\Support\Money\Montant::format($produit->prix_vente) }}@if ($produit->unite) / {{ \App\Support\Quantite::symbole($produit->unite) }}@endif · seuil {{ \App\Support\Quantite::formaterStock($produit->seuil_alerte, $produit->unite, $produit->paliers) }}@if ($produit->categorie) · {{ $produit->categorie->nom }}@endif</span>
                    </div>
                    @php($stockLisible = \App\Support\Quantite::formaterStock($produit->stock, $produit->unite, $produit->paliers))
                    @if ($produit->estEnRupture())
                        <x-charte.puce ton="danger" icone="remove_shopping_cart">Rupture</x-charte.puce>
                    @elseif ($produit->stockFaible())
                        <x-charte.puce ton="warn" icone="trending_down">Stock bas ({{ $stockLisible }})</x-charte.puce>
                    @else
                        <x-charte.puce ton="succes" icone="check_circle">En stock ({{ $stockLisible }})</x-charte.puce>
                    @endif
                    @can('stocks.update')
                        <button wire:click="ouvrirAjustement('{{ $produit->id }}')" class="shrink-0 h-10 px-4 rounded-xl bg-accent-soft text-accent text-sm font-bold hover:bg-puce">Ajuster</button>
                    @endcan
                </div>
            @empty
                <x-charte.etat-vide icone="filter_alt_off" :titre="trim($recherche) === '' ? 'Aucun article dans ce filtre.' : 'Aucun article ne correspond à « '.trim($recherche).' ».'" />
            @endforelse
            <div class="border-t border-separateur">
                <x-charte.pagination :pages="$produits" mot="articles" />
            </div>
        </x-charte.carte>
    @endif

    @if ($ajustementProduitId)
        <div class="fixed inset-0 bg-black/40 flex items-end md:items-center justify-center z-50 md:p-4" wire:click.self="fermerAjustement">
            <div class="bg-paper rounded-t-[24px] md:rounded-[24px] p-6 w-full md:max-w-sm max-h-[90vh] overflow-y-auto flex flex-col gap-4 shadow-carte-haute">
                <x-charte.en-tete-section icone="inventory" titre="Ajuster le stock" sous-titre="Inventaire, casse, périmé détruit, réassort…" />
                <form wire:submit="enregistrerAjustement" class="flex flex-col gap-3">
                    <div>
                        <label class="block text-sm font-semibold mb-1">Nouveau stock</label>
                        <input wire:model="nouveauStock" type="text" inputmode="decimal" class="w-full h-11 px-3 rounded-xl bg-white border border-border-strong">
                        @error('nouveauStock') <p class="text-sm text-danger-fg mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-semibold mb-1">Motif</label>
                        <input wire:model="motif" type="text" placeholder="Inventaire, casse, réassort…" class="w-full h-11 px-3 rounded-xl bg-white border border-border-strong">
                    </div>
                    <div class="flex gap-3 mt-2">
                        <button type="button" wire:click="fermerAjustement" class="flex-1 h-11 rounded-xl bg-white text-accent shadow-carte font-bold">Annuler</button>
                        <button type="submit" class="flex-1 h-11 rounded-xl bg-accent text-white font-bold">Enregistrer</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
