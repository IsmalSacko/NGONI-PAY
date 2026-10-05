@php
    $champ = 'h-11 px-3.5 rounded-xl border border-border-strong bg-white text-sm focus:outline-none focus:border-accent/40 focus:ring-2 focus:ring-accent/30';
    $tons = ['en_attente' => 'attente', 'approuvee' => 'succes', 'refusee' => 'danger', 'annulee' => 'neutre'];
@endphp
<div class="flex flex-col gap-5">
    @php
        $onglets = [
            'en_attente' => ['À traiter', 'horloge'],
            'approuvee' => ['Approuvées', 'ok'],
            'refusee' => ['Refusées', 'stop'],
            'annulee' => ['Annulées', 'gomme'],
            'toutes' => ['Toutes', 'etiquette'],
        ];
        $aTraiter = (int) ($comptes['en_attente'] ?? 0);
    @endphp
    <div>
        <h1 class="font-display font-extrabold text-2xl md:text-3xl">Demandes d’abonnement</h1>
        <p class="text-sm text-muted">Approuvez une fois le paiement constaté. Le plan ne s’active qu’à ce moment.</p>
    </div>

    {{-- La carte forte de la marque : ce qui attend une décision. --}}
    <div class="rounded-2xl p-5 text-white shadow-carte flex items-center gap-4" style="background: linear-gradient(135deg, var(--color-nuit-clair), var(--color-accent));">
        <span class="w-12 h-12 shrink-0 rounded-xl bg-jaune text-accent inline-flex items-center justify-center"><x-plateforme.picto nom="horloge" class="w-6 h-6" /></span>
        <div class="min-w-0">
            <p class="text-sm font-semibold text-white/70">À traiter</p>
            <p class="font-display font-extrabold text-2xl md:text-3xl">{{ $aTraiter }} demande{{ $aTraiter > 1 ? 's' : '' }}</p>
            <p class="text-sm font-bold text-jaune">{{ $aTraiter ? 'La plus ancienne en premier : traitez dans l’ordre d’arrivée.' : 'Tout est traité.' }}</p>
        </div>
    </div>

    {{-- Recherche, puis les onglets avec leur nombre (recherche comprise). --}}
    <div class="flex flex-col gap-3">
        <label class="relative block">
            <x-plateforme.picto nom="recherche" class="w-5 h-5 text-muted absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none" />
            <input wire:model.live.debounce.300ms="recherche" type="search" placeholder="Boutique, téléphone, nom, prénom ou e-mail…"
                   class="{{ $champ }} w-full pl-11 h-12 text-base">
        </label>
        <div class="flex flex-wrap gap-2" role="tablist">
            @foreach ($onglets as $cle => [$libelle, $picto])
                @php($n = $cle === 'toutes' ? $comptes->sum() : (int) ($comptes[$cle] ?? 0))
                <button wire:click="$set('filtre', '{{ $cle }}')" role="tab" aria-selected="{{ $filtre === $cle ? 'true' : 'false' }}"
                        class="h-10 pl-3 pr-2 rounded-xl inline-flex items-center gap-2 text-sm font-bold transition
                               {{ $filtre === $cle ? 'bg-jaune text-accent shadow-sm' : 'bg-white text-ink ring-1 ring-border-strong hover:bg-puce' }}">
                    <x-plateforme.picto :nom="$picto" class="w-4 h-4" />{{ $libelle }}
                    <span class="min-w-6 h-6 px-1.5 rounded-lg inline-flex items-center justify-center text-xs tabular-nums
                                 {{ $filtre === $cle ? 'bg-accent text-white' : ($cle === 'en_attente' && $n ? 'bg-danger-fg text-white' : 'bg-puce text-muted') }}">{{ $n }}</span>
                </button>
            @endforeach
        </div>
    </div>

    @if ($info)<p class="rounded-xl bg-accent-soft text-accent-dark px-4 py-3 text-sm font-semibold">{{ $info }}</p>@endif
    @if ($alerte)<p class="rounded-xl bg-danger-bg text-danger-fg px-4 py-3 text-sm font-semibold">{{ $alerte }}</p>@endif

    @forelse ($demandes as $demande)
        @php($tranchee = $demande->statut->estTranchee())
        @if ($tranchee)
            {{-- Déjà traitée : une ligne compacte, le détail au toucher. --}}
            <div x-data="{ ouvert: false }" class="bg-white rounded-2xl shadow-carte" wire:key="demande-{{ $demande->id }}">
                <button type="button" @click="ouvert = !ouvert" class="w-full p-3.5 md:px-5 flex items-center gap-3 text-left text-sm">
                    <x-plateforme.icone-chip nom="boutique" ton="neutre" taille="w-9 h-9" />
                    <span class="min-w-0 flex-1 flex flex-wrap items-center gap-x-2 gap-y-0.5">
                        <span class="font-bold truncate">{{ $demande->boutique?->nom ?? '—' }}</span>
                        <span class="text-muted">· {{ ucfirst($demande->plan) }} {{ strtolower($demande->cycle->libelle()) }}</span>
                        <span class="font-bold text-accent tabular-nums">{{ number_format($demande->montant, 0, ',', ' ') }} {{ $demande->devise }}</span>
                    </span>
                    <x-plateforme.pastille :ton="$tons[$demande->statut->value] ?? 'neutre'">{{ $demande->statut->libelle() }}</x-plateforme.pastille>
                    <span class="hidden sm:inline text-xs text-muted tabular-nums w-24 text-right">{{ $demande->decide_le?->format('d/m/Y') }}</span>
                    <svg class="w-4 h-4 text-muted transition" :class="ouvert && 'rotate-180'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                </button>
                <div x-show="ouvert" x-cloak class="px-4 md:px-5 pb-4 pt-1 border-t border-separateur">
        @else
        <div class="bg-white rounded-2xl shadow-carte p-4 md:p-5 ring-1 ring-jaune/50" wire:key="demande-{{ $demande->id }}">
        @endif
            <div class="flex flex-col md:flex-row md:items-start justify-between gap-4">
                <div class="flex items-start gap-3 min-w-0 text-sm">
                    <x-plateforme.icone-chip nom="boutique" :ton="$tranchee ? 'neutre' : 'jaune'" />
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <p class="font-bold text-base">{{ $demande->boutique?->nom ?? '—' }}</p>
                            <x-plateforme.pastille :ton="$tons[$demande->statut->value] ?? 'neutre'">{{ $demande->statut->libelle() }}</x-plateforme.pastille>
                            {{-- En attente depuis plus d'un jour : à ne pas oublier. --}}
                            @if (! $tranchee && $demande->created_at->lt(now()->subDay()))
                                <span class="inline-flex items-center gap-1 rounded-full bg-danger-fg text-white px-2.5 py-0.5 text-xs font-bold">
                                    <x-plateforme.picto nom="alerte" class="w-3.5 h-3.5" />Depuis {{ $demande->created_at->diffForHumans(null, true) }}
                                </span>
                            @endif
                        </div>
                        <p class="mt-0.5 flex flex-wrap items-baseline gap-x-2">
                            <span class="font-semibold">{{ ucfirst($demande->plan) }} {{ strtolower($demande->cycle->libelle()) }}</span>
                            <span class="text-muted">·</span>
                            <span class="font-display font-extrabold text-lg text-accent tabular-nums">{{ number_format($demande->montant, 0, ',', ' ') }} {{ $demande->devise }}</span>
                        </p>
                        @php($parrainage = app(\App\Services\Parrainage::class)->pourDemande($demande))
                        @if ($parrainage)
                            <p class="mt-1.5 inline-flex items-center gap-1.5 rounded-full bg-jaune-doux px-2.5 py-1 text-xs font-bold text-warn-fg">
                                <x-plateforme.picto nom="cadeau" class="w-3.5 h-3.5 shrink-0" />
                                {{ $parrainage['etat'] === 'a_venir'
                                    ? 'Filleul de '.$parrainage['parrain'].' : approuver lui fera gagner son mois offert'
                                    : 'A rapporté le mois offert à '.$parrainage['parrain'] }}
                            </p>
                        @endif
                        <p class="mt-2 text-muted flex flex-wrap items-center gap-x-2 gap-y-1">
                            <span class="font-semibold text-ink">{{ $demande->proprietaire?->name }}</span> ·
                            <x-telephone :numero="$demande->telephone_contact ?: $demande->proprietaire?->phone" />
                            · {{ $demande->created_at->diffForHumans() }}
                        </p>
                        <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1">
                            <span class="text-muted">Abonnement actuel :</span> <x-statut-abonnement :abonnement="$demande->proprietaire?->abonnement" />
                            @if ($demande->jeko_paiement_id)
                                <span class="text-muted">· payé par <span class="font-semibold text-ink">{{ config('jeko.moyens')[substr((string) $demande->moyen, 5)] ?? 'Mobile Money' }}</span> via Jèko</span>
                                @unless ($tranchee)<span class="ml-1 rounded-full bg-jaune-doux px-2 py-0.5 text-xs font-bold text-ink">paiement en cours chez l’opérateur</span>@endunless
                            @elseif ($demande->moyen)<span class="text-muted">· moyen annoncé : <span class="font-semibold text-ink">{{ $demande->moyen }}</span></span>@endif
                        </p>
                        @if ($demande->note)<p class="mt-2 rounded-xl bg-fond-tableau px-3 py-2">{{ $demande->note }}</p>@endif
                        @if ($demande->preuve_note)<p class="mt-2 rounded-xl bg-fond-tableau px-3 py-2"><span class="font-bold">SMS / preuve :</span> {{ $demande->preuve_note }}</p>@endif
                        @if ($demande->preuve_chemin)
                            <a href="{{ route('plateforme.preuve', $demande) }}" target="_blank"
                               class="mt-2 inline-flex items-center gap-1.5 h-9 px-3 rounded-lg bg-accent-soft text-accent font-bold hover:bg-accent hover:text-white">
                                <x-plateforme.picto nom="document" class="w-4 h-4" />Voir la preuve de paiement
                            </a>
                        @endif
                        @if ($tranchee)
                            <p class="mt-2 text-muted">{{ $demande->statut->libelle() }} {{ $demande->decide_le?->format('d/m/Y H:i') }}
                                @if ($demande->note_decision) — {{ $demande->note_decision }} @endif</p>
                        @endif
                    </div>
                </div>

                {{-- Paiement Mobile Money : il s'approuve seul à la confirmation de Jèko. --}}
                @unless ($tranchee || $demande->jeko_paiement_id)
                    <div class="flex gap-2 md:shrink-0 pl-13 md:pl-0">
                        <button wire:click="approuver({{ $demande->id }})" wire:confirm="Le paiement a bien été reçu ? Le plan s’active immédiatement."
                                class="h-10 px-4 rounded-xl bg-accent text-white font-bold text-sm inline-flex items-center gap-1.5 shadow-sm hover:bg-accent-dark">
                            <x-plateforme.picto nom="ok" class="w-4 h-4" />Approuver
                        </button>
                        <button wire:click="approuver({{ $demande->id }}, true)" wire:confirm="Le paiement d’un accès à vie a bien été reçu ? Le plan s’active immédiatement, sans échéance."
                                class="h-10 px-4 rounded-xl border border-accent text-accent font-bold text-sm inline-flex items-center gap-1.5 hover:bg-accent/5">
                            Sans échéance
                        </button>
                        <button wire:click="demanderRefus({{ $demande->id }})"
                                class="h-10 px-4 rounded-xl font-bold text-sm text-danger-fg hover:bg-danger-bg {{ $refusEnCours === $demande->id ? 'bg-danger-bg' : '' }}">Refuser</button>
                    </div>
                @endunless
            </div>

            @if ($refusEnCours === $demande->id)
                <form wire:submit="refuser" class="mt-4 rounded-xl bg-danger-bg/50 p-3 flex flex-col sm:flex-row gap-3 sm:items-start">
                    <div class="flex-grow">
                        <input wire:model="motif" type="text" placeholder="Motif visible par le commerçant (ex. paiement introuvable)"
                               class="{{ $champ }} w-full">
                        @error('motif') <p class="text-xs text-danger-fg mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex gap-3">
                        <button type="submit" class="flex-1 sm:flex-none h-11 px-4 rounded-xl bg-danger-fg text-white font-bold">Confirmer le refus</button>
                        <button type="button" wire:click="$set('refusEnCours', null)" class="h-11 px-4 rounded-xl bg-white ring-1 ring-border-strong font-bold hover:bg-puce">Annuler</button>
                    </div>
                </form>
            @endif
        </div>
        @if ($tranchee)
            </div>
        @endif
    @empty
        <div class="rounded-2xl bg-white shadow-carte p-8 flex flex-col items-center gap-3 text-center">
            <x-plateforme.icone-chip nom="ok" ton="succes" taille="w-12 h-12" />
            <p class="text-muted">{{ trim($recherche) !== '' ? 'Aucune demande ne correspond à « '.trim($recherche).' ».' : ($filtre === 'en_attente' ? 'Aucune demande à traiter.' : 'Aucune demande.') }}</p>
        </div>
    @endforelse

    {{ $demandes->links() }}
</div>
