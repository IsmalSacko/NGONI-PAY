<div class="flex flex-col gap-5">
    <div>
        <h1 class="font-display font-extrabold text-3xl">Plans et tarifs</h1>
        <p class="text-sm text-muted">Limites vides = illimité. Un tarif vide ou décoché n’est pas proposé. Les demandes déjà déposées gardent leur montant.</p>
    </div>

    @if ($info)<p class="rounded-xl bg-accent-soft text-accent-dark px-4 py-3 text-sm font-semibold">{{ $info }}</p>@endif

    <form wire:submit="enregistrer" class="flex flex-col gap-4">
        @foreach ($plans as $id => $plan)
            <div class="bg-white border border-border rounded-2xl p-5" wire:key="plan-{{ $id }}">
                <div class="flex items-center gap-3 mb-4">
                    <input wire:model="plans.{{ $id }}.nom" class="h-11 px-3 rounded-lg border border-border-strong font-bold">
                    <code class="text-xs bg-[#F1EDE4] rounded px-2 py-1">{{ $plan['code'] }}</code>
                    @unless ($plan['essai'])
                        <label class="flex items-center gap-2 text-sm ml-auto"><input type="checkbox" wire:model="plans.{{ $id }}.est_actif"> Proposé dans l’application</label>
                    @endunless
                </div>
                <textarea wire:model="plans.{{ $id }}.description" rows="2" class="w-full px-3 py-2 rounded-lg border border-border-strong text-sm"></textarea>
                <div class="grid grid-cols-3 gap-3 mt-3 text-sm">
                    @if ($plan['essai'])
                        <label>Durée de l’essai (jours)
                            <input wire:model="plans.{{ $id }}.jours_essai" type="number" min="1" class="mt-1 w-full h-10 px-3 rounded-lg border border-border-strong">
                            @error("plans.$id.jours_essai") <span class="text-xs text-danger-fg">{{ $message }}</span> @enderror
                        </label>
                    @endif
                    <label>Boutiques max
                        <input wire:model="plans.{{ $id }}.max_boutiques" type="number" min="1" placeholder="illimité" class="mt-1 w-full h-10 px-3 rounded-lg border border-border-strong">
                    </label>
                    <label>Membres max par boutique
                        <input wire:model="plans.{{ $id }}.max_membres" type="number" min="1" placeholder="illimité" class="mt-1 w-full h-10 px-3 rounded-lg border border-border-strong">
                    </label>
                </div>

                @unless ($plan['essai'])
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mt-4">
                        @foreach ($cycles as $cycle)
                            <div class="rounded-xl border border-border p-3 text-sm">
                                <label class="flex items-center justify-between font-bold">
                                    {{ $cycle->libelle() }}
                                    <input type="checkbox" wire:model="tarifs.{{ $id }}.{{ $cycle->value }}.actif">
                                </label>
                                <input wire:model="tarifs.{{ $id }}.{{ $cycle->value }}.montant" type="number" min="0" placeholder="non proposé"
                                       class="mt-2 w-full h-10 px-3 rounded-lg border border-border-strong">
                                <span class="text-xs text-muted">F CFA / {{ $cycle->unite() }}</span>
                                @error("tarifs.$id.{$cycle->value}.montant") <span class="block text-xs text-danger-fg">{{ $message }}</span> @enderror
                            </div>
                        @endforeach
                    </div>
                @endunless
            </div>
        @endforeach

        <button type="submit" class="self-start h-12 px-6 rounded-xl bg-accent text-white font-bold">Enregistrer</button>
    </form>
</div>
