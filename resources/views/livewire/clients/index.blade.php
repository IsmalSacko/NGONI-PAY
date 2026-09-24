<div class="flex flex-col gap-5">
    <div class="flex items-end justify-between gap-4">
        <div class="flex flex-col gap-1">
            <h1 class="font-display font-extrabold text-3xl">Clients</h1>
            <span class="text-[--color-muted] text-sm">Programme de fidélité de la boutique.</span>
        </div>
        @can('clients.create')
            <button wire:click="nouveauClient" class="h-12 px-5 rounded-xl bg-accent text-white font-bold">+ Nouveau client</button>
        @endcan
    </div>

    <input wire:model.live.debounce.300ms="recherche" type="text" placeholder="Rechercher un client…"
           class="w-96 h-12 px-4 rounded-xl border border-[--color-border-strong] focus:outline-none focus:ring-2 focus:ring-accent">

    <section class="bg-white border border-[--color-border] rounded-2xl overflow-hidden">
        <div class="grid grid-cols-[2fr_1fr_1fr_1fr] gap-3 px-5 py-3 bg-[#F7F5F0] border-b border-[--color-border] text-xs font-bold text-[--color-muted] uppercase">
            <span>Nom</span><span>Téléphone</span><span class="text-right">Points fidélité</span><span></span>
        </div>
        @forelse ($clients as $client)
            <div class="grid grid-cols-[2fr_1fr_1fr_1fr] gap-3 px-5 py-3 border-b border-[#EEEAE1] items-center text-sm">
                <span class="font-bold">{{ $client->nom }}</span>
                <span>{{ $client->telephone ?: '—' }}</span>
                <span class="text-right font-bold">{{ number_format($client->points_fidelite, 0, ',', ' ') }}</span>
                <div class="flex gap-2 justify-end">
                    @can('clients.update')
                        <button wire:click="modifier('{{ $client->id }}')" class="h-9 px-3 rounded-lg border border-[--color-border-strong] text-xs font-bold">Modifier</button>
                    @endcan
                    @can('clients.delete')
                        <button wire:click="supprimer('{{ $client->id }}')" wire:confirm="Supprimer ce client ?" class="h-9 px-3 rounded-lg border border-[--color-border-strong] text-xs font-bold text-danger-fg">Suppr.</button>
                    @endcan
                </div>
            </div>
        @empty
            <p class="text-sm text-[--color-muted] p-5">Aucun client pour l'instant.</p>
        @endforelse
    </section>

    {{ $clients->links() }}

    @if ($modaleOuverte)
        <div class="fixed inset-0 bg-black/40 flex items-center justify-center z-50" wire:click.self="$set('modaleOuverte', false)">
            <div class="bg-white rounded-2xl p-6 w-full max-w-sm flex flex-col gap-4">
                <h2 class="font-display font-extrabold text-xl">{{ $clientId ? 'Modifier le client' : 'Nouveau client' }}</h2>
                <form wire:submit="enregistrer" class="flex flex-col gap-3">
                    <div>
                        <label class="block text-sm font-semibold mb-1">Nom</label>
                        <input wire:model="nom" type="text" class="w-full h-11 px-3 rounded-lg border border-[--color-border-strong]">
                        @error('nom') <p class="text-sm text-danger-fg mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-semibold mb-1">Téléphone</label>
                        <input wire:model="telephone" type="text" class="w-full h-11 px-3 rounded-lg border border-[--color-border-strong]">
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
