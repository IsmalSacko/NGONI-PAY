<div class="flex flex-col gap-5" wire:poll.visible.30s>
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
           class="w-full md:w-96 h-12 px-4 rounded-xl border border-[--color-border-strong] focus:outline-none focus:ring-2 focus:ring-accent">

    <section class="bg-white border border-[--color-border] rounded-2xl overflow-hidden">
        <div class="hidden md:grid grid-cols-[2fr_1fr_1fr_0.8fr_0.8fr_1fr] gap-3 px-5 py-3 bg-fond-tableau border-b border-[--color-border] text-xs font-bold text-[--color-muted] uppercase">
            <span>Article</span><span>Catégorie</span><span class="text-right">Vente</span><span class="text-right">TVA</span><span class="text-right">Stock</span><span></span>
        </div>
        @foreach ($produits as $produit)
            {{-- Téléphone : nom et prix, puis stock et actions. --}}
            <div class="grid grid-cols-[1fr_auto] md:grid-cols-[2fr_1fr_1fr_0.8fr_0.8fr_1fr] gap-x-3 gap-y-2 px-5 py-3 border-b border-separateur items-center text-sm">
                <div class="flex items-center gap-3">
                    @if ($produit->photo_url)
                        <img src="{{ $produit->vignette_url }}" alt="" loading="lazy" class="w-11 h-11 rounded-lg object-contain bg-white border border-border shrink-0">
                    @else
                        <span class="w-11 h-11 rounded-lg bg-puce text-accent text-xs font-extrabold flex items-center justify-center shrink-0">{{ mb_strtoupper($produit->code ?: mb_substr($produit->nom, 0, 2)) }}</span>
                    @endif
                <div class="flex flex-col">
                    <span class="font-bold">{{ $produit->nom }}</span>
                    <span class="text-xs text-[--color-muted]">{{ $produit->format }}</span>
                </div>
                </div>
                <span class="hidden md:block">{{ $produit->categorie?->nom ?? '—' }}</span>
                <span class="text-right font-bold">{{ \App\Support\Money\Montant::format($produit->prix_vente) }}</span>
                <span class="hidden md:block text-right">{{ rtrim(rtrim((string) $produit->taux_tva, '0'), '.') ?: '0' }} %</span>
                <span class="md:text-right font-semibold {{ $produit->estEnRupture() ? 'text-danger-fg' : ($produit->stockFaible() ? 'text-warn-fg' : '') }}"><span class="md:hidden font-normal text-[--color-muted]">Stock </span>{{ $produit->stock }}</span>
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

                    {{-- Photo : montrée sur la tuile de la caisse. Sur téléphone, le
                         sélecteur propose aussi l'appareil photo. --}}
                    @php($photoActuelle = $produitId && ! $retirerPhoto ? \App\Models\Produit::find($produitId)?->photo_url : null)
                    <div class="rounded-xl border-2 border-dashed border-border-strong p-3 flex items-center gap-4">
                        <label class="relative w-24 h-24 shrink-0 rounded-xl bg-fond-tableau border border-border overflow-hidden flex items-center justify-center cursor-pointer">
                            @if ($photo && str_starts_with((string) $photo->getMimeType(), 'image/'))
                                <img src="{{ $photo->temporaryUrl() }}" alt="Nouvelle photo" class="w-full h-full object-contain bg-white">
                            @elseif ($photoActuelle)
                                <img src="{{ $photoActuelle }}" alt="Photo actuelle" class="w-full h-full object-contain bg-white">
                            @else
                                <x-icone nom="photo" class="w-8 h-8 text-muted" />
                            @endif
                            <span wire:loading.flex wire:target="photo" class="absolute inset-0 bg-white/85 items-center justify-center text-xs font-bold text-accent">Envoi…</span>
                            <input type="file" wire:model="photo" accept="image/*" class="sr-only" aria-label="Photo de l’article">
                        </label>
                        <div class="flex flex-col gap-2 min-w-0">
                            <span class="font-bold text-sm">Photo de l’article</span>
                            <span class="text-xs text-muted">Facultative, affichée dans la caisse. JPG, PNG ou WebP, 3 Mo maximum.</span>
                            <div class="flex flex-wrap gap-2">
                                <label class="inline-flex items-center gap-1.5 h-9 px-3 rounded-lg bg-accent text-white text-xs font-bold cursor-pointer">
                                    <x-icone nom="photo" class="w-4 h-4" />
                                    {{ $photo || $photoActuelle ? 'Changer la photo' : 'Ajouter une photo' }}
                                    <input type="file" wire:model="photo" accept="image/*" class="sr-only">
                                </label>
                                @if ($photo || $photoActuelle)
                                    <button type="button" wire:click="retirerLaPhoto" class="h-9 px-3 rounded-lg border border-border-strong text-xs font-bold text-danger-fg">Retirer</button>
                                @endif
                            </div>
                        </div>
                    </div>
                    @error('photo') <p class="text-sm text-danger-fg">{{ $message }}</p> @enderror
                    @php($devise = \App\Support\Money\Montant::deviseActive())
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-semibold mb-1">Prix d’achat <span class="font-normal text-[--color-muted]">({{ $devise }}, facultatif)</span></label>
                            <input wire:model.live.debounce.400ms="prix_achat" type="text" inputmode="decimal" class="w-full h-11 px-3 rounded-lg border border-[--color-border-strong]">
                            @error('prix_achat') <p class="text-sm text-danger-fg mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-1">Prix de vente <span class="font-normal text-[--color-muted]">({{ $devise }})</span></label>
                            <input wire:model.live.debounce.400ms="prix_vente" type="text" inputmode="decimal" class="w-full h-11 px-3 rounded-lg border border-[--color-border-strong]">
                            @error('prix_vente') <p class="text-sm text-danger-fg mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    @if ($marge)
                        <p class="text-sm font-semibold {{ $marge['perte'] ? 'text-danger-fg' : 'text-accent-dark' }}">{{ $marge['texte'] }}</p>
                    @endif

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
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
                        <button type="submit" wire:loading.attr="disabled" wire:target="photo,enregistrer" class="flex-1 h-11 rounded-lg bg-accent text-white font-bold disabled:opacity-60">Enregistrer</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
