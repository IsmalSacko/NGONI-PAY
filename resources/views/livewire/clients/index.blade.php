<div class="flex flex-col gap-5" wire:poll.visible.30s>
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="flex flex-col gap-1">
            <h1 class="font-display font-extrabold text-2xl md:text-3xl">Clients</h1>
            <span class="text-[--color-muted] text-sm">Programme de fidélité de la boutique.</span>
        </div>
        @can('clients.create')
            <button wire:click="nouveauClient" class="h-12 px-5 rounded-xl bg-accent text-white font-bold">+ Nouveau client</button>
        @endcan
    </div>

    <input wire:model.live.debounce.300ms="recherche" type="text" placeholder="Rechercher un client…"
           class="w-full md:w-96 h-12 px-4 rounded-xl border border-[--color-border-strong] focus:outline-none focus:ring-2 focus:ring-accent">

    <section class="bg-white border border-[--color-border] rounded-2xl overflow-hidden">
        <div class="hidden md:grid grid-cols-[2fr_1fr_1fr_1fr] gap-3 px-5 py-3 bg-[#F7F5F0] border-b border-[--color-border] text-xs font-bold text-[--color-muted] uppercase">
            <span>Nom</span><span>Téléphone</span><span class="text-right">Doit</span><span></span>
        </div>
        @forelse ($clients as $client)
            <div class="grid grid-cols-[1fr_auto] md:grid-cols-[2fr_1fr_1fr_1fr] gap-x-3 gap-y-2 px-5 py-3 border-b border-[#EEEAE1] items-center text-sm">
                <span class="font-bold">{{ $client->nom }}</span>
                <span class="text-right md:text-left">{{ $client->telephone ?: '—' }}</span>
                @php($doit = max(0, (int) $client->credit_total - (int) $client->reglements_total))
                <span class="md:text-right font-bold {{ $doit > 0 ? 'text-danger-fg' : 'text-[--color-muted]' }}">
                    <span class="md:hidden font-normal">Doit </span>{{ $doit > 0 ? \App\Support\Money\Montant::format($doit) : '—' }}
                </span>
                <div class="flex gap-2 justify-end">
                    @if ($doit > 0)
                        @can('ventes.create')
                            <button wire:click="ouvrirReglement('{{ $client->id }}')" class="h-9 px-3 rounded-lg bg-accent text-white text-xs font-bold">Règlement</button>
                        @endcan
                    @endif
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
        <div class="fixed inset-0 bg-black/40 flex items-end md:items-center justify-center z-50 md:p-4" wire:click.self="$set('modaleOuverte', false)">
            <div class="bg-white rounded-t-2xl md:rounded-2xl p-6 w-full md:max-w-sm max-h-[90vh] overflow-y-auto flex flex-col gap-4">
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

    @if ($reglementClientId)
        <div class="fixed inset-0 bg-black/40 flex items-end md:items-center justify-center z-50 md:p-4" wire:click.self="$set('reglementClientId', null)">
            <form wire:submit="enregistrerReglement" class="bg-white rounded-t-2xl md:rounded-2xl p-6 w-full md:max-w-sm flex flex-col gap-3">
                <h2 class="font-display font-extrabold text-xl">Règlement de crédit</h2>
                <label class="text-sm font-semibold">Montant reçu
                    <input wire:model="reglementMontant" type="text" inputmode="decimal" class="mt-1 w-full h-11 px-3 rounded-lg border border-[--color-border-strong] font-normal">
                </label>
                @error('reglementMontant') <p class="text-sm text-danger-fg">{{ $message }}</p> @enderror
                <label class="text-sm font-semibold">Payé par
                    <select wire:model="reglementMoyen" class="mt-1 w-full h-11 px-3 rounded-lg border border-[--color-border-strong] bg-white font-normal">
                        @foreach (['especes' => 'Espèces', 'orange_money' => 'Orange Money', 'moov_money' => 'Moov Money', 'wave' => 'Wave', 'carte' => 'Carte', 'virement' => 'Virement'] as $code => $libelle)
                            <option value="{{ $code }}">{{ $libelle }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="flex gap-3 mt-2">
                    <button type="button" wire:click="$set('reglementClientId', null)" class="flex-1 h-11 rounded-lg border border-[--color-border-strong] font-bold">Annuler</button>
                    <button type="submit" class="flex-1 h-11 rounded-lg bg-accent text-white font-bold">Enregistrer</button>
                </div>
            </form>
        </div>
    @endif
</div>
