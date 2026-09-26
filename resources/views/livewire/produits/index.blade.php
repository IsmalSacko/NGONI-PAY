<div class="flex flex-col gap-5">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="flex flex-col gap-1">
            <h1 class="font-display font-extrabold text-2xl md:text-3xl">Produits</h1>
            <span class="text-[--color-muted] text-sm">Catalogue, prix et TVA de la boutique.</span>
        </div>
        <div class="flex gap-3">
            @can('categories.view')
                <a href="{{ route('categories.index') }}" class="h-12 px-4 rounded-xl border border-[--color-border-strong] font-bold flex items-center">Catégories</a>
            @endcan
            @can('produits.create')
                <button wire:click="nouveauProduit" class="h-12 px-5 rounded-xl bg-accent text-white font-bold">+ Nouvel article</button>
            @endcan
        </div>
    </div>

    <input wire:model.live.debounce.300ms="recherche" type="text" placeholder="Rechercher un article…"
           class="w-96 h-12 px-4 rounded-xl border border-[--color-border-strong] focus:outline-none focus:ring-2 focus:ring-accent">

    <section class="bg-white border border-[--color-border] rounded-2xl overflow-x-auto">
        <div class="grid min-w-[680px] grid-cols-[2fr_1fr_1fr_0.8fr_0.8fr_1fr] gap-3 px-5 py-3 bg-[#F7F5F0] border-b border-[--color-border] text-xs font-bold text-[--color-muted] uppercase">
            <span>Article</span><span>Catégorie</span><span class="text-right">Vente</span><span class="text-right">TVA</span><span class="text-right">Stock</span><span></span>
        </div>
        @foreach ($produits as $produit)
            <div class="grid min-w-[680px] grid-cols-[2fr_1fr_1fr_0.8fr_0.8fr_1fr] gap-3 px-5 py-3 border-b border-[#EEEAE1] items-center text-sm">
                <div class="flex flex-col">
                    <span class="font-bold">{{ $produit->nom }}</span>
                    <span class="text-xs text-[--color-muted]">{{ $produit->format }}</span>
                </div>
                <span>{{ $produit->categorie?->nom ?? '—' }}</span>
                <span class="text-right font-bold">{{ number_format($produit->prix_vente, 0, ',', ' ') }}</span>
                <span class="text-right">{{ rtrim(rtrim((string) $produit->taux_tva, '0'), '.') ?: '0' }} %</span>
                <span class="text-right font-semibold {{ $produit->estEnRupture() ? 'text-danger-fg' : ($produit->stockFaible() ? 'text-warn-fg' : '') }}">{{ $produit->stock }}</span>
                <div class="flex gap-2 justify-end">
                    @can('produits.update')
                        <button wire:click="modifier('{{ $produit->id }}')" class="h-9 px-3 rounded-lg border border-[--color-border-strong] text-xs font-bold">Modifier</button>
                    @endcan
                    @can('produits.delete')
                        <button wire:click="supprimer('{{ $produit->id }}')" wire:confirm="Supprimer cet article ?" class="h-9 px-3 rounded-lg border border-[--color-border-strong] text-xs font-bold text-danger-fg">Suppr.</button>
                    @endcan
                </div>
            </div>
        @endforeach
    </section>

    {{ $produits->links() }}

    @if ($modaleOuverte)
        <div class="fixed inset-0 bg-black/40 flex items-end md:items-center justify-center z-50 md:p-4" wire:click.self="$set('modaleOuverte', false)">
            <div class="bg-white rounded-t-2xl md:rounded-2xl p-6 w-full md:max-w-lg max-h-[90vh] overflow-y-auto flex flex-col gap-4">
                <h2 class="font-display font-extrabold text-xl">{{ $produitId ? 'Modifier l’article' : 'Nouvel article' }}</h2>

                <form wire:submit="enregistrer" class="flex flex-col gap-3">
                    <div>
                        <label class="block text-sm font-semibold mb-1">Nom</label>
                        <input wire:model="nom" type="text" class="w-full h-11 px-3 rounded-lg border border-[--color-border-strong]">
                        @error('nom') <p class="text-sm text-danger-fg mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-semibold mb-1">Format</label>
                            <input wire:model="format" type="text" placeholder="Sac 5 kg" class="w-full h-11 px-3 rounded-lg border border-[--color-border-strong]">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-1">Catégorie</label>
                            <select wire:model="categorie_produit_id" class="w-full h-11 px-3 rounded-lg border border-[--color-border-strong]">
                                <option value="">—</option>
                                @foreach ($categories as $categorie)
                                    <option value="{{ $categorie->id }}">{{ $categorie->nom }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div>
                            <label class="block text-sm font-semibold mb-1">Prix de vente</label>
                            <input wire:model="prix_vente" type="number" min="0" class="w-full h-11 px-3 rounded-lg border border-[--color-border-strong]">
                            @error('prix_vente') <p class="text-sm text-danger-fg mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-1">TVA %</label>
                            <input wire:model="taux_tva" type="number" min="0" max="100" class="w-full h-11 px-3 rounded-lg border border-[--color-border-strong]">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-1">Code</label>
                            <input wire:model="code" type="text" maxlength="4" class="w-full h-11 px-3 rounded-lg border border-[--color-border-strong] uppercase">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div>
                            <label class="block text-sm font-semibold mb-1">Code-barres</label>
                            <input wire:model="code_barre" type="text" class="w-full h-11 px-3 rounded-lg border border-[--color-border-strong]">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-1">Stock</label>
                            <input wire:model="stock" type="number" min="0" class="w-full h-11 px-3 rounded-lg border border-[--color-border-strong]">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-1">Seuil d'alerte</label>
                            <input wire:model="seuil_alerte" type="number" min="0" class="w-full h-11 px-3 rounded-lg border border-[--color-border-strong]">
                        </div>
                    </div>

                    <div class="flex gap-3 mt-2">
                        <button type="button" wire:click="$set('modaleOuverte', false)" class="flex-1 h-11 rounded-lg border border-[--color-border-strong] font-bold">Annuler</button>
                        <button type="submit" class="flex-1 h-11 rounded-lg bg-accent text-white font-bold">Enregistrer</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
