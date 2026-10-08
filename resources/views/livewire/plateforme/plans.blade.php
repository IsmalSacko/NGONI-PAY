@php
    $champ = 'h-11 px-3.5 rounded-xl border border-border-strong bg-white text-sm focus:outline-none focus:border-accent/40 focus:ring-2 focus:ring-accent/30';
    $titreSection = 'flex items-center gap-2 text-xs font-extrabold uppercase tracking-wide text-muted';
@endphp
<div class="flex flex-col gap-5">
    <div>
        <h1 class="font-display font-extrabold text-2xl md:text-3xl">Plans et tarifs</h1>
        <p class="text-sm text-muted">Limites vides = illimité. Un tarif vide ou décoché n’est pas proposé. Les demandes déjà déposées gardent leur montant.</p>
    </div>

    @if ($info)<p class="rounded-xl bg-accent-soft text-accent-dark px-4 py-3 text-sm font-semibold">{{ $info }}</p>@endif

    <form wire:submit="enregistrer" class="flex flex-col gap-5">
        @foreach ($plans as $id => $plan)
            <div class="bg-white rounded-2xl shadow-carte" wire:key="plan-{{ $id }}">
                {{-- Bandeau : nom modifiable, code, et l'interrupteur « proposé ». --}}
                <div class="flex flex-wrap items-center gap-3 px-4 md:px-5 py-3.5 rounded-t-2xl bg-linear-to-r from-accent-soft to-white border-b border-separateur">
                    <x-plateforme.icone-chip :nom="$plan['essai'] ? 'horloge' : 'etoile'" :ton="$plan['essai'] ? 'jaune' : 'nuit'" />
                    <label class="min-w-0 flex-1 md:flex-none">
                        <span class="sr-only">Nom du plan</span>
                        <input wire:model="plans.{{ $id }}.nom"
                               class="w-full md:w-64 h-11 px-3 rounded-xl border border-transparent bg-white/70 font-display font-extrabold text-lg text-accent hover:border-border-strong focus:outline-none focus:bg-white focus:border-accent/40 focus:ring-2 focus:ring-accent/30">
                    </label>
                    <code class="text-xs font-bold bg-white text-muted ring-1 ring-border rounded-full px-2.5 py-1">{{ $plan['code'] }}</code>
                    @unless ($plan['essai'])
                        <label class="ml-auto inline-flex items-center gap-2.5 text-sm font-semibold cursor-pointer select-none">
                            <input type="checkbox" wire:model="plans.{{ $id }}.est_actif" class="peer sr-only">
                            <span class="order-2 relative w-11 h-6 shrink-0 rounded-full bg-border-strong peer-checked:bg-succes peer-focus-visible:ring-2 peer-focus-visible:ring-accent/30 motion-safe:transition-colors
                                         after:absolute after:top-0.5 after:left-0.5 after:w-5 after:h-5 after:rounded-full after:bg-white after:shadow after:content-[''] motion-safe:after:transition-transform peer-checked:after:translate-x-5"
                                  aria-hidden="true"></span>
                            <span class="order-1">Proposé dans l’application</span>
                        </label>
                    @endunless
                </div>

                <div class="p-4 md:p-5 flex flex-col gap-5">
                    <label class="block">
                        <span class="sr-only">Description</span>
                        <textarea wire:model="plans.{{ $id }}.description" rows="2" placeholder="Description"
                                  class="w-full px-3.5 py-2.5 rounded-xl border border-border-strong text-sm focus:outline-none focus:border-accent/40 focus:ring-2 focus:ring-accent/30"></textarea>
                    </label>

                    <section class="flex flex-col gap-2.5">
                        <h3 class="{{ $titreSection }}"><x-plateforme.picto nom="reglages" class="w-4 h-4" />Limites</h3>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm">
                            @if ($plan['essai'])
                                <label class="flex flex-col gap-1 font-semibold">Durée de l’essai (jours)
                                    <span class="relative">
                                        <x-plateforme.picto nom="horloge" class="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-muted pointer-events-none" />
                                        <input wire:model="plans.{{ $id }}.jours_essai" type="number" min="1" class="{{ $champ }} w-full pl-10 font-normal">
                                    </span>
                                    @error("plans.$id.jours_essai") <span class="text-xs font-normal text-danger-fg">{{ $message }}</span> @enderror
                                </label>
                            @endif
                            <label class="flex flex-col gap-1 font-semibold">Boutiques max
                                <span class="relative">
                                    <x-plateforme.picto nom="boutique" class="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-muted pointer-events-none" />
                                    <input wire:model="plans.{{ $id }}.max_boutiques" type="number" min="1" placeholder="illimité" class="{{ $champ }} w-full pl-10 font-normal">
                                </span>
                            </label>
                            <label class="flex flex-col gap-1 font-semibold">Membres max par boutique
                                <span class="relative">
                                    <x-plateforme.picto nom="membres" class="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-muted pointer-events-none" />
                                    <input wire:model="plans.{{ $id }}.max_membres" type="number" min="1" placeholder="illimité" class="{{ $champ }} w-full pl-10 font-normal">
                                </span>
                            </label>
                        </div>
                    </section>

                    <section class="flex flex-col gap-2.5">
                        <h3 class="{{ $titreSection }}"><x-plateforme.picto nom="ok" class="w-4 h-4" />Fonctionnalités</h3>
                        <div class="flex flex-wrap gap-2 text-sm">
                            @foreach ($fonctionnalites as $code => $libelle)
                                @if ($plan['essai'])
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-succes-doux/70 text-succes px-3 py-1.5 font-semibold">✓ {{ $libelle }} <span class="font-normal text-muted">(l’essai couvre tout)</span></span>
                                @else
                                    <label class="inline-flex items-center gap-2 rounded-full px-3 h-9 ring-1 ring-border-strong bg-white cursor-pointer select-none font-semibold text-muted hover:ring-accent/40
                                                  has-checked:bg-accent-soft has-checked:text-accent has-checked:ring-accent/30 has-focus-visible:ring-2 has-focus-visible:ring-accent/40">
                                        <input type="checkbox" wire:model="plans.{{ $id }}.fonctionnalites.{{ $code }}" class="w-4 h-4 accent-accent"> {{ $libelle }}
                                    </label>
                                @endif
                            @endforeach
                        </div>
                    </section>

                    @unless ($plan['essai'])
                        <section class="flex flex-col gap-2.5">
                            <h3 class="{{ $titreSection }}"><x-plateforme.picto nom="etiquette" class="w-4 h-4" />Tarifs</h3>
                            <div class="grid grid-cols-1 min-[420px]:grid-cols-2 lg:grid-cols-4 gap-3">
                                @foreach ($cycles as $cycle)
                                    {{-- Coché : la carte se pose en blanc, cerclée de bleu ; décoché, elle s'efface. --}}
                                    <div class="rounded-xl p-3.5 text-sm bg-fond-tableau ring-1 ring-border has-checked:bg-white has-checked:ring-accent/25 has-checked:shadow-[0_1px_2px_rgba(15,42,92,.05),0_4px_12px_rgba(15,42,92,.06)] motion-safe:transition">
                                        <label class="flex items-center justify-between gap-2 font-bold cursor-pointer">
                                            {{ $cycle->libelle() }}
                                            <input type="checkbox" wire:model="tarifs.{{ $id }}.{{ $cycle->value }}.actif" class="w-4 h-4 accent-accent">
                                        </label>
                                        <input wire:model="tarifs.{{ $id }}.{{ $cycle->value }}.montant" type="number" min="0" placeholder="non proposé"
                                               aria-label="Montant {{ strtolower($cycle->libelle()) }}"
                                               class="mt-2 w-full h-12 px-3 rounded-xl border border-border-strong bg-white font-display font-extrabold text-xl text-accent tabular-nums placeholder:text-sm placeholder:font-sans placeholder:font-normal placeholder:text-muted focus:outline-none focus:border-accent/40 focus:ring-2 focus:ring-accent/30">
                                        <span class="mt-1 block text-xs text-muted">F CFA / {{ $cycle->unite() }}</span>
                                        @error("tarifs.$id.{$cycle->value}.montant") <span class="block text-xs text-danger-fg">{{ $message }}</span> @enderror
                                    </div>
                                @endforeach
                            </div>
                        </section>

                        {{-- Abonnement à vie : réglé une fois. Prix vide = pas proposé ;
                             la vitrine et les conditions d'utilisation suivent. --}}
                        <section class="flex flex-col gap-2.5">
                            <h3 class="{{ $titreSection }}"><x-plateforme.picto nom="etoile" class="w-4 h-4" />Abonnement à vie</h3>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm">
                                <label class="flex flex-col gap-1 font-semibold">Prix (une seule fois)
                                    <input wire:model="plans.{{ $id }}.prix_a_vie" type="number" min="1" placeholder="non proposé" class="{{ $champ }} w-full font-normal">
                                    @error("plans.$id.prix_a_vie") <span class="text-xs font-normal text-danger-fg">{{ $message }}</span> @enderror
                                </label>
                                <label class="flex flex-col gap-1 font-semibold">Proposé du
                                    <input wire:model="plans.{{ $id }}.a_vie_debut" type="date" class="{{ $champ }} w-full font-normal">
                                </label>
                                <label class="flex flex-col gap-1 font-semibold">au
                                    <input wire:model="plans.{{ $id }}.a_vie_fin" type="date" class="{{ $champ }} w-full font-normal">
                                    @error("plans.$id.a_vie_fin") <span class="text-xs font-normal text-danger-fg">{{ $message }}</span> @enderror
                                </label>
                            </div>
                            <label class="block text-sm font-semibold">Texte affiché sur la vitrine
                                <input wire:model="plans.{{ $id }}.texte_a_vie" type="text" maxlength="255" placeholder="Toutes les fonctions du plan, sans jamais renouveler."
                                       class="{{ $champ }} mt-1 w-full font-normal">
                            </label>
                        </section>
                    @endunless
                </div>
            </div>
        @endforeach

        {{-- Le seul bouton fort de l'écran, gardé à portée de pouce. --}}
        <div class="sticky bottom-3 z-20 flex">
            <button type="submit" wire:loading.attr="disabled" wire:target="enregistrer"
                    class="w-full sm:w-auto h-12 px-7 rounded-xl bg-accent text-white font-bold shadow-carte-haute inline-flex items-center justify-center gap-2 hover:bg-accent-dark disabled:opacity-60 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent/30 focus-visible:ring-offset-2">
                <svg wire:loading wire:target="enregistrer" class="w-4 h-4 motion-safe:animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".3" stroke-width="3"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                Enregistrer
            </button>
        </div>
    </form>
</div>
