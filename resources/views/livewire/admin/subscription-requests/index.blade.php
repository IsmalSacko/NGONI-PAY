<div>
    @if (session('status'))
        <div class="mb-4 rounded-lg bg-emerald-50 px-4 py-2.5 text-sm text-emerald-700">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="mb-4 rounded-lg bg-red-50 px-4 py-2.5 text-sm text-red-600">{{ session('error') }}</div>
    @endif

    {{-- Compteurs : la file de travail se mesure d'un coup d'œil. --}}
    <div class="mb-4 grid grid-cols-3 gap-3">
        @foreach ([
            ['pending', 'En attente', 'amber'],
            ['approved', 'Approuvées', 'emerald'],
            ['refused', 'Refusées', 'slate'],
        ] as [$cle, $libelle, $teinte])
            <button type="button" wire:click="$set('status', '{{ $cle }}')"
                    class="rounded-xl border px-4 py-3 text-left transition
                           {{ $status === $cle ? 'border-indigo-300 bg-indigo-50' : 'border-slate-200 bg-white hover:border-slate-300' }}">
                <div class="text-2xl font-semibold text-{{ $teinte }}-600">{{ $compteurs[$cle] }}</div>
                <div class="text-xs text-slate-500">{{ $libelle }}</div>
            </button>
        @endforeach
    </div>

    <div class="mb-4 flex flex-wrap gap-3">
        <select wire:model.live="status" class="rounded-lg border-slate-300 px-3 py-2 text-base sm:text-sm focus:border-indigo-500 focus:ring-indigo-500/20">
            <option value="pending">En attente</option>
            <option value="approved">Approuvées</option>
            <option value="refused">Refusées</option>
            <option value="cancelled">Annulées</option>
            <option value="all">Toutes</option>
        </select>
        <input type="search" wire:model.live.debounce.400ms="search" placeholder="Entreprise ou téléphone…"
               class="min-w-56 flex-1 rounded-lg border-slate-300 px-3 py-2 text-base sm:text-sm focus:border-indigo-500 focus:ring-indigo-500/20">
    </div>

    <div class="space-y-3">
        @forelse ($demandes as $demande)
            <div class="rounded-xl border border-slate-200 bg-white p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <a href="{{ route('admin.businesses.show', $demande->business) }}"
                               class="font-medium text-slate-900 hover:text-indigo-600">
                                {{ $demande->business->name }}
                            </a>
                            <span class="rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700">
                                {{ strtoupper($demande->plan) }}
                            </span>
                            @php($teinte = match ($demande->status->value) {
                                'pending' => 'bg-amber-50 text-amber-700',
                                'approved' => 'bg-emerald-50 text-emerald-700',
                                'refused' => 'bg-red-50 text-red-600',
                                default => 'bg-slate-100 text-slate-600',
                            })
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $teinte }}">
                                {{ $demande->status->label() }}
                            </span>
                        </div>
                        <div class="mt-1 text-sm text-slate-500">
                            {{ $demande->business->owner?->name ?? '—' }}
                            · {{ $demande->contact_phone ?: ($demande->business->phone ?: '—') }}
                            · {{ $demande->created_at->diffForHumans() }}
                        </div>
                        @if ($demande->note)
                            <p class="mt-2 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600">
                                « {{ $demande->note }} »
                            </p>
                        @endif
                        @if ($demande->proof_note)
                            {{-- SMS recopié : tous les téléphones ne permettent pas
                                 d'en faire une capture. --}}
                            <p class="mt-2 rounded-lg bg-slate-50 px-3 py-2 font-mono text-xs text-slate-600">
                                {{ $demande->proof_note }}
                            </p>
                        @endif
                    </div>

                    <div class="text-right">
                        <div class="text-lg font-semibold text-slate-900">
                            {{ number_format((float) $demande->amount_due, 0, ',', ' ') }} {{ $demande->currency }}
                        </div>
                        {{-- La durée demandée, nommée : « 3 » ne dit pas si le
                             commerçant a pris un trimestre ou trois mois à
                             l'unité, et c'est ce que l'approbation accorde. --}}
                        <div class="mt-1 inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700">
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round">
                                <path d="M12 8v4l3 2M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Z"/>
                            </svg>
                            {{ $demande->cycle?->label() ?? 'Mensuel' }}
                            · {{ $demande->months }} mois
                        </div>
                        <div class="mt-1 text-xs text-slate-500">
                            {{ $demande->method ? str_replace('_', ' ', $demande->method) : 'moyen non précisé' }}
                        </div>
                    </div>
                </div>

                @if ($demande->proofUrl())
                    <button type="button" wire:click="showProof('{{ $demande->proofUrl() }}')"
                            class="mt-3 inline-flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-1.5 text-xs text-slate-600 hover:border-indigo-300 hover:text-indigo-600">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path d="M4 16V6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v10M4 16l4-4 3 3 4-5 5 6" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        Voir la preuve de paiement
                    </button>
                @endif

                @if ($demande->status->value === 'pending')
                    @if ($decidingId === $demande->id)
                        <div class="mt-3 rounded-lg border border-slate-200 bg-slate-50 p-3">
                            <input type="text" wire:model="decisionNote" maxlength="500"
                                   placeholder="Note ou motif (facultatif) — le commerçant le verra"
                                   class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500/20">
                            <div class="mt-3 flex flex-wrap gap-2">
                                <button type="button" wire:click="approve({{ $demande->id }})"
                                        wire:loading.attr="disabled"
                                        class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50">
                                    Approuver — {{ strtoupper($demande->plan) }} pour {{ $demande->months }} mois
                                </button>
                                <button type="button" wire:click="refuse({{ $demande->id }})"
                                        wire:loading.attr="disabled"
                                        class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-50">
                                    Refuser
                                </button>
                                <button type="button" wire:click="cancelDeciding"
                                        class="rounded-lg px-4 py-2 text-sm text-slate-600 hover:text-slate-900">
                                    Annuler
                                </button>
                            </div>
                        </div>
                    @else
                        <button type="button" wire:click="startDeciding({{ $demande->id }})"
                                class="mt-3 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                            Instruire cette demande
                        </button>
                    @endif
                @elseif ($demande->decided_at)
                    <div class="mt-3 text-xs text-slate-500">
                        {{ $demande->status->label() }} le {{ $demande->decided_at->format('d/m/Y à H:i') }}
                        @if ($demande->decidedBy) par {{ $demande->decidedBy->name }} @endif
                        @if ($demande->decision_note) — « {{ $demande->decision_note }} » @endif
                    </div>
                @endif
            </div>
        @empty
            <div class="rounded-xl border border-dashed border-slate-300 bg-white px-4 py-10 text-center text-sm text-slate-500">
                Aucune demande {{ $status === 'all' ? '' : mb_strtolower(match ($status) {
                    'pending' => 'en attente',
                    'approved' => 'approuvée',
                    'refused' => 'refusée',
                    default => 'annulée',
                }) }}.
            </div>
        @endforelse
    </div>

    <div class="mt-4">{{ $demandes->links() }}</div>

    {{-- Preuve en grand : c'est sur elle que la décision se prend. --}}
    @if ($proofUrl)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/70 p-4"
             wire:click="hideProof">
            <div class="max-h-full max-w-3xl overflow-auto rounded-xl bg-white p-2" wire:click.stop>
                <img src="{{ $proofUrl }}" alt="Preuve de paiement" class="max-h-[80vh] w-auto rounded-lg">
                <div class="flex justify-end gap-2 p-2">
                    <a href="{{ $proofUrl }}" target="_blank" rel="noopener"
                       class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs text-slate-600 hover:border-indigo-300">
                        Ouvrir dans un onglet
                    </a>
                    <button type="button" wire:click="hideProof"
                            class="rounded-lg bg-slate-900 px-3 py-1.5 text-xs text-white">Fermer</button>
                </div>
            </div>
        </div>
    @endif
</div>
