<div class="flex flex-col gap-5">
    <div class="flex items-end justify-between gap-4">
        <div class="flex flex-col gap-1">
            <h1 class="font-display font-extrabold text-3xl">Équipe</h1>
            <span class="text-[--color-muted] text-sm">Comptes ayant accès à la boutique.</span>
        </div>
        <button wire:click="nouveauCompte" class="h-12 px-5 rounded-xl bg-accent text-white font-bold">+ Nouveau compte</button>
    </div>

    @if ($info)
        <p class="rounded-xl bg-accent-soft text-[#0B4F39] px-4 py-3 text-sm font-semibold">{{ $info }}</p>
    @endif
    @if ($alerte)
        <p class="rounded-xl bg-[--color-danger-bg] text-[--color-danger-fg] px-4 py-3 text-sm font-semibold">{{ $alerte }}</p>
    @endif

    <section class="bg-white border border-[--color-border] rounded-2xl overflow-hidden">
        <div class="grid grid-cols-[2fr_1fr_1fr_1fr_220px] gap-3 px-5 py-3 bg-[#F7F5F0] border-b border-[--color-border] text-xs font-bold text-[--color-muted] uppercase">
            <span>Nom</span><span>Téléphone</span><span>Rôle</span><span>Statut</span><span></span>
        </div>
        @foreach ($membres as $membre)
            <div class="grid grid-cols-[2fr_1fr_1fr_1fr_220px] gap-3 px-5 py-3 border-b border-[#EEEAE1] items-center text-sm">
                <span class="font-bold">
                    {{ $membre->name }}
                    @if ($membre->id === $proprietaireId)
                        <span class="ml-1 text-xs font-bold text-[--color-muted]">(propriétaire)</span>
                    @endif
                </span>
                <span>{{ $membre->phone }}</span>
                <span>{{ $membre->roles->pluck('name')->map(fn ($r) => ['admin' => 'Admin', 'gerant' => 'Gérant', 'caissier' => 'Caissier'][$r] ?? $r)->join(', ') ?: '—' }}</span>
                <span>
                    @if ($membre->is_active)
                        <span class="text-xs font-bold rounded px-2 py-1 bg-accent-soft text-[#0B4F39]">Actif</span>
                    @else
                        <span class="text-xs font-bold rounded px-2 py-1 bg-[#F1EDE4] text-[--color-muted]">Désactivé</span>
                    @endif
                </span>
                @if ($membre->id !== auth()->id() && $membre->id !== $proprietaireId)
                    <div class="flex gap-2 justify-self-end">
                        <button wire:click="retirer('{{ $membre->id }}')" wire:confirm="Retirer {{ $membre->name }} de l’équipe de cette boutique ?"
                                class="h-9 px-3 rounded-lg border border-[--color-border-strong] text-xs font-bold">Retirer</button>
                        <button wire:click="basculerActivation('{{ $membre->id }}')" class="h-9 px-3 rounded-lg border border-[--color-border-strong] text-xs font-bold">
                            {{ $membre->is_active ? 'Désactiver' : 'Réactiver' }}
                        </button>
                    </div>
                @else
                    <span></span>
                @endif
            </div>
        @endforeach
    </section>

    @if ($modaleOuverte)
        <div class="fixed inset-0 bg-black/40 flex items-center justify-center z-50" wire:click.self="$set('modaleOuverte', false)">
            <div class="bg-white rounded-2xl p-6 w-full max-w-sm flex flex-col gap-4">
                <h2 class="font-display font-extrabold text-xl">Nouveau compte</h2>
                <form wire:submit="enregistrer" class="flex flex-col gap-3">
                    <div>
                        <label class="block text-sm font-semibold mb-1">Nom</label>
                        <input wire:model="name" type="text" class="w-full h-11 px-3 rounded-lg border border-[--color-border-strong]">
                        @error('name') <p class="text-sm text-danger-fg mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-semibold mb-1">Téléphone</label>
                        <input wire:model="telephone" type="text" class="w-full h-11 px-3 rounded-lg border border-[--color-border-strong]">
                        @error('telephone') <p class="text-sm text-danger-fg mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-semibold mb-1">Mot de passe <span class="font-normal text-[--color-muted]">(inutile si le numéro a déjà un compte)</span></label>
                        <input wire:model="password" type="password" class="w-full h-11 px-3 rounded-lg border border-[--color-border-strong]">
                        @error('password') <p class="text-sm text-danger-fg mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-semibold mb-1">Rôle</label>
                        <select wire:model="role" class="w-full h-11 px-3 rounded-lg border border-[--color-border-strong]">
                            <option value="gerant">Gérant</option>
                            <option value="caissier">Caissier</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <div class="flex gap-3 mt-2">
                        <button type="button" wire:click="$set('modaleOuverte', false)" class="flex-1 h-11 rounded-lg border border-[--color-border-strong] font-bold">Annuler</button>
                        <button type="submit" class="flex-1 h-11 rounded-lg bg-accent text-white font-bold">Créer</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
