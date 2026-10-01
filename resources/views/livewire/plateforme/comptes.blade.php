@php
    $champ = 'h-11 px-3.5 rounded-xl border border-border-strong bg-white text-sm focus:outline-none focus:border-accent/40 focus:ring-2 focus:ring-accent/30';
@endphp
<div class="flex flex-col gap-5">
    <div class="flex items-end justify-between gap-4 flex-wrap">
        <div>
            <h1 class="font-display font-extrabold text-2xl md:text-3xl">Comptes</h1>
            <p class="text-sm text-muted">Propriétaires, leurs boutiques et leur abonnement.</p>
        </div>
        <div class="flex flex-wrap md:flex-nowrap gap-3 w-full md:w-auto">
            <select wire:model.live="filtre" class="{{ $champ }} pr-8">
                <option value="">Tous</option>
                <option value="actifs">Actifs</option>
                <option value="expires">Expirés</option>
            </select>
            <label class="relative flex-1 md:flex-none">
                <span class="sr-only">Rechercher</span>
                <x-plateforme.picto nom="recherche" class="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-muted pointer-events-none" />
                <input wire:model.live.debounce.300ms="recherche" type="text" placeholder="Nom, téléphone, e-mail, boutique…"
                       class="{{ $champ }} w-full md:w-80 pl-10">
            </label>
        </div>
    </div>

    @if ($info)
        <p class="rounded-xl bg-accent-soft text-accent-dark px-4 py-3 text-sm font-semibold">{{ $info }}</p>
    @endif

    @include('livewire.partials.reinitialisation')

    {{-- Retour en arrière après une remise à zéro : la sauvegarde la plus récente, puis RESTAURER à taper. --}}
    @if ($aRestaurer)
        <div class="fixed inset-0 z-50 bg-accent-dark/40 backdrop-blur-[2px] flex items-end md:items-center justify-center md:p-4" wire:click.self="annulerRestauration">
            <div class="bg-white rounded-t-3xl md:rounded-2xl shadow-carte-haute p-6 w-full md:max-w-lg max-h-[90vh] overflow-y-auto flex flex-col gap-3">
                <div class="flex items-center gap-3">
                    <x-plateforme.icone-chip nom="restaurer" />
                    <h2 class="font-display font-extrabold text-xl">Restaurer {{ $aRestaurerNom }} ?</h2>
                </div>
                @if ($aRestaurerResume)
                    <p class="text-sm">Les données effacées par la remise à zéro du <strong>{{ $aRestaurerResume['le']->format('d/m/Y à H:i') }}</strong> (par {{ $aRestaurerResume['par'] }}) sont remises en place, à côté de ce qui a été fait depuis : rien n’est écrasé. Le stock retiré revient en plus du stock actuel ; un article recréé avec le même code-barres fusionne avec l’ancien.</p>
                    <ul class="text-sm rounded-xl bg-accent-soft px-4 py-3 flex flex-col gap-1">
                        <li><strong>{{ $aRestaurerResume['ventes'] }}</strong> vente{{ $aRestaurerResume['ventes'] > 1 ? 's' : '' }}, <strong>{{ $aRestaurerResume['clients'] }}</strong> client{{ $aRestaurerResume['clients'] > 1 ? 's' : '' }}, <strong>{{ $aRestaurerResume['achats'] }}</strong> achat{{ $aRestaurerResume['achats'] > 1 ? 's' : '' }}</li>
                        <li><strong>{{ $aRestaurerResume['articles'] }}</strong> article{{ $aRestaurerResume['articles'] > 1 ? 's' : '' }}</li>
                    </ul>
                    <p class="text-xs text-muted">Sauvegarde gardée jusqu’au {{ $aRestaurerResume['expire_le']->format('d/m/Y') }}. S’il y a eu plusieurs remises à zéro, la plus récente se restaure d’abord.</p>
                    <label class="text-sm font-semibold">Tapez <span class="font-mono">RESTAURER</span> pour confirmer
                        <input wire:model="confirmationRestauration" type="text" autocomplete="off" class="{{ $champ }} mt-1 w-full font-mono">
                    </label>
                    @error('confirmationRestauration') <p class="text-sm text-danger-fg">{{ $message }}</p> @enderror
                    @error('sauvegarde') <p class="text-sm text-danger-fg">{{ $message }}</p> @enderror
                    <div class="flex gap-3">
                        <button wire:click="annulerRestauration" class="flex-1 h-11 rounded-xl ring-1 ring-border-strong font-bold hover:bg-puce">Annuler</button>
                        <button wire:click="restaurer" wire:loading.attr="disabled" wire:target="restaurer" class="flex-1 h-11 rounded-xl bg-accent text-white font-bold hover:bg-accent-dark disabled:opacity-60 inline-flex items-center justify-center gap-2"><svg wire:loading wire:target="restaurer" class="w-4 h-4 motion-safe:animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".3" stroke-width="3"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg><span wire:loading.remove wire:target="restaurer">Restaurer</span><span wire:loading wire:target="restaurer">Restauration…</span></button>
                    </div>
                @else
                    <p class="text-sm">Aucune sauvegarde restaurable pour cette boutique (elles sont gardées 30 jours).</p>
                    <button wire:click="annulerRestauration" class="h-11 rounded-xl ring-1 ring-border-strong font-bold hover:bg-puce">Fermer</button>
                @endif
            </div>
        </div>
    @endif

    <div class="flex flex-col gap-4">
        @forelse ($abonnements as $abonnement)
            @php($proprietaire = $abonnement->proprietaire)
            <div class="bg-white rounded-2xl shadow-carte p-4 md:p-5" wire:key="compte-{{ $abonnement->id }}">
                <div class="flex flex-col lg:flex-row lg:items-start justify-between gap-3 lg:gap-6">
                    <div class="flex items-start gap-3 min-w-0">
                        <x-plateforme.avatar :nom="$proprietaire?->name" taille="w-11 h-11 text-sm" />
                        <div class="min-w-0">
                            <p class="font-bold text-base">{{ $proprietaire?->name ?? '—' }}</p>
                            <p class="text-sm text-muted flex flex-wrap items-center gap-x-2 gap-y-1">
                                <x-telephone :numero="$proprietaire?->phone" />
                                @if ($proprietaire?->email)<span class="break-all">· {{ $proprietaire->email }}</span>@endif
                            </p>
                            <p class="text-xs text-muted mt-1">
                                Inscrit le {{ $proprietaire?->created_at?->format('d/m/Y à H:i') }}
                                · {{ $proprietaire?->derniere_app ? 'actif dans l’app '.\Illuminate\Support\Carbon::parse($proprietaire->derniere_app)->locale('fr')->diffForHumans() : 'jamais connecté à l’app' }}
                                @if ($proprietaire?->parrain) · parrainé par <span class="font-semibold text-ink">{{ $proprietaire->parrain->name }}</span> @endif
                                @if ($abonnement->jours_offerts > 0) · <span class="font-semibold text-warn-fg">{{ $abonnement->jours_offerts }} j offerts en attente</span> @endif
                            </p>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 lg:justify-end lg:shrink-0 pl-14 lg:pl-0">
                        @if ($abonnement->estEssai())
                            <x-plateforme.pastille ton="essai">essai</x-plateforme.pastille>
                        @else
                            <x-plateforme.pastille ton="info">{{ $abonnement->plan }}</x-plateforme.pastille>
                        @endif
                        @if ($abonnement->est_manuel)<span class="rounded-full bg-puce px-2 py-0.5 text-[11px] font-bold text-muted">manuel</span>@endif
                        <x-statut-abonnement :abonnement="$abonnement" />
                        <span class="flex items-center gap-1 ml-auto lg:ml-1">
                            <button wire:click="gerer('{{ $abonnement->user_id }}')"
                                    class="h-9 px-3.5 rounded-lg text-xs font-bold inline-flex items-center gap-1.5 hover:bg-accent hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-accent/30 {{ $compteOuvert === $abonnement->user_id ? 'bg-accent text-white' : 'bg-accent-soft text-accent' }}">
                                <x-plateforme.picto nom="carte" class="w-4 h-4" />Gérer
                            </button>
                            @if ($abonnement->estEnCours())
                                <button wire:click="revoquer('{{ $abonnement->user_id }}')"
                                        wire:confirm="Mettre fin à l’abonnement maintenant ? Ses boutiques passent en lecture seule."
                                        title="Révoquer l’abonnement"
                                        class="h-9 px-2.5 rounded-lg text-xs font-bold text-danger-fg inline-flex items-center gap-1.5 hover:bg-danger-bg focus:outline-none focus-visible:ring-2 focus-visible:ring-danger-fg/30">
                                    <x-plateforme.picto nom="stop" class="w-4 h-4" /><span class="sr-only sm:not-sr-only">Révoquer</span>
                                </button>
                            @endif
                        </span>
                    </div>
                </div>

                {{-- Les boutiques du propriétaire, chacune avec son pictogramme. --}}
                <ul class="mt-3 flex flex-col gap-1.5 text-sm">
                    @forelse ($proprietaire?->boutiquesPossedees ?? [] as $b)
                        <li class="flex flex-wrap sm:flex-nowrap items-center gap-x-3 gap-y-1 rounded-xl bg-fond-tableau px-3 py-2.5">
                            <x-plateforme.icone-chip nom="boutique" taille="w-8 h-8" />
                            <span class="min-w-0 flex-1 basis-[calc(100%-2.75rem)] sm:basis-auto">
                                <span class="font-semibold block truncate">{{ $b->nom }}</span>
                                <span class="text-xs text-muted">
                                    créée le {{ $b->created_at?->format('d/m/Y') }}
                                    · {{ $b->nb_ventes }} vente{{ $b->nb_ventes > 1 ? 's' : '' }}
                                    @if ($b->derniere_vente) · dernière le {{ \Illuminate\Support\Carbon::parse($b->derniere_vente)->format('d/m/Y') }} @endif
                                    · 30 j : <span class="font-semibold text-ink tabular-nums">{{ \App\Support\Money\Montant::format((int) $b->total_30j, $b->devise) }} {{ $b->devise }}</span>
                                </span>
                            </span>
                            <span class="flex items-center gap-1 ml-11 sm:ml-0 shrink-0">
                                <button wire:click="preparerReinitialisation('{{ $b->id }}')"
                                        class="h-8 px-2.5 rounded-lg text-xs font-bold text-danger-fg inline-flex items-center gap-1.5 hover:bg-danger-bg focus:outline-none focus-visible:ring-2 focus-visible:ring-danger-fg/30">
                                    <x-plateforme.picto nom="gomme" class="w-3.5 h-3.5" />Réinitialiser
                                </button>
                                @isset($sauvegardes[$b->id])
                                    <button wire:click="preparerRestauration('{{ $b->id }}')"
                                            class="h-8 px-2.5 rounded-lg text-xs font-bold text-accent inline-flex items-center gap-1.5 hover:bg-accent-soft focus:outline-none focus-visible:ring-2 focus-visible:ring-accent/30">
                                        <x-plateforme.picto nom="restaurer" class="w-3.5 h-3.5" />Restaurer
                                    </button>
                                @endisset
                            </span>
                        </li>
                    @empty
                        <li class="text-muted text-sm rounded-xl bg-fond-tableau px-3 py-2.5">aucune boutique</li>
                    @endforelse
                </ul>

                @if ($compteOuvert === $abonnement->user_id)
                    <form wire:submit="accorder" class="mt-4 rounded-xl bg-accent-soft/50 ring-1 ring-accent/10 p-4 grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
                        <div>
                            <label class="block text-xs font-bold text-muted mb-1">Plan</label>
                            <select wire:model="plan" class="{{ $champ }} w-full">
                                @foreach ($plans as $p)
                                    <option value="{{ $p->code }}">{{ $p->nom }}{{ $p->estEssai() ? ' (prolonger)' : '' }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-muted mb-1">Fin (incluse)</label>
                            <input wire:model="fin" type="date" @disabled($sansEcheance) class="{{ $champ }} w-full disabled:opacity-50">
                            @error('fin') <p class="text-xs text-danger-fg mt-1">{{ $message }}</p> @enderror
                        </div>
                        <label class="flex items-center gap-2 text-sm font-semibold h-11 cursor-pointer">
                            <input wire:model.live="sansEcheance" type="checkbox" class="w-4 h-4 accent-accent"> Sans échéance
                        </label>
                        <div>
                            <label class="block text-xs font-bold text-muted mb-1">Note</label>
                            <input wire:model="note" type="text" class="{{ $champ }} w-full">
                        </div>
                        <div class="md:col-span-4 flex gap-3">
                            <button type="submit" class="h-11 px-5 rounded-xl bg-accent text-white font-bold hover:bg-accent-dark">Enregistrer</button>
                            <button type="button" wire:click="$set('compteOuvert', null)" class="h-11 px-5 rounded-xl bg-white ring-1 ring-border-strong font-bold hover:bg-puce">Annuler</button>
                        </div>
                    </form>
                @endif
            </div>
        @empty
            <p class="rounded-2xl bg-white shadow-carte p-6 text-center text-muted">Aucun compte.</p>
        @endforelse
    </div>

    {{ $abonnements->links() }}
</div>
