<div class="flex flex-col gap-5">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="flex flex-col gap-1">
            <h1 class="font-display font-extrabold text-2xl md:text-3xl">Boutiques</h1>
            <span class="text-[--color-muted] text-sm">
                Vous possédez {{ $possedees }} boutique{{ $possedees > 1 ? 's' : '' }}{{ $maxBoutiques ? " sur {$maxBoutiques} permise".($maxBoutiques > 1 ? 's' : '').' par votre plan' : '' }}.
            </span>
        </div>
        <button wire:click="nouvelle" class="h-12 px-5 rounded-xl bg-accent text-white font-bold">+ Nouvelle boutique</button>
    </div>

    @if (session('info'))
        <p class="rounded-xl bg-accent-soft text-[#0B4F39] px-4 py-3 text-sm font-semibold">{{ session('info') }}</p>
    @endif
    @if ($alerte)
        <p class="rounded-xl bg-danger-bg text-danger-fg px-4 py-3 text-sm font-semibold">
            {{ $alerte }} Le plan Pro permet jusqu’à 5 boutiques : abonnez-vous depuis l’application Ngoni Caisse.
        </p>
    @elseif (! $peutCreer)
        <p class="rounded-xl bg-[--color-warn-bg] text-[--color-warn-fg] px-4 py-3 text-sm">
            Votre plan ne permet pas d’autre boutique. Le plan Pro en permet jusqu’à 5.
        </p>
    @endif

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        @foreach ($boutiques as $b)
            <div class="bg-white border rounded-2xl p-5 flex flex-col gap-2 {{ $b->id === $active ? 'border-accent border-2' : 'border-[--color-border]' }}" wire:key="boutique-{{ $b->id }}">
                <div class="flex items-start justify-between gap-2">
                    <h2 class="font-display font-extrabold text-lg">{{ $b->nom }}</h2>
                    @if ($b->id === $active)
                        <span class="text-xs font-bold rounded px-2 py-1 bg-accent-soft text-[#0B4F39]">Active</span>
                    @endif
                </div>
                <span class="text-sm text-[--color-muted]">{{ \App\Enums\Country::tryFrom((string) $b->pays)?->label() }} · devise <strong>{{ $b->devise }}</strong></span>
                @if ($b->adresse)<span class="text-sm">{{ $b->adresse }}</span>@endif
                <span class="text-xs text-[--color-muted]">{{ $b->proprietaire_id === auth()->id() ? 'Vous en êtes propriétaire' : 'Membre de l’équipe' }}</span>
                @if ($b->id === $active && $peutRegler)
                    <button wire:click="ouvrirReglages" class="mt-1 self-start h-9 px-3 rounded-lg border border-[--color-border-strong] text-xs font-bold">Réglages (nom, pays, devise…)</button>
                @endif
                @if ($b->id !== $active && in_array($b->id, auth()->user()->boutiquesBackOffice(), true))
                    <form method="POST" action="{{ route('boutique-active') }}" class="mt-1">
                        @csrf
                        <input type="hidden" name="boutique" value="{{ $b->id }}">
                        <button class="h-9 px-3 rounded-lg border border-[--color-border-strong] text-xs font-bold">Travailler dans cette boutique</button>
                    </form>
                @endif
            </div>
        @endforeach
    </div>

    @if ($modaleOuverte)
        <div class="fixed inset-0 bg-black/40 flex items-end md:items-center justify-center z-50 md:p-4" wire:click.self="$set('modaleOuverte', false)">
            <div class="bg-white rounded-t-2xl md:rounded-2xl p-6 w-full md:max-w-md max-h-[90vh] overflow-y-auto flex flex-col gap-4">
                <h2 class="font-display font-extrabold text-xl">Nouvelle boutique</h2>
                <form wire:submit="creer" class="flex flex-col gap-3">
                    <div>
                        <label class="block text-sm font-semibold mb-1">Nom de la boutique</label>
                        <input wire:model="nom" type="text" class="w-full h-11 px-3 rounded-lg border border-[--color-border-strong]">
                        @error('nom') <p class="text-sm text-danger-fg mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-semibold mb-1">Pays <span class="font-normal text-[--color-muted]">(fixe la devise)</span></label>
                        <x-choix-pays :liste-pays="$listePays" />
                    </div>
                    <div>
                        <label class="block text-sm font-semibold mb-1">Téléphone de la boutique <span class="font-normal text-[--color-muted]">(facultatif)</span></label>
                        <input wire:model="telephone" type="tel" class="w-full h-11 px-3 rounded-lg border border-[--color-border-strong]">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold mb-1">Adresse <span class="font-normal text-[--color-muted]">(facultatif)</span></label>
                        <input wire:model="adresse" type="text" class="w-full h-11 px-3 rounded-lg border border-[--color-border-strong]">
                    </div>
                    <div class="flex gap-3 mt-2">
                        <button type="button" wire:click="$set('modaleOuverte', false)" class="flex-1 h-11 rounded-lg border border-[--color-border-strong] font-bold">Annuler</button>
                        <button type="submit" class="flex-1 h-11 rounded-lg bg-accent text-white font-bold">Ouvrir la boutique</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    @if ($reglagesOuverts)
        <div class="fixed inset-0 bg-black/40 flex items-end md:items-center justify-center z-50 md:p-4" wire:click.self="$set('reglagesOuverts', false)">
            <div class="bg-white rounded-t-2xl md:rounded-2xl p-6 w-full md:max-w-md max-h-[90vh] overflow-y-auto flex flex-col gap-4">
                <h2 class="font-display font-extrabold text-xl">Réglages de la boutique</h2>
                <form wire:submit="enregistrerReglages" class="flex flex-col gap-3">
                    <div>
                        <label class="block text-sm font-semibold mb-1">Nom</label>
                        <input wire:model="reglages.nom" type="text" class="w-full h-11 px-3 rounded-lg border border-[--color-border-strong]">
                        @error('reglages.nom') <p class="text-sm text-danger-fg mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-semibold mb-1">Pays de la boutique</label>
                        <x-choix-pays model="reglages.pays" :liste-pays="$listePays" />
                    </div>
                    <div>
                        <label class="block text-sm font-semibold mb-1">Devise</label>
                        <select wire:model="reglages.devise" class="w-full h-11 px-3 rounded-lg border border-[--color-border-strong] bg-white">
                            @foreach ($devises as $code)
                                <option value="{{ $code }}">{{ $code }}</option>
                            @endforeach
                        </select>
                        @error('reglages.devise') <p class="text-sm text-danger-fg mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div class="rounded-xl bg-[#F7F5F0] p-3 text-sm flex flex-col gap-2">
                        <span class="font-semibold">Si la devise change (actuellement {{ $deviseInitiale }})</span>
                        <label class="flex items-start gap-2">
                            <input type="checkbox" wire:model="reglages.convertir" class="mt-1">
                            <span>Convertir les prix et les ventes. Franc CFA ↔ euro : au taux fixe 1 € = 655,957 F, automatiquement.</span>
                        </label>
                        <label class="block">
                            <span class="text-[--color-muted]">Autre devise : combien de {{ $deviseInitiale }} vaut 1 unité de la nouvelle devise ?</span>
                            <input wire:model="reglages.taux" type="text" inputmode="decimal" placeholder="ex. 655,957" class="mt-1 w-full h-10 px-3 rounded-lg border border-[--color-border-strong]">
                        </label>
                        @error('reglages.taux') <p class="text-sm text-danger-fg">{{ $message }}</p> @enderror
                        <span class="text-xs text-[--color-muted]">Décoché : les montants gardent les mêmes chiffres (300 F → 300,00 €).</span>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold mb-1">Téléphone (imprimé sur le ticket)</label>
                        <input wire:model="reglages.telephone" type="tel" class="w-full h-11 px-3 rounded-lg border border-[--color-border-strong]">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold mb-1">Adresse (imprimée sur le ticket)</label>
                        <input wire:model="reglages.adresse" type="text" class="w-full h-11 px-3 rounded-lg border border-[--color-border-strong]">
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-semibold mb-1">NIF <span class="font-normal text-[--color-muted]">(sur le ticket)</span></label>
                            <input wire:model="reglages.identifiant_fiscal" type="text" maxlength="60" class="w-full h-11 px-3 rounded-lg border border-[--color-border-strong]">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-1">RCCM <span class="font-normal text-[--color-muted]">(sur le ticket)</span></label>
                            <input wire:model="reglages.rccm" type="text" maxlength="60" class="w-full h-11 px-3 rounded-lg border border-[--color-border-strong]">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold mb-1">Message en bas du ticket</label>
                        <input wire:model="reglages.message_ticket" type="text" maxlength="160" placeholder="Merci de votre visite ! Les articles vendus ne sont ni repris ni échangés." class="w-full h-11 px-3 rounded-lg border border-[--color-border-strong]">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold mb-1">E-mail</label>
                        <input wire:model="reglages.email" type="email" class="w-full h-11 px-3 rounded-lg border border-[--color-border-strong]">
                        @error('reglages.email') <p class="text-sm text-danger-fg mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex gap-3 mt-2">
                        <button type="button" wire:click="$set('reglagesOuverts', false)" class="flex-1 h-11 rounded-lg border border-[--color-border-strong] font-bold">Annuler</button>
                        <button type="submit" class="flex-1 h-11 rounded-lg bg-accent text-white font-bold">Enregistrer</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
