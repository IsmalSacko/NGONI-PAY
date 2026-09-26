<div class="flex flex-col gap-5">
    <div class="flex flex-col gap-1">
        <h1 class="font-display font-extrabold text-2xl md:text-3xl">Stocks &amp; inventaire</h1>
        <span class="text-[--color-muted] text-sm">Ajustez le stock à la suite d'un inventaire ou d'un réassort.</span>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div class="bg-white border border-[--color-border] rounded-2xl p-5 flex flex-col gap-1">
            <span class="text-sm font-semibold text-[--color-muted]">Valeur du stock</span>
            <span class="font-display font-extrabold text-2xl">{{ \App\Support\Money\Montant::format($valeurStock) }}</span>
        </div>
        <div class="bg-white border border-[--color-border] rounded-2xl p-5 flex flex-col gap-1">
            <span class="text-sm font-semibold text-warn-fg">Stock bas</span>
            <span class="font-display font-extrabold text-2xl">{{ $nBas }} <span class="text-sm font-normal text-[--color-muted]">article(s)</span></span>
        </div>
        <div class="bg-white border border-[--color-border] rounded-2xl p-5 flex flex-col gap-1">
            <span class="text-sm font-semibold text-danger-fg">Ruptures</span>
            <span class="font-display font-extrabold text-2xl">{{ $nRupture }} <span class="text-sm font-normal text-[--color-muted]">article(s)</span></span>
        </div>
    </div>

    <div class="flex flex-wrap gap-3 items-center">
        <input wire:model.live.debounce.300ms="recherche" type="text" placeholder="Rechercher un article…"
               class="w-full md:w-80 h-12 px-4 rounded-xl border border-[--color-border-strong] focus:outline-none focus:ring-2 focus:ring-accent">
        <div class="flex flex-wrap gap-2">
            @foreach (['tous' => 'Tous', 'bas' => 'Stock bas', 'rupture' => 'Ruptures'] as $valeur => $label)
                <button wire:click="$set('filtre', '{{ $valeur }}')"
                        class="h-11 px-4 rounded-full text-sm font-bold {{ $filtre === $valeur ? 'bg-ink text-white' : 'bg-white border border-[--color-border-strong]' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    <section class="bg-white border border-[--color-border] rounded-2xl overflow-hidden">
        <div class="hidden md:grid grid-cols-[2fr_1fr_1fr_1fr_100px] gap-3 px-5 py-3 bg-[#F7F5F0] border-b border-[--color-border] text-xs font-bold text-[--color-muted] uppercase">
            <span>Article</span><span class="text-right">Stock</span><span class="text-right">Seuil</span><span>Statut</span><span></span>
        </div>
        @forelse ($produits as $produit)
            {{-- Téléphone : une carte par article (nom, stock / seuil, statut, Ajuster). --}}
            <div class="grid grid-cols-[1fr_auto] md:grid-cols-[2fr_1fr_1fr_1fr_100px] gap-x-3 gap-y-1 px-5 py-3 border-b border-[#EEEAE1] items-center text-sm">
                <span class="font-bold">{{ $produit->nom }}</span>
                <span class="text-right font-semibold"><span class="md:hidden text-[--color-muted] font-normal">Stock </span>{{ $produit->stock }}</span>
                <span class="hidden md:block text-right text-[--color-muted]">{{ $produit->seuil_alerte }}</span>
                <span>
                    @if ($produit->estEnRupture())
                        <span class="text-xs font-bold rounded px-2 py-1 bg-danger-bg text-danger-fg">Rupture</span>
                    @elseif ($produit->stockFaible())
                        <span class="text-xs font-bold rounded px-2 py-1 bg-warn-bg text-warn-fg">Stock bas</span>
                    @else
                        <span class="text-xs font-bold rounded px-2 py-1 bg-accent-soft text-[#0B4F39]">En stock</span>
                    @endif
                </span>
                @can('stocks.update')
                    <button wire:click="ouvrirAjustement('{{ $produit->id }}')" class="h-9 px-3 rounded-lg border border-[--color-border-strong] text-xs font-bold justify-self-end">Ajuster</button>
                @endcan
            </div>
        @empty
            <p class="text-sm text-[--color-muted] p-5">Aucun article ne correspond.</p>
        @endforelse
    </section>

    @if ($ajustementProduitId)
        <div class="fixed inset-0 bg-black/40 flex items-end md:items-center justify-center z-50 md:p-4" wire:click.self="fermerAjustement">
            <div class="bg-white rounded-t-2xl md:rounded-2xl p-6 w-full md:max-w-sm max-h-[90vh] overflow-y-auto flex flex-col gap-4">
                <h2 class="font-display font-extrabold text-xl">Ajuster le stock</h2>
                <form wire:submit="enregistrerAjustement" class="flex flex-col gap-3">
                    <div>
                        <label class="block text-sm font-semibold mb-1">Nouveau stock</label>
                        <input wire:model="nouveauStock" type="number" min="0" class="w-full h-11 px-3 rounded-lg border border-[--color-border-strong]">
                        @error('nouveauStock') <p class="text-sm text-danger-fg mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-semibold mb-1">Motif</label>
                        <input wire:model="motif" type="text" placeholder="Inventaire, casse, réassort…" class="w-full h-11 px-3 rounded-lg border border-[--color-border-strong]">
                    </div>
                    <div class="flex gap-3 mt-2">
                        <button type="button" wire:click="fermerAjustement" class="flex-1 h-11 rounded-lg border border-[--color-border-strong] font-bold">Annuler</button>
                        <button type="submit" class="flex-1 h-11 rounded-lg bg-accent text-white font-bold">Enregistrer</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
