<div>
    @if (session('status'))
        <div class="mb-4 rounded-lg bg-emerald-50 px-4 py-2.5 text-sm text-emerald-700">{{ session('status') }}</div>
    @endif

    <div class="mb-5 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-600">
        Les montants sont en <span class="font-medium">francs CFA</span> : l'abonnement est
        facturé par NGONI PAY, quelle que soit la devise dans laquelle le commerçant tient
        ses comptes. Rien n'impose qu'un trimestre vaille trois fois le mois — une remise
        sur les durées longues se règle ici.
    </div>

    <form wire:submit="save" class="space-y-5">
        @foreach ($plans as $plan)
            <div class="rounded-xl border border-slate-200 bg-white">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-4 py-3">
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="font-medium text-slate-900">{{ $plan->name }}</span>
                            <code class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-500">{{ $plan->code }}</code>
                            @if ($plan->isFree())
                                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">
                                    Essai de {{ $plan->trial_days }} jours
                                </span>
                            @endif
                        </div>
                        @if ($plan->description)
                            <p class="mt-1 max-w-2xl text-sm text-slate-500">{{ $plan->description }}</p>
                        @endif
                    </div>

                    <label class="flex items-center gap-2 text-sm text-slate-600">
                        <input type="checkbox" wire:model="plansActive.{{ $plan->id }}"
                               class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500/20">
                        Proposé dans l'application
                    </label>
                </div>

                {{-- Règles du plan : elles étaient écrites dans le code, et
                     pouvaient contredire la description affichée. --}}
                <div class="flex flex-wrap items-end gap-6 border-b border-slate-100 bg-slate-50/60 px-4 py-3">
                    <div>
                        <label class="block text-xs font-medium text-slate-600">
                            Paiements en ligne / mois
                        </label>
                        <input type="number" min="0" step="1" placeholder="illimité"
                               wire:model="quotas.{{ $plan->id }}"
                               class="mt-1 w-32 rounded-lg border-slate-300 px-3 py-1.5 text-sm focus:border-indigo-500 focus:ring-indigo-500/20">
                        <p class="mt-1 text-xs text-slate-400">Vide = sans limite · 0 = aucun</p>
                    </div>

                    @if ($plan->isFree())
                        <div>
                            <label class="block text-xs font-medium text-slate-600">
                                Durée de l'essai (jours)
                            </label>
                            <input type="number" min="0" step="1"
                                   wire:model="trials.{{ $plan->id }}"
                                   class="mt-1 w-32 rounded-lg border-slate-300 px-3 py-1.5 text-sm focus:border-indigo-500 focus:ring-indigo-500/20">
                        </div>
                    @endif
                </div>

                @if ($plan->isFree())
                    <div class="px-4 py-4 text-sm text-slate-500">
                        Ce plan est gratuit : il n'a pas de tarif à régler.
                    </div>
                @else
                    <div class="divide-y divide-slate-100">
                        @foreach ($cycles as $cycle)
                            @php($price = $plan->prices->firstWhere('cycle', $cycle))
                            <div class="flex flex-wrap items-center gap-4 px-4 py-3">
                                <div class="w-32 shrink-0">
                                    <div class="text-sm font-medium text-slate-700">{{ $cycle->label() }}</div>
                                    <div class="text-xs text-slate-400">
                                        {{ $cycle->months() }} mois · par {{ $cycle->unit() }}
                                    </div>
                                </div>

                                @if ($price)
                                    <div class="flex items-center gap-2">
                                        <input type="number" min="0" step="1"
                                               wire:model="amounts.{{ $price->id }}"
                                               class="w-36 rounded-lg border-slate-300 px-3 py-2 text-right text-sm focus:border-indigo-500 focus:ring-indigo-500/20">
                                        <span class="text-sm text-slate-500">{{ $price->currency }}</span>
                                    </div>

                                    @error('amounts.' . $price->id)
                                        <span class="text-xs text-red-600">{{ $message }}</span>
                                    @enderror

                                    <label class="flex items-center gap-2 text-sm text-slate-600">
                                        <input type="checkbox" wire:model="actives.{{ $price->id }}"
                                               class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500/20">
                                        Durée proposée
                                    </label>

                                    @php($mensuel = $plan->prices->firstWhere('cycle', \App\Enums\BillingCycle::Monthly))
                                    @if ($mensuel && $cycle->months() > 1 && (float) $mensuel->amount > 0)
                                        @php($plein = (float) $mensuel->amount * $cycle->months())
                                        @php($remise = $plein > 0 ? round((1 - ((float) $price->amount / $plein)) * 100) : 0)
                                        @if ($remise > 0)
                                            <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">
                                                −{{ $remise }} % vs mensuel
                                            </span>
                                        @elseif ($remise < 0)
                                            <span class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700">
                                                +{{ abs($remise) }} % vs mensuel
                                            </span>
                                        @endif
                                    @endif
                                @else
                                    <button type="button" wire:click="addCycle({{ $plan->id }}, '{{ $cycle->value }}')"
                                            class="rounded-lg border border-dashed border-slate-300 px-3 py-1.5 text-xs text-slate-500 hover:border-indigo-300 hover:text-indigo-600">
                                        Ouvrir cette durée
                                    </button>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach

        <div class="flex items-center gap-3">
            <button type="submit" wire:loading.attr="disabled"
                    class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">
                Enregistrer les tarifs
            </button>
            <span wire:loading class="text-sm text-slate-500">Enregistrement…</span>
        </div>
    </form>
</div>
