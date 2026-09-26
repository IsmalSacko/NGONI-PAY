<div class="flex flex-col gap-5">
    <div class="flex items-end justify-between gap-4">
        <div>
            <h1 class="font-display font-extrabold text-3xl">Demandes d’abonnement</h1>
            <p class="text-sm text-muted">Approuvez une fois le paiement constaté. Le plan ne s’active qu’à ce moment.</p>
        </div>
        <select wire:model.live="filtre" class="h-11 px-3 rounded-xl border border-border-strong bg-white">
            <option value="en_attente">En attente</option>
            <option value="toutes">Toutes</option>
        </select>
    </div>

    @if ($info)<p class="rounded-xl bg-accent-soft text-accent-dark px-4 py-3 text-sm font-semibold">{{ $info }}</p>@endif
    @if ($alerte)<p class="rounded-xl bg-danger-bg text-danger-fg px-4 py-3 text-sm font-semibold">{{ $alerte }}</p>@endif

    @forelse ($demandes as $demande)
        <div class="bg-white border border-border rounded-2xl p-4" wire:key="demande-{{ $demande->id }}">
            <div class="flex items-start justify-between gap-4 flex-wrap">
                <div class="text-sm">
                    <p class="font-bold text-base">
                        {{ $demande->boutique?->nom ?? '—' }} · {{ ucfirst($demande->plan) }} {{ strtolower($demande->cycle->libelle()) }}
                        · {{ number_format($demande->montant, 0, ',', ' ') }} {{ $demande->devise }}
                    </p>
                    <p class="text-muted flex flex-wrap gap-x-2">
                        {{ $demande->proprietaire?->name }} ·
                        <x-telephone :numero="$demande->telephone_contact ?: $demande->proprietaire?->phone" />
                        · {{ $demande->created_at->diffForHumans() }}
                    </p>
                    <p class="mt-1">
                        Abonnement actuel : <x-statut-abonnement :abonnement="$demande->proprietaire?->abonnement" />
                        @if ($demande->moyen) · moyen annoncé : {{ $demande->moyen }} @endif
                    </p>
                    @if ($demande->note)<p class="mt-2 rounded-lg bg-[#F7F5F0] px-3 py-2">{{ $demande->note }}</p>@endif
                    @if ($demande->preuve_note)<p class="mt-2 rounded-lg bg-[#F7F5F0] px-3 py-2"><span class="font-bold">SMS / preuve :</span> {{ $demande->preuve_note }}</p>@endif
                    @if ($demande->preuve_chemin)
                        <a href="{{ route('plateforme.preuve', $demande) }}" target="_blank" class="mt-2 inline-block font-bold text-accent underline">Voir la preuve de paiement</a>
                    @endif
                    @if ($demande->statut->estTranchee())
                        <p class="mt-2 text-muted">{{ $demande->statut->libelle() }} {{ $demande->decide_le?->format('d/m/Y H:i') }}
                            @if ($demande->note_decision) — {{ $demande->note_decision }} @endif</p>
                    @endif
                </div>

                @unless ($demande->statut->estTranchee())
                    <div class="flex gap-2">
                        <button wire:click="approuver({{ $demande->id }})" wire:confirm="Le paiement a bien été reçu ? Le plan s’active immédiatement."
                                class="h-10 px-4 rounded-lg bg-accent text-white font-bold text-sm">Approuver</button>
                        <button wire:click="demanderRefus({{ $demande->id }})" class="h-10 px-4 rounded-lg border border-border-strong font-bold text-sm text-danger-fg">Refuser</button>
                    </div>
                @endunless
            </div>

            @if ($refusEnCours === $demande->id)
                <form wire:submit="refuser" class="mt-4 pt-4 border-t border-border flex gap-3 items-start">
                    <div class="flex-grow">
                        <input wire:model="motif" type="text" placeholder="Motif visible par le commerçant (ex. paiement introuvable)"
                               class="w-full h-11 px-3 rounded-lg border border-border-strong">
                        @error('motif') <p class="text-xs text-danger-fg mt-1">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" class="h-11 px-4 rounded-lg bg-danger-fg text-white font-bold">Confirmer le refus</button>
                    <button type="button" wire:click="$set('refusEnCours', null)" class="h-11 px-4 rounded-lg border border-border-strong font-bold">Annuler</button>
                </form>
            @endif
        </div>
    @empty
        <p class="text-muted">Aucune demande.</p>
    @endforelse

    {{ $demandes->links() }}
</div>
