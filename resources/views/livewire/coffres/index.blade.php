@php($m = fn ($v) => $visible ? \App\Support\Money\Montant::format((int) $v) : '• • • •')
@php($part = fn ($p) => str_replace('.', ',', (string) (0 + (float) $p)))
@php($regle = fn ($c) => match ($c->mode) {
    \App\Models\Coffre::FIXE => \App\Support\Money\Montant::format((int) $c->montant_fixe).' chaque soir',
    \App\Models\Coffre::POURCENTAGE => $part($c->pourcentage).' % des ventes encaissées, chaque soir',
    default => 'Rempli par vous-même',
})
@php($reste = $encaisse - (int) $epargne['mis_de_cote'] + (int) $epargne['repris'])
<div class="flex flex-col gap-5">
    {{-- Même habit que l'écran Coffre de l'application. --}}
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="flex flex-col gap-1">
            <h1 class="font-display font-extrabold text-2xl md:text-3xl text-accent">Coffre</h1>
            <span class="text-muted text-sm">Ngoni compte ce que vous mettez de côté ; il ne déplace pas l’argent.</span>
        </div>
        <div class="flex gap-2">
            <button wire:click="$toggle('visible')" title="{{ $visible ? 'Cacher les montants' : 'Afficher les montants' }}"
                    class="inline-flex items-center gap-2 h-12 px-4 rounded-xl bg-white border border-border-strong font-bold text-accent">
                <x-charte.icone :nom="$visible ? 'visibility_off' : 'visibility'" />{{ $visible ? 'Cacher' : 'Afficher' }}
            </button>
            <button wire:click="nouveau" class="inline-flex items-center gap-2 h-12 px-5 rounded-xl bg-accent text-white font-bold shadow-carte">
                <x-charte.icone nom="add" />Nouveau coffre
            </button>
        </div>
    </div>

    @if ($info)<p class="rounded-2xl bg-succes-doux text-succes-fonce px-4 py-3 text-sm font-bold">{{ $info }}</p>@endif
    @error('coffre') <p class="rounded-xl bg-danger-bg text-danger-fg px-3 py-2 text-sm font-semibold">{{ $message }}</p> @enderror

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <x-charte.carte-heros icone="savings" titre="Mis de côté" :valeur="$m($total)">
            <span>{{ $coffres->count() }} coffre{{ $coffres->count() > 1 ? 's' : '' }}</span>
        </x-charte.carte-heros>
        <x-charte.carte class="p-5 flex flex-col gap-1">
            <x-charte.en-tete-section icone="account_balance_wallet" titre="Ce mois-ci" sous-titre="Le chiffre d’affaires et le bénéfice ne changent pas." />
            <div class="mt-2 flex justify-between"><span>Encaissé</span><span class="font-bold tabular-nums">{{ $m($encaisse) }}</span></div>
            <div class="flex justify-between"><span>Mis de côté</span><span class="font-bold tabular-nums">{{ $visible ? '−' : '' }}{{ $m($epargne['mis_de_cote']) }}</span></div>
            @if ((int) $epargne['repris'] > 0)
                <div class="flex justify-between"><span>Repris du coffre</span><span class="font-bold tabular-nums">{{ $visible ? '+' : '' }}{{ $m($epargne['repris']) }}</span></div>
            @endif
            <div class="mt-1 pt-2 border-t border-separateur flex justify-between font-extrabold text-accent"><span>Reste disponible</span><span class="tabular-nums">{{ $m($reste) }}</span></div>
        </x-charte.carte>
    </div>

    <x-charte.carte class="p-5 flex flex-col gap-3">
        <x-charte.en-tete-section icone="savings" titre="Vos coffres"
            sous-titre="Chaque soir à 23 h, les coffres automatiques prennent leur part de la recette du jour, jamais plus que ce qui a été encaissé, et rien un jour sans vente." />
        <div>
            @forelse ($coffres as $c)
                @php($solde = $soldes[$c->id] ?? 0)
                <div class="flex flex-wrap items-center gap-3 py-3 border-b border-separateur last:border-b-0" wire:key="c-{{ $c->id }}">
                    <x-charte.pastille :icone="$c->mode === \App\Models\Coffre::MANUEL ? 'savings' : 'autorenew'" :taille="38" />
                    <button wire:click="$set('ouvert', '{{ $c->id }}')" class="flex-1 min-w-0 flex flex-col text-left">
                        <span class="font-bold text-ink truncate">{{ $c->nom }}</span>
                        <span class="text-xs text-muted">{{ $regle($c) }}</span>
                    </button>
                    <span class="font-extrabold text-accent tabular-nums">{{ $m($solde) }}</span>
                    <span class="flex gap-1">
                        <button wire:click="ouvrirMouvement('{{ $c->id }}', true)" class="h-9 px-3 rounded-xl bg-accent text-white text-xs font-bold">Ajouter</button>
                        @if ($solde > 0)
                            <button wire:click="ouvrirMouvement('{{ $c->id }}', false)" class="h-9 px-3 rounded-xl bg-white border border-border-strong text-xs font-bold">Retirer</button>
                        @endif
                        <button wire:click="modifier('{{ $c->id }}')" title="Modifier la règle" class="w-9 h-9 rounded-xl bg-accent-soft text-accent inline-flex items-center justify-center"><x-charte.icone nom="tune" :taille="18" /></button>
                        @if ($solde === 0)
                            <button wire:click="supprimer('{{ $c->id }}')" wire:confirm="Supprimer {{ $c->nom }} ? Le coffre est vide : il disparaît avec son historique." title="Supprimer"
                                    class="w-9 h-9 rounded-xl bg-danger-bg text-danger-fg inline-flex items-center justify-center"><x-charte.icone nom="delete" :taille="18" /></button>
                        @endif
                    </span>
                </div>
            @empty
                <x-charte.etat-vide icone="savings" titre="Aucun coffre pour l’instant." texte="Créez-en un par projet : « Loyer », « Tabaski », « Réserve »…" />
            @endforelse
        </div>
    </x-charte.carte>

    @if ($fiche)
        <x-charte.carte class="p-5 flex flex-col gap-3">
            <div class="flex items-center justify-between gap-3">
                <x-charte.en-tete-section icone="history" :titre="'Historique · '.$fiche->nom" :sous-titre="$regle($fiche)" />
                <button wire:click="$set('ouvert', null)" class="h-9 px-3 rounded-xl bg-white border border-border-strong text-xs font-bold">Fermer</button>
            </div>
            <div>
                @forelse ($historique as $mv)
                    <div class="flex items-center gap-3 py-2 border-b border-separateur last:border-b-0" wire:key="mv-{{ $mv->id }}">
                        <x-charte.icone :nom="$mv->type === 'retrait' ? 'south_west' : 'north_east'" class="{{ $mv->type === 'retrait' ? 'text-danger-fg' : 'text-succes-fonce' }}" />
                        <span class="flex-1 min-w-0 flex flex-col">
                            <span class="font-semibold">{{ match ($mv->type) { 'automatique' => 'Mis de côté le soir'.($mv->base !== null ? ' · ventes '.$m($mv->base) : ''), 'retrait' => 'Retiré', default => 'Ajouté' } }}</span>
                            <span class="text-xs text-muted">{{ $mv->jour->translatedFormat('j F Y') }}{{ $mv->auteur ? ' · '.$mv->auteur->name : '' }}</span>
                        </span>
                        <span class="font-bold tabular-nums">{{ $mv->type === 'retrait' ? '−' : '+' }}{{ $m($mv->montant) }}</span>
                    </div>
                @empty
                    <p class="text-muted text-sm py-2">Rien pour l’instant.</p>
                @endforelse
            </div>
        </x-charte.carte>
    @endif

    @if ($edite !== null)
        <div class="fixed inset-0 bg-black/40 flex items-end md:items-center justify-center z-50 md:p-4" wire:click.self="$set('edite', null)">
            <form wire:submit="enregistrer" class="bg-paper rounded-t-[24px] md:rounded-[24px] p-6 shadow-carte-haute w-full md:max-w-md flex flex-col gap-3">
                <h2 class="font-display font-extrabold text-xl text-accent">{{ $edite === '' ? 'Nouveau coffre' : 'Modifier le coffre' }}</h2>
                <label class="text-sm font-semibold">Nom (ex. Loyer, Tabaski)
                    <input wire:model="nom" type="text" maxlength="40" class="mt-1 w-full h-11 px-3 rounded-xl bg-white border border-border-strong font-normal">
                </label>
                @error('nom') <p class="text-sm text-danger-fg">{{ $message }}</p> @enderror
                <fieldset class="flex flex-col gap-1">
                    <legend class="text-sm font-semibold mb-1">Comment le remplir ?</legend>
                    @foreach ([\App\Models\Coffre::MANUEL => 'Moi-même', \App\Models\Coffre::FIXE => 'Une somme chaque jour', \App\Models\Coffre::POURCENTAGE => 'Un pourcentage de mes ventes (%)'] as $code => $libelle)
                        <label class="flex items-center gap-2 h-10"><input type="radio" wire:model.live="mode" value="{{ $code }}"> {{ $libelle }}</label>
                    @endforeach
                </fieldset>
                @if ($mode === \App\Models\Coffre::FIXE)
                    <label class="text-sm font-semibold">Somme par jour
                        <input wire:model="montantFixe" type="text" inputmode="decimal" class="mt-1 w-full h-11 px-3 rounded-xl bg-white border border-border-strong font-normal">
                    </label>
                    @error('montantFixe') <p class="text-sm text-danger-fg">{{ $message }}</p> @enderror
                @elseif ($mode === \App\Models\Coffre::POURCENTAGE)
                    @php($taux = (float) str_replace(',', '.', $pourcentage))
                    @php($taux = $taux > 0 && $taux <= 100 ? $taux : 10)
                    <label class="text-sm font-semibold">Pourcentage (ex. 10)
                        <span class="mt-1 flex items-center gap-2">
                            <input wire:model.live.debounce.300ms="pourcentage" type="text" inputmode="decimal" class="w-full h-11 px-3 rounded-xl bg-white border border-border-strong font-normal">
                            <span class="font-extrabold">%</span>
                        </span>
                        <span class="block text-xs text-muted font-normal mt-1">Ex. : {{ \App\Support\Money\Montant::format(20000) }} vendus → {{ \App\Support\Money\Montant::format((int) floor(20000 * $taux / 100)) }} mis de côté ({{ $part($taux) }} %)</span>
                    </label>
                    @error('pourcentage') <p class="text-sm text-danger-fg">{{ $message }}</p> @enderror
                @endif
                @if ($mode !== \App\Models\Coffre::MANUEL)
                    <p class="text-xs text-muted">Chaque soir à 23 h, sur l’argent encaissé dans la journée (tous moyens confondus). Un jour sans vente, rien n’est mis de côté.</p>
                @endif
                <div class="flex gap-3 mt-2">
                    <button type="button" wire:click="$set('edite', null)" class="flex-1 h-11 rounded-xl bg-white border border-border-strong font-bold">Annuler</button>
                    <button type="submit" class="flex-1 h-11 rounded-xl bg-accent text-white font-bold">Enregistrer</button>
                </div>
            </form>
        </div>
    @endif

    @if ($mouvementPour)
        @php($cible = $coffres->firstWhere('id', $mouvementPour))
        <div class="fixed inset-0 bg-black/40 flex items-end md:items-center justify-center z-50 md:p-4" wire:click.self="$set('mouvementPour', null)">
            <form wire:submit="enregistrerMouvement" class="bg-paper rounded-t-[24px] md:rounded-[24px] p-6 shadow-carte-haute w-full md:max-w-sm flex flex-col gap-3">
                <h2 class="font-display font-extrabold text-xl text-accent">{{ $ajout ? 'Ajouter à' : 'Retirer de' }} « {{ $cible?->nom }} »</h2>
                <label class="text-sm font-semibold">Montant
                    <input wire:model="montant" type="text" inputmode="decimal" autofocus class="mt-1 w-full h-11 px-3 rounded-xl bg-white border border-border-strong font-normal">
                </label>
                @error('montant') <p class="text-sm text-danger-fg">{{ $message }}</p> @enderror
                <div class="flex gap-3 mt-2">
                    <button type="button" wire:click="$set('mouvementPour', null)" class="flex-1 h-11 rounded-xl bg-white border border-border-strong font-bold">Annuler</button>
                    <button type="submit" class="flex-1 h-11 rounded-xl bg-accent text-white font-bold">{{ $ajout ? 'Ajouter' : 'Retirer' }}</button>
                </div>
            </form>
        </div>
    @endif
</div>
