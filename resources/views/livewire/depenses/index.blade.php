@php($m = fn ($v) => \App\Support\Money\Montant::format((int) $v))
@php($positif = (int) $bilan['resultat'] >= 0)
@php($lourdes = collect($bilan['depenses']['par_categorie'])->where('total', '>', 0)->sortByDesc('total')->take(3))
<div class="flex flex-col gap-5">
    {{-- Même habit que l'écran Dépenses et la carte Bilan de l'application. --}}
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="flex flex-col gap-1">
            <h1 class="font-display font-extrabold text-2xl md:text-3xl text-accent">Dépenses</h1>
            <span class="text-muted text-sm">Loyer, salaires, électricité… et ce qui vous reste une fois tout payé.</span>
        </div>
        @can('ventes.create')
            <button wire:click="nouvelle" class="inline-flex items-center gap-2 h-12 px-5 rounded-xl bg-accent text-white font-bold shadow-carte">
                <x-charte.icone nom="add" />Nouvelle dépense
            </button>
        @endcan
    </div>

    <div class="flex items-center justify-center gap-3">
        <button wire:click="moisPrecedent" title="Mois précédent" class="w-10 h-10 rounded-xl bg-white border border-border-strong inline-flex items-center justify-center"><x-charte.icone nom="chevron_left" /></button>
        <span class="font-extrabold text-lg min-w-40 text-center">{{ $titreMois }}</span>
        <button wire:click="moisSuivant" title="Mois suivant" @disabled($dernierMois) class="w-10 h-10 rounded-xl bg-white border border-border-strong inline-flex items-center justify-center disabled:opacity-40"><x-charte.icone nom="chevron_right" /></button>
    </div>

    @if ($info)<p class="rounded-2xl bg-succes-doux text-succes-fonce px-4 py-3 text-sm font-bold">{{ $info }}</p>@endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <x-charte.carte class="p-5 flex flex-col gap-1">
            <x-charte.en-tete-section icone="account_balance" titre="Bilan" sous-titre="L’argent reçu, moins les dépenses : ce qui vous reste." />
            <div class="mt-2 flex justify-between"><span>Recettes (argent reçu)</span><span class="font-bold tabular-nums text-succes-fonce">+{{ $m($bilan['recettes']['total']) }}</span></div>
            <div class="flex justify-between"><span>Dépenses</span><span class="font-bold tabular-nums">{{ (int) $bilan['depenses']['total'] > 0 ? '−' : '' }}{{ $m($bilan['depenses']['total']) }}</span></div>
            @if ((int) $bilan['fournisseurs'] > 0)
                <div class="flex justify-between"><span>Payé aux fournisseurs</span><span class="font-bold tabular-nums">−{{ $m($bilan['fournisseurs']) }}</span></div>
            @endif
            <div class="mt-1 pt-2 border-t border-separateur flex justify-between font-extrabold text-lg {{ $positif ? 'text-succes-fonce' : 'text-danger-fg' }}">
                <span>{{ $positif ? 'Il vous reste' : 'Il vous manque' }}</span><span class="tabular-nums">{{ $positif ? '' : '−' }}{{ $m(abs((int) $bilan['resultat'])) }}</span>
            </div>
            @if ($lourdes->isNotEmpty())
                <p class="text-xs text-muted mt-1">{{ $lourdes->map(fn ($c) => $c['libelle'].' '.$m($c['total']))->join(' · ') }}</p>
            @endif
        </x-charte.carte>
        <x-charte.carte-heros icone="payments" titre="Dépenses du mois" :valeur="$m($depenses->sum('montant'))">
            <span>{{ $depenses->count() }} dépense{{ $depenses->count() > 1 ? 's' : '' }}</span>
        </x-charte.carte-heros>
    </div>

    <x-charte.carte class="p-5 flex flex-col gap-3">
        <x-charte.en-tete-section icone="receipt_long" titre="Les dépenses" sous-titre="Payées en espèces, elles sortent de la caisse ouverte." />
        <div>
            @forelse ($depenses as $d)
                <div class="flex flex-wrap items-center gap-3 py-3 border-b border-separateur last:border-b-0" wire:key="d-{{ $d->id }}">
                    <x-charte.pastille icone="outbox" :taille="38" />
                    <span class="flex-1 min-w-0 flex flex-col">
                        <span class="font-bold text-ink truncate">{{ $d->libelle }}</span>
                        <span class="text-xs text-muted">{{ $categories[$d->categorie] ?? $d->categorie }} · {{ \App\Enums\MoyenPaiement::tryFrom((string) $d->moyen_paiement)?->label() ?? $d->moyen_paiement }} · {{ $d->jour->locale('fr')->translatedFormat('j F') }}{{ $d->auteur ? ' · '.$d->auteur->name : '' }}</span>
                    </span>
                    <span class="font-extrabold text-accent tabular-nums">{{ $m($d->montant) }}</span>
                    @can('ventes.delete')
                        <button wire:click="supprimer('{{ $d->id }}')" wire:confirm="Supprimer « {{ $d->libelle }} » ?" title="Supprimer"
                                class="w-9 h-9 rounded-xl bg-danger-bg text-danger-fg inline-flex items-center justify-center"><x-charte.icone nom="delete" :taille="18" /></button>
                    @endcan
                </div>
            @empty
                <x-charte.etat-vide icone="receipt_long" titre="Aucune dépense ce mois-ci." />
            @endforelse
        </div>
    </x-charte.carte>

    @if ($formulaire)
        <div class="fixed inset-0 bg-black/40 flex items-end md:items-center justify-center z-50 md:p-4" wire:click.self="$set('formulaire', false)">
            <form wire:submit="enregistrer" class="bg-paper rounded-t-[24px] md:rounded-[24px] p-6 shadow-carte-haute w-full md:max-w-md flex flex-col gap-3">
                <h2 class="font-display font-extrabold text-xl text-accent">Nouvelle dépense</h2>
                <label class="text-sm font-semibold">Quoi (ex. Électricité, lessive)
                    <input wire:model="libelle" type="text" maxlength="120" class="mt-1 w-full h-11 px-3 rounded-xl bg-white border border-border-strong font-normal">
                </label>
                @error('libelle') <p class="text-sm text-danger-fg">{{ $message }}</p> @enderror
                <label class="text-sm font-semibold">Montant
                    <input wire:model="montant" type="text" inputmode="decimal" class="mt-1 w-full h-11 px-3 rounded-xl bg-white border border-border-strong font-normal">
                </label>
                @error('montant') <p class="text-sm text-danger-fg">{{ $message }}</p> @enderror
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <label class="text-sm font-semibold">Catégorie
                        <select wire:model="categorie" class="mt-1 w-full h-11 px-3 rounded-xl bg-white border border-border-strong font-normal">
                            @foreach ($categories as $code => $lib)<option value="{{ $code }}">{{ $lib }}</option>@endforeach
                        </select>
                    </label>
                    <label class="text-sm font-semibold">Payée par
                        <select wire:model="moyen" class="mt-1 w-full h-11 px-3 rounded-xl bg-white border border-border-strong font-normal">
                            @foreach ($moyens as $code => $lib)<option value="{{ $code }}">{{ $lib }}</option>@endforeach
                        </select>
                    </label>
                </div>
                <label class="text-sm font-semibold">Jour
                    <input wire:model="jour" type="date" max="{{ now()->toDateString() }}" class="mt-1 w-full h-11 px-3 rounded-xl bg-white border border-border-strong font-normal">
                </label>
                @error('jour') <p class="text-sm text-danger-fg">{{ $message }}</p> @enderror
                <p class="text-xs text-muted">Elle sort de la caisse ouverte, quel que soit le moyen.</p>
                <div class="flex gap-3 mt-2">
                    <button type="button" wire:click="$set('formulaire', false)" class="flex-1 h-11 rounded-xl bg-white border border-border-strong font-bold">Annuler</button>
                    <button type="submit" class="flex-1 h-11 rounded-xl bg-accent text-white font-bold">Enregistrer</button>
                </div>
            </form>
        </div>
    @endif
</div>
