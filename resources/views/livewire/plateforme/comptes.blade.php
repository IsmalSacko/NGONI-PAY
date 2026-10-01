<div class="flex flex-col gap-5">
    <div class="flex items-end justify-between gap-4 flex-wrap">
        <div>
            <h1 class="font-display font-extrabold text-2xl md:text-3xl">Comptes</h1>
            <p class="text-sm text-muted">Propriétaires, leurs boutiques et leur abonnement.</p>
        </div>
        <div class="flex flex-wrap md:flex-nowrap gap-3 w-full md:w-auto">
            <select wire:model.live="filtre" class="h-11 px-3 rounded-xl border border-border-strong bg-white">
                <option value="">Tous</option>
                <option value="actifs">Actifs</option>
                <option value="expires">Expirés</option>
            </select>
            <input wire:model.live.debounce.300ms="recherche" type="text" placeholder="Nom, téléphone, e-mail, boutique…"
                   class="w-full md:w-80 h-11 px-4 rounded-xl border border-border-strong">
        </div>
    </div>

    @if ($info)
        <p class="rounded-xl bg-accent-soft text-accent-dark px-4 py-3 text-sm font-semibold">{{ $info }}</p>
    @endif

    {{-- Remise à zéro d'une boutique d'essai : l'aperçu d'abord, puis REINITIALISER à taper. --}}
    @if ($aReinitialiser)
        <div class="fixed inset-0 z-50 bg-black/40 flex items-end md:items-center justify-center md:p-4" wire:click.self="annulerReinitialisation">
            <div class="bg-white rounded-t-2xl md:rounded-2xl p-6 w-full md:max-w-lg max-h-[90vh] overflow-y-auto flex flex-col gap-3">
                <h2 class="font-display font-extrabold text-xl text-danger-fg">Réinitialiser {{ $apercu['nom'] ?? '' }} ?</h2>
                <p class="text-sm">Les données d’essai sont effacées pour repartir à zéro. Réglages, logo, programme fidélité, équipe et abonnement sont gardés. Une sauvegarde est gardée sur le serveur.</p>
                <ul class="text-sm rounded-xl bg-danger-bg px-4 py-3 flex flex-col gap-1">
                    <li><strong>{{ $apercu['ventes'] ?? 0 }}</strong> vente{{ ($apercu['ventes'] ?? 0) > 1 ? 's' : '' }}, <strong>{{ $apercu['sessions'] ?? 0 }}</strong> séance{{ ($apercu['sessions'] ?? 0) > 1 ? 's' : '' }} de caisse</li>
                    <li><strong>{{ $apercu['achats'] ?? 0 }}</strong> achat{{ ($apercu['achats'] ?? 0) > 1 ? 's' : '' }}, <strong>{{ $apercu['clients'] ?? 0 }}</strong> client{{ ($apercu['clients'] ?? 0) > 1 ? 's' : '' }}</li>
                    <li>
                        @if ($garderCatalogue)
                            <strong>{{ $apercu['articles'] ?? 0 }}</strong> article{{ ($apercu['articles'] ?? 0) > 1 ? 's' : '' }} gardé{{ ($apercu['articles'] ?? 0) > 1 ? 's' : '' }}, stock remis à 0
                        @else
                            <strong>{{ $apercu['articles'] ?? 0 }}</strong> article{{ ($apercu['articles'] ?? 0) > 1 ? 's' : '' }} et <strong>{{ $apercu['categories'] ?? 0 }}</strong> catégorie{{ ($apercu['categories'] ?? 0) > 1 ? 's' : '' }} effacés
                        @endif
                    </li>
                    <li><strong>{{ $apercu['fournisseurs'] ?? 0 }}</strong> fournisseur{{ ($apercu['fournisseurs'] ?? 0) > 1 ? 's' : '' }} {{ $garderFournisseurs ? 'gardé' : 'effacé' }}{{ ($apercu['fournisseurs'] ?? 0) > 1 ? 's' : '' }}</li>
                </ul>
                <label class="flex items-center gap-2 text-sm"><input wire:model.live="garderCatalogue" type="checkbox"> Garder les articles (le stock repart de 0)</label>
                <label class="flex items-center gap-2 text-sm"><input wire:model.live="garderFournisseurs" type="checkbox"> Garder les fournisseurs</label>
                @if ($apercu['avertissement'] ?? null)
                    <p class="rounded-xl bg-warn-bg text-warn-fg px-4 py-3 text-sm font-semibold">{{ $apercu['avertissement'] }}</p>
                @endif
                <label class="text-sm font-semibold">Tapez <span class="font-mono">REINITIALISER</span> pour confirmer
                    <input wire:model="confirmation" type="text" autocomplete="off" class="mt-1 w-full h-11 px-3 rounded-lg border border-border-strong font-mono">
                </label>
                @error('confirmation') <p class="text-sm text-danger-fg">{{ $message }}</p> @enderror
                <div class="flex gap-3">
                    <button wire:click="annulerReinitialisation" class="flex-1 h-11 rounded-lg border border-border-strong font-bold">Annuler</button>
                    <button wire:click="reinitialiser" wire:loading.attr="disabled" class="flex-1 h-11 rounded-lg bg-danger-fg text-white font-bold disabled:opacity-60">Réinitialiser</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Retour en arrière après une remise à zéro : la sauvegarde la plus récente, puis RESTAURER à taper. --}}
    @if ($aRestaurer)
        <div class="fixed inset-0 z-50 bg-black/40 flex items-end md:items-center justify-center md:p-4" wire:click.self="annulerRestauration">
            <div class="bg-white rounded-t-2xl md:rounded-2xl p-6 w-full md:max-w-lg max-h-[90vh] overflow-y-auto flex flex-col gap-3">
                <h2 class="font-display font-extrabold text-xl">Restaurer {{ $aRestaurerNom }} ?</h2>
                @if ($aRestaurerResume)
                    <p class="text-sm">Les données effacées par la remise à zéro du <strong>{{ $aRestaurerResume['le']->format('d/m/Y à H:i') }}</strong> (par {{ $aRestaurerResume['par'] }}) sont remises en place, et le stock des articles reprend sa valeur d’avant.</p>
                    <ul class="text-sm rounded-xl bg-accent-soft px-4 py-3 flex flex-col gap-1">
                        <li><strong>{{ $aRestaurerResume['ventes'] }}</strong> vente{{ $aRestaurerResume['ventes'] > 1 ? 's' : '' }}, <strong>{{ $aRestaurerResume['clients'] }}</strong> client{{ $aRestaurerResume['clients'] > 1 ? 's' : '' }}, <strong>{{ $aRestaurerResume['achats'] }}</strong> achat{{ $aRestaurerResume['achats'] > 1 ? 's' : '' }}</li>
                        <li><strong>{{ $aRestaurerResume['articles'] }}</strong> article{{ $aRestaurerResume['articles'] > 1 ? 's' : '' }}</li>
                    </ul>
                    <p class="text-xs text-muted">Sauvegarde gardée jusqu’au {{ $aRestaurerResume['expire_le']->format('d/m/Y') }}. S’il y a eu plusieurs remises à zéro, la plus récente se restaure d’abord.</p>
                    <label class="text-sm font-semibold">Tapez <span class="font-mono">RESTAURER</span> pour confirmer
                        <input wire:model="confirmationRestauration" type="text" autocomplete="off" class="mt-1 w-full h-11 px-3 rounded-lg border border-border-strong font-mono">
                    </label>
                    @error('confirmationRestauration') <p class="text-sm text-danger-fg">{{ $message }}</p> @enderror
                    @error('sauvegarde') <p class="text-sm text-danger-fg">{{ $message }}</p> @enderror
                    <div class="flex gap-3">
                        <button wire:click="annulerRestauration" class="flex-1 h-11 rounded-lg border border-border-strong font-bold">Annuler</button>
                        <button wire:click="restaurer" wire:loading.attr="disabled" class="flex-1 h-11 rounded-lg bg-accent text-white font-bold disabled:opacity-60">Restaurer</button>
                    </div>
                @else
                    <p class="text-sm">Aucune sauvegarde restaurable pour cette boutique (elles sont gardées 30 jours).</p>
                    <button wire:click="annulerRestauration" class="h-11 rounded-lg border border-border-strong font-bold">Fermer</button>
                @endif
            </div>
        </div>
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
                        <p class="text-xs text-muted mt-1">
                            Inscrit le {{ $proprietaire?->created_at?->format('d/m/Y à H:i') }}
                            · {{ $proprietaire?->derniere_app ? 'actif dans l’app '.\Illuminate\Support\Carbon::parse($proprietaire->derniere_app)->locale('fr')->diffForHumans() : 'jamais connecté à l’app' }}
                            @if ($proprietaire?->parrain) · parrainé par <span class="font-semibold">{{ $proprietaire->parrain->name }}</span> @endif
                            @if ($abonnement->jours_offerts > 0) · {{ $abonnement->jours_offerts }} j offerts en attente @endif
                        </p>
                        <ul class="mt-2 flex flex-col gap-1 text-sm">
                            @forelse ($proprietaire?->boutiquesPossedees ?? [] as $b)
                                <li>
                                    <span class="font-semibold">{{ $b->nom }}</span>
                                    <span class="text-xs text-muted">
                                        · créée le {{ $b->created_at?->format('d/m/Y') }}
                                        · {{ $b->nb_ventes }} vente{{ $b->nb_ventes > 1 ? 's' : '' }}
                                        @if ($b->derniere_vente) · dernière le {{ \Illuminate\Support\Carbon::parse($b->derniere_vente)->format('d/m/Y') }} @endif
                                        · 30 j : {{ \App\Support\Money\Montant::format((int) $b->total_30j, $b->devise) }} {{ $b->devise }}
                                    </span>
                                    <button wire:click="preparerReinitialisation('{{ $b->id }}')"
                                            class="ml-1 text-xs font-bold text-danger-fg underline">Réinitialiser</button>
                                    @isset($sauvegardes[$b->id])
                                        <button wire:click="preparerRestauration('{{ $b->id }}')"
                                                class="ml-1 text-xs font-bold text-accent underline">Restaurer</button>
                                    @endisset
                                </li>
                            @empty
                                <li class="text-muted">aucune boutique</li>
                            @endforelse
                        </ul>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="rounded-full bg-puce px-2.5 py-0.5 text-xs font-bold">{{ $abonnement->estEssai() ? 'essai' : $abonnement->plan }}</span>
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
