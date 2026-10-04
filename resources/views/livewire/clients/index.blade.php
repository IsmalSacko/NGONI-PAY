<div class="flex flex-col gap-5" wire:poll.visible.30s>
    {{-- Même habit que l'écran Clients de l'application. --}}
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="flex flex-col gap-1">
            <h1 class="font-display font-extrabold text-2xl md:text-3xl text-accent">Clients</h1>
            <span class="text-muted text-sm">Fidélité, ventes à crédit et remboursements.</span>
        </div>
        @can('clients.create')
            <button wire:click="nouveauClient" class="inline-flex items-center gap-2 h-12 px-5 rounded-xl bg-accent text-white font-bold shadow-carte">
                <x-charte.icone nom="person_add" />Nouveau client
            </button>
        @endcan
    </div>

    <x-charte.carte-heros icone="groups" :titre="$encours > 0 ? 'Vos clients vous doivent' : 'Vos clients'" :valeur="$encours > 0 ? '−'.\App\Support\Money\Montant::format($encours) : $nClients.' client'.($nClients > 1 ? 's' : '')">
        @if ($encours > 0)
            <button wire:click="$set('filtre', 'debiteurs')" class="self-start text-[#ffb4a9] hover:underline">{{ $nDebiteurs }} client{{ $nDebiteurs > 1 ? 's' : '' }} sur {{ $nClients }} · voir qui ›</button>
        @else
            <span>Personne ne vous doit rien</span>
        @endif
    </x-charte.carte-heros>

    <div class="flex flex-col gap-3">
        <label class="flex items-center gap-2 h-12 px-4 rounded-2xl bg-white shadow-carte focus-within:ring-2 focus-within:ring-accent">
            <x-charte.icone nom="search" class="text-muted" />
            <input wire:model.live.debounce.300ms="recherche" type="text" placeholder="Rechercher par nom ou téléphone…" class="flex-1 min-w-0 bg-transparent outline-none">
        </label>
        <div class="flex flex-wrap gap-2">
            @foreach (['tous' => ['Tous', 'check', $nClients], 'debiteurs' => ['Ils vous doivent', 'hourglass_bottom', $nDebiteurs]] as $cle => [$label, $icone, $nombre])
                <button wire:click="$set('filtre', '{{ $cle }}')" class="inline-flex items-center gap-1.5 h-10 px-4 rounded-full text-sm font-bold {{ $filtre === $cle ? 'bg-jaune text-accent' : 'bg-puce text-accent hover:bg-accent-soft' }}">
                    <x-charte.icone :nom="$icone" :taille="18" />{{ $label }} <span class="opacity-70">{{ $nombre }}</span>
                </button>
            @endforeach
        </div>
    </div>

    <x-charte.carte class="overflow-hidden">
        @forelse ($clients as $client)
            @php($doit = max(0, (int) $client->credit_total - (int) $client->reglements_total))
            <div class="flex flex-wrap items-center gap-3 px-4 py-3 border-b border-separateur last:border-b-0" wire:key="client-{{ $client->id }}">
                <span class="w-11 h-11 shrink-0 rounded-full bg-jaune-doux text-accent font-extrabold flex items-center justify-center">{{ mb_strtoupper(collect(preg_split('/\s+/', trim($client->nom)))->take(2)->map(fn ($m) => mb_substr($m, 0, 1))->join('')) }}</span>
                <span class="flex-1 min-w-0 flex flex-col">
                    <span class="font-bold text-ink truncate">{{ $client->nom }}</span>
                    <span class="text-xs text-muted truncate">{{ $client->telephone ?: 'Sans téléphone' }}</span>
                </span>
                @if ($doit > 0)
                    <x-charte.puce ton="danger" icone="hourglass_bottom">Doit {{ \App\Support\Money\Montant::format($doit) }}</x-charte.puce>
                @endif
                <span class="flex gap-1">
                    @if ($doit > 0)
                        @can('ventes.create')
                            <button wire:click="ouvrirReglement('{{ $client->id }}')" class="h-9 px-3 rounded-xl bg-accent text-white text-xs font-bold">Remboursement</button>
                        @endcan
                    @endif
                    @can('clients.update')
                        <button wire:click="modifier('{{ $client->id }}')" title="Modifier" class="w-9 h-9 rounded-xl bg-accent-soft text-accent inline-flex items-center justify-center"><x-charte.icone nom="edit" :taille="18" /></button>
                    @endcan
                    @can('clients.delete')
                        <button wire:click="supprimer('{{ $client->id }}')" wire:confirm="Supprimer ce client ?" title="Supprimer" class="w-9 h-9 rounded-xl bg-danger-bg text-danger-fg inline-flex items-center justify-center"><x-charte.icone nom="delete" :taille="18" /></button>
                    @endcan
                </span>
            </div>
        @empty
            <x-charte.etat-vide icone="groups" :titre="$filtre === 'debiteurs' ? 'Personne ne vous doit rien.' : 'Aucun client pour l’instant.'" />
        @endforelse
        <div class="border-t border-separateur">
            <x-charte.pagination :pages="$clients" mot="clients" />
        </div>
    </x-charte.carte>

    @if ($modaleOuverte)
        <div class="fixed inset-0 bg-black/40 flex items-end md:items-center justify-center z-50 md:p-4" wire:click.self="$set('modaleOuverte', false)">
            <div class="bg-paper rounded-t-[24px] md:rounded-[24px] p-6 shadow-carte-haute w-full md:max-w-sm max-h-[90vh] overflow-y-auto flex flex-col gap-4">
                <h2 class="font-display font-extrabold text-xl text-accent">{{ $clientId ? 'Modifier le client' : 'Nouveau client' }}</h2>
                <form wire:submit="enregistrer" class="flex flex-col gap-3">
                    <div>
                        <label class="block text-sm font-semibold mb-1">Nom</label>
                        <input wire:model="nom" type="text" class="w-full h-11 px-3 rounded-xl bg-white border border-border-strong">
                        @error('nom') <p class="text-sm text-danger-fg mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-semibold mb-1">Téléphone</label>
                        <input wire:model="telephone" type="text" class="w-full h-11 px-3 rounded-xl bg-white border border-border-strong">
                    </div>
                    <div class="flex gap-3 mt-2">
                        <button type="button" wire:click="$set('modaleOuverte', false)" class="flex-1 h-11 rounded-xl bg-white border border-border-strong font-bold">Annuler</button>
                        <button type="submit" class="flex-1 h-11 rounded-xl bg-accent text-white font-bold">Enregistrer</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    @if ($reglementClientId)
        <div class="fixed inset-0 bg-black/40 flex items-end md:items-center justify-center z-50 md:p-4" wire:click.self="$set('reglementClientId', null)">
            <form wire:submit="enregistrerReglement" class="bg-paper rounded-t-[24px] md:rounded-[24px] p-6 shadow-carte-haute w-full md:max-w-sm flex flex-col gap-3">
                <h2 class="font-display font-extrabold text-xl text-accent">Règlement de crédit</h2>
                <label class="text-sm font-semibold">Montant reçu
                    <input wire:model="reglementMontant" type="text" inputmode="decimal" class="mt-1 w-full h-11 px-3 rounded-xl bg-white border border-border-strong font-normal">
                </label>
                @error('reglementMontant') <p class="text-sm text-danger-fg">{{ $message }}</p> @enderror
                <label class="text-sm font-semibold">Payé par
                    <select wire:model="reglementMoyen" class="mt-1 w-full h-11 px-3 rounded-xl bg-white border border-border-strong bg-white font-normal">
                        @foreach (['especes' => 'Espèces', 'orange_money' => 'Orange Money', 'moov_money' => 'Moov Money', 'wave' => 'Wave', 'carte' => 'Carte', 'virement' => 'Virement'] as $code => $libelle)
                            <option value="{{ $code }}">{{ $libelle }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="flex gap-3 mt-2">
                    <button type="button" wire:click="$set('reglementClientId', null)" class="flex-1 h-11 rounded-xl bg-white border border-border-strong font-bold">Annuler</button>
                    <button type="submit" class="flex-1 h-11 rounded-xl bg-accent text-white font-bold">Enregistrer</button>
                </div>
            </form>
        </div>
    @endif
</div>
