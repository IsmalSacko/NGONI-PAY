@php($libelles = ['admin' => 'Admin', 'gerant' => 'Gérant', 'caissier' => 'Caissier'])
<div class="flex flex-col gap-5" wire:poll.visible.30s>
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="flex flex-col gap-1">
            <h1 class="font-display font-extrabold text-2xl md:text-3xl">Équipe</h1>
            <span class="text-[--color-muted] text-sm">Comptes ayant accès à la boutique.</span>
        </div>
        @if ($peutAjouter)
            <button wire:click="nouveauCompte" class="h-12 px-5 rounded-xl bg-accent text-white font-bold">+ Nouveau membre</button>
        @endif
    </div>

    <details class="bg-white border border-[--color-border] rounded-2xl px-5 py-3 text-sm">
        <summary class="font-bold cursor-pointer">Qui peut faire quoi ?</summary>
        <ul class="mt-2 flex flex-col gap-1 text-[--color-muted]">
            <li><strong class="text-[--color-ink]">Admin</strong> : tout, y compris l’équipe, les réglages de la boutique et l’abonnement.</li>
            <li><strong class="text-[--color-ink]">Gérant</strong> : caisse, pilotage, catalogue, stocks, clients, toutes les ventes et le back-office. Pas l’équipe ni l’abonnement.</li>
            <li><strong class="text-[--color-ink]">Caissier</strong> : l’application seulement — encaisser, ses propres ventes, ajouter un client. Pas de back-office.</li>
        </ul>
    </details>

    @if ($info)
        <p class="rounded-xl bg-accent-soft text-accent-dark px-4 py-3 text-sm font-semibold">{{ $info }}</p>
    @endif
    @if ($motDePasseProvisoire)
        <div class="rounded-xl border-2 border-accent bg-white px-4 py-3 text-sm flex flex-col gap-2">
            <span>Mot de passe provisoire, affiché une seule fois :</span>
            <code class="text-lg font-bold tracking-wider">{{ $motDePasseProvisoire }}</code>
            @if ($lienWhatsApp)
                <a href="{{ $lienWhatsApp }}" target="_blank" rel="noopener" class="self-start h-10 px-4 rounded-lg bg-whatsapp text-white font-bold inline-flex items-center">Envoyer par WhatsApp</a>
            @endif
        </div>
    @endif
    @if ($alerte)
        <p class="rounded-xl bg-danger-bg text-danger-fg px-4 py-3 text-sm font-semibold">{{ $alerte }}</p>
    @endif

    <section class="bg-white border border-[--color-border] rounded-2xl overflow-hidden">
        <div class="hidden md:grid grid-cols-[2fr_1.3fr_1fr_1fr_200px] gap-3 px-5 py-3 bg-fond-tableau border-b border-[--color-border] text-xs font-bold text-[--color-muted] uppercase">
            <span>Nom</span><span>Téléphone</span><span>Rôle</span><span>Statut</span><span></span>
        </div>
        @foreach ($membres as $membre)
            @php($role = $membre->roles->first()?->name)
            @php($modifiable = $peutGerer && $membre->id !== auth()->id() && $membre->id !== $proprietaireId)
            <div class="flex flex-col gap-2 md:grid md:grid-cols-[2fr_1.3fr_1fr_1fr_200px] md:gap-3 px-5 py-4 md:py-3 border-b border-separateur md:items-center text-sm" wire:key="membre-{{ $membre->id }}">
                <span class="font-bold">
                    {{ $membre->name }}
                    @if ($membre->id === $proprietaireId)
                        <span class="ml-1 text-xs font-bold text-[--color-muted]">(propriétaire)</span>
                    @endif
                </span>
                <span><x-telephone :numero="$membre->phone" /></span>
                <span>
                    @if ($modifiable)
                        <select wire:change="changerRole('{{ $membre->id }}', $event.target.value)" class="h-9 px-2 rounded-lg border border-[--color-border-strong] bg-white">
                            @foreach ($libelles as $code => $libelle)
                                <option value="{{ $code }}" @selected($role === $code)>{{ $libelle }}</option>
                            @endforeach
                        </select>
                    @else
                        {{ $libelles[$role] ?? '—' }}
                    @endif
                </span>
                <span>
                    @if ($membre->is_active)
                        <span class="text-xs font-bold rounded px-2 py-1 bg-accent-soft text-accent-dark">Actif</span>
                    @else
                        <span class="text-xs font-bold rounded px-2 py-1 bg-puce text-[--color-muted]">Désactivé</span>
                    @endif
                </span>
                @if ($modifiable)
                    <div class="flex gap-2 md:justify-self-end">
                        <button wire:click="retirer('{{ $membre->id }}')" wire:confirm="Retirer {{ $membre->name }} de l’équipe de cette boutique ?"
                                class="h-9 px-3 rounded-lg border border-[--color-border-strong] text-xs font-bold">Retirer</button>
                        <button wire:click="basculerActivation('{{ $membre->id }}')" class="h-9 px-3 rounded-lg border border-[--color-border-strong] text-xs font-bold">
                            {{ $membre->is_active ? 'Désactiver' : 'Réactiver' }}
                        </button>
                    </div>
                @else
                    <span class="hidden md:block"></span>
                @endif
            </div>
        @endforeach
    </section>

    @if ($modaleOuverte)
        <div class="fixed inset-0 bg-black/40 flex items-end md:items-center justify-center z-50 p-0 md:p-4" wire:click.self="$set('modaleOuverte', false)">
            <div class="bg-white rounded-t-2xl md:rounded-2xl p-6 w-full md:max-w-sm flex flex-col gap-4 max-h-[90vh] overflow-y-auto">
                <h2 class="font-display font-extrabold text-xl">Nouveau membre</h2>
                <form wire:submit="enregistrer" class="flex flex-col gap-3">
                    <div>
                        <label class="block text-sm font-semibold mb-1">Nom</label>
                        <input wire:model="name" type="text" class="w-full h-11 px-3 rounded-lg border border-[--color-border-strong]">
                        @error('name') <p class="text-sm text-danger-fg mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-semibold mb-1">Téléphone</label>
                        <input wire:model="telephone" type="tel" class="w-full h-11 px-3 rounded-lg border border-[--color-border-strong]">
                        @error('telephone') <p class="text-sm text-danger-fg mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-semibold mb-1">Mot de passe <span class="font-normal text-[--color-muted]">(facultatif : laissé vide, un mot de passe provisoire est créé)</span></label>
                        <input wire:model="password" type="password" class="w-full h-11 px-3 rounded-lg border border-[--color-border-strong]">
                        @error('password') <p class="text-sm text-danger-fg mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-semibold mb-1">Rôle</label>
                        <select wire:model="role" class="w-full h-11 px-3 rounded-lg border border-[--color-border-strong] bg-white">
                            <option value="caissier">Caissier</option>
                            <option value="gerant">Gérant</option>
                            <option value="admin">Admin</option>
                        </select>
                        @error('role') <p class="text-sm text-danger-fg mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex gap-3 mt-2">
                        <button type="button" wire:click="$set('modaleOuverte', false)" class="flex-1 h-11 rounded-lg border border-[--color-border-strong] font-bold">Annuler</button>
                        <button type="submit" class="flex-1 h-11 rounded-lg bg-accent text-white font-bold">Ajouter</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
