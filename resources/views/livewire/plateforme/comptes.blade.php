<div class="flex flex-col gap-5">
    <div class="flex items-end justify-between gap-4 flex-wrap">
        <div>
            <h1 class="font-display font-extrabold text-2xl md:text-3xl">Comptes</h1>
            <p class="text-sm text-muted">Propriétaires, leurs boutiques et leur abonnement.</p>
        </div>
        <div class="flex gap-3">
            <select wire:model.live="filtre" class="h-11 px-3 rounded-xl border border-border-strong bg-white">
                <option value="">Tous</option>
                <option value="actifs">Actifs</option>
                <option value="expires">Expirés</option>
            </select>
            <input wire:model.live.debounce.300ms="recherche" type="text" placeholder="Nom, téléphone, e-mail, boutique…"
                   class="w-80 h-11 px-4 rounded-xl border border-border-strong">
        </div>
    </div>

    @if ($info)
        <p class="rounded-xl bg-accent-soft text-accent-dark px-4 py-3 text-sm font-semibold">{{ $info }}</p>
    @endif

    <div class="flex flex-col gap-3">
        @forelse ($abonnements as $abonnement)
            @php($proprietaire = $abonnement->proprietaire)
            <div class="bg-white border border-border rounded-2xl p-4" wire:key="compte-{{ $abonnement->id }}">
                <div class="flex items-start justify-between gap-4 flex-wrap">
                    <div>
                        <p class="font-bold">{{ $proprietaire?->name ?? '—' }}</p>
                        <p class="text-sm text-muted flex flex-wrap gap-x-2">
                            <x-telephone :numero="$proprietaire?->phone" />
                            @if ($proprietaire?->email) · {{ $proprietaire->email }} @endif
                        </p>
                        <p class="text-sm mt-1">
                            {{ $proprietaire?->boutiquesPossedees->pluck('nom')->join(', ') ?: 'aucune boutique' }}
                        </p>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="rounded-full bg-[#F1EDE4] px-2.5 py-0.5 text-xs font-bold">{{ $abonnement->estEssai() ? 'essai' : $abonnement->plan }}</span>
                        @if ($abonnement->est_manuel)<span class="text-xs text-muted">manuel</span>@endif
                        <x-statut-abonnement :abonnement="$abonnement" />
                        <button wire:click="gerer('{{ $abonnement->user_id }}')" class="h-9 px-3 rounded-lg border border-border-strong text-xs font-bold">Gérer</button>
                        @if ($abonnement->estEnCours())
                            <button wire:click="revoquer('{{ $abonnement->user_id }}')"
                                    wire:confirm="Mettre fin à l’abonnement maintenant ? Ses boutiques passent en lecture seule."
                                    class="h-9 px-3 rounded-lg border border-border-strong text-xs font-bold text-danger-fg">Révoquer</button>
                        @endif
                    </div>
                </div>

                @if ($compteOuvert === $abonnement->user_id)
                    <form wire:submit="accorder" class="mt-4 pt-4 border-t border-border grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
                        <div>
                            <label class="block text-xs font-bold text-muted mb-1">Plan</label>
                            <select wire:model="plan" class="w-full h-11 px-3 rounded-lg border border-border-strong">
                                @foreach ($plans as $p)
                                    <option value="{{ $p->code }}">{{ $p->nom }}{{ $p->estEssai() ? ' (prolonger)' : '' }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-muted mb-1">Fin (incluse)</label>
                            <input wire:model="fin" type="date" @disabled($sansEcheance) class="w-full h-11 px-3 rounded-lg border border-border-strong">
                            @error('fin') <p class="text-xs text-danger-fg mt-1">{{ $message }}</p> @enderror
                        </div>
                        <label class="flex items-center gap-2 text-sm h-11">
                            <input wire:model.live="sansEcheance" type="checkbox"> Sans échéance
                        </label>
                        <div>
                            <label class="block text-xs font-bold text-muted mb-1">Note</label>
                            <input wire:model="note" type="text" class="w-full h-11 px-3 rounded-lg border border-border-strong">
                        </div>
                        <div class="md:col-span-4 flex gap-3">
                            <button type="submit" class="h-11 px-5 rounded-lg bg-accent text-white font-bold">Enregistrer</button>
                            <button type="button" wire:click="$set('compteOuvert', null)" class="h-11 px-5 rounded-lg border border-border-strong font-bold">Annuler</button>
                        </div>
                    </form>
                @endif
            </div>
        @empty
            <p class="text-muted">Aucun compte.</p>
        @endforelse
    </div>

    {{ $abonnements->links() }}
</div>
