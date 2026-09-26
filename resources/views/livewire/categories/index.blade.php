<div class="flex flex-col gap-5" wire:poll.visible.30s>
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="flex flex-col gap-1">
            <h1 class="font-display font-extrabold text-2xl md:text-3xl">Catégories</h1>
            <span class="text-[--color-muted] text-sm">Organisent la grille de la caisse tactile.</span>
        </div>
        <a href="{{ route('produits.index') }}" class="h-11 px-4 rounded-xl border border-[--color-border-strong] font-bold flex items-center">← Produits</a>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-[2fr_1fr] gap-4">
        <section class="bg-white border border-[--color-border] rounded-2xl overflow-x-auto">
            @forelse ($categories as $categorie)
                <div class="flex items-center gap-3 px-5 py-3 border-b border-[#EEEAE1]">
                    <span class="w-4 h-4 rounded-full shrink-0" style="background: {{ $categorie->couleur ?: '#0B6E4F' }}"></span>
                    <span class="font-bold flex-grow">{{ $categorie->nom }}</span>
                    <span class="text-sm text-[--color-muted]">{{ $categorie->produits_count }} article(s)</span>
                    <button wire:click="modifier('{{ $categorie->id }}')" class="h-9 px-3 rounded-lg border border-[--color-border-strong] text-xs font-bold">Modifier</button>
                    <button wire:click="supprimer('{{ $categorie->id }}')" wire:confirm="Supprimer cette catégorie ?" class="h-9 px-3 rounded-lg border border-[--color-border-strong] text-xs font-bold text-danger-fg">Suppr.</button>
                </div>
            @empty
                <p class="text-sm text-[--color-muted] p-5">Aucune catégorie pour l'instant.</p>
            @endforelse
        </section>

        <section class="bg-white border border-[--color-border] rounded-2xl p-5">
            <h2 class="font-bold text-base mb-3">{{ $categorieId ? 'Modifier' : 'Nouvelle catégorie' }}</h2>
            <form wire:submit="enregistrer" class="flex flex-col gap-3">
                <div>
                    <label class="block text-sm font-semibold mb-1">Nom</label>
                    <input wire:model="nom" type="text" class="w-full h-11 px-3 rounded-lg border border-[--color-border-strong]">
                    @error('nom') <p class="text-sm text-danger-fg mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-semibold mb-1">Couleur</label>
                    <input wire:model="couleur" type="color" class="w-full h-11 rounded-lg border border-[--color-border-strong]">
                </div>
                <div class="flex gap-3">
                    @if ($categorieId)
                        <button type="button" wire:click="annuler" class="flex-1 h-11 rounded-lg border border-[--color-border-strong] font-bold">Annuler</button>
                    @endif
                    <button type="submit" class="flex-1 h-11 rounded-lg bg-accent text-white font-bold">Enregistrer</button>
                </div>
            </form>
        </section>
    </div>
</div>
