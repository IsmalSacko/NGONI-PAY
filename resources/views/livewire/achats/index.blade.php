@php($m = fn ($v) => \App\Support\Money\Montant::format((int) $v))
@php($pharmacie = $pharmacie ?? false)
@php($mot = $pharmacie ? 'grossiste' : 'fournisseur')
@php($dette = $fournisseurs->sum(fn ($f) => max(0, (int) $f->achats_total - (int) $f->paiements_total)))
@php($creanciers = $fournisseurs->filter(fn ($f) => (int) $f->achats_total > (int) $f->paiements_total)->count())
<div class="flex flex-col gap-5">
    {{-- Même habit que l'écran Achats de l'application. --}}
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="flex flex-col gap-1">
            <h1 class="font-display font-extrabold text-2xl md:text-3xl text-accent">Achats</h1>
            <span class="text-muted text-sm">Réceptions de marchandise et ce que vous devez à vos {{ $mot }}s.</span>
        </div>
        @can('achats.create')
            <button wire:click="nouvelleReception" class="inline-flex items-center gap-2 h-12 px-5 rounded-xl bg-accent text-white font-bold shadow-carte">
                <x-charte.icone nom="local_shipping" />Réception de marchandise
            </button>
        @endcan
    </div>

    @if ($info)<p class="rounded-2xl bg-succes-doux text-succes-fonce px-4 py-3 text-sm font-bold">{{ $info }}</p>@endif

    <x-charte.carte-heros icone="local_shipping" :titre="$dette > 0 ? 'Vous devez à vos '.$mot.'s' : 'Vos '.$mot.'s'" :valeur="$dette > 0 ? $m($dette) : $fournisseurs->count().' '.$mot.($fournisseurs->count() > 1 ? 's' : '')">
        <span>{{ $dette > 0 ? 'À '.$creanciers.' '.$mot.($creanciers > 1 ? 's' : '').' sur '.$fournisseurs->count() : 'Vous ne devez rien' }}</span>
    </x-charte.carte-heros>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <x-charte.carte class="p-5 flex flex-col gap-3">
            <x-charte.en-tete-section icone="storefront" :titre="ucfirst($mot).'s'" sous-titre="Ce que vous leur devez, et de quoi les payer." />
            <div>
                @forelse ($fournisseurs as $f)
                    @php($doit = max(0, (int) $f->achats_total - (int) $f->paiements_total))
                    <div class="flex flex-wrap items-center gap-3 py-3 border-b border-separateur last:border-b-0" wire:key="f-{{ $f->id }}">
                        <x-charte.pastille icone="storefront" :taille="38" />
                        <span class="flex-1 min-w-0 flex flex-col">
                            <span class="font-bold text-ink truncate">{{ $f->nom }}</span>
                            <span class="text-xs text-muted">{{ $f->telephone ?: 'Sans téléphone' }}</span>
                        </span>
                        <x-charte.puce :ton="$doit > 0 ? 'danger' : 'succes'">{{ $doit > 0 ? 'Vous devez '.$m($doit) : 'Rien à payer' }}</x-charte.puce>
                        @can('achats.create')
                            <span class="flex gap-1">
                                @if ($doit > 0)
                                    <button wire:click="ouvrirPaiement('{{ $f->id }}')" class="h-9 px-3 rounded-xl bg-accent text-white text-xs font-bold">Payer</button>
                                @endif
                                <button wire:click="modifierFournisseur('{{ $f->id }}')" title="Modifier" class="w-9 h-9 rounded-xl bg-accent-soft text-accent inline-flex items-center justify-center"><x-charte.icone nom="edit" :taille="18" /></button>
                                <button wire:click="supprimerFournisseur('{{ $f->id }}')" wire:confirm="Supprimer {{ $f->nom }} ? Ses achats passés restent dans l’historique." title="Supprimer"
                                        class="w-9 h-9 rounded-xl bg-danger-bg text-danger-fg inline-flex items-center justify-center"><x-charte.icone nom="delete" :taille="18" /></button>
                            </span>
                        @endcan
                    </div>
                @empty
                    <x-charte.etat-vide icone="storefront" :titre="'Aucun '.$mot.' pour le moment.'" texte="Créez-le pendant une réception." />
                @endforelse
            </div>
            @error('fournisseur') <p class="rounded-xl bg-danger-bg text-danger-fg px-3 py-2 text-sm font-semibold">{{ $message }}</p> @enderror
        </x-charte.carte>
        <x-charte.carte class="p-5 flex flex-col gap-3">
            <x-charte.en-tete-section icone="receipt_long" titre="Dernières réceptions" sous-titre="Ce qui est entré en stock." />
            <div>
                @forelse ($achats as $a)
                    <div class="flex items-start gap-3 py-3 border-b border-separateur last:border-b-0" wire:key="a-{{ $a->id }}">
                        <x-charte.pastille icone="inventory" :taille="38" />
                        <span class="flex-1 min-w-0 flex flex-col">
                            <span class="font-bold text-ink truncate">{{ $a->fournisseur?->nom ?? 'Sans '.$mot }}@if ($a->reference) <span class="font-normal text-muted">· {{ $a->reference }}</span>@endif</span>
                            <span class="text-xs text-muted">{{ \App\Support\Fuseau::heure($a->created_at) }} · {{ $a->lignes->map(fn ($l) => $l->nom_produit.' × '.\App\Support\Quantite::formater($l->quantite, $l->unite))->join(', ') }}</span>
                        </span>
                        <span class="font-extrabold text-accent tabular-nums">{{ $m($a->total) }}</span>
                    </div>
                @empty
                    <x-charte.etat-vide icone="inventory" titre="Aucune réception pour l’instant." />
                @endforelse
            </div>
        </x-charte.carte>
    </div>

    @if ($receptionOuverte)
        <div class="fixed inset-0 bg-black/40 flex items-end md:items-center justify-center z-50 md:p-4" wire:click.self="$set('receptionOuverte', false)">
            <form wire:submit="enregistrerReception" class="bg-paper rounded-t-[24px] md:rounded-[24px] p-6 shadow-carte-haute w-full md:max-w-2xl max-h-[92vh] overflow-y-auto flex flex-col gap-3">
                <h2 class="font-display font-extrabold text-xl text-accent">Réception de marchandise</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <label class="text-sm font-semibold">Fournisseur
                        <select wire:model="fournisseurId" class="mt-1 w-full h-11 px-3 rounded-xl bg-white border border-border-strong bg-white font-normal">
                            <option value="">— Sans fournisseur (payé comptant) —</option>
                            @foreach ($fournisseurs as $f)<option value="{{ $f->id }}">{{ $f->nom }}</option>@endforeach
                        </select>
                        <button type="button" wire:click="$toggle('fournisseurOuvert')" class="mt-1 text-xs text-accent font-bold">+ Nouveau fournisseur</button>
                        @error('fournisseur_id') <span class="block text-danger-fg font-normal">{{ $message }}</span> @enderror
                    </label>
                    <label class="text-sm font-semibold">N° de bon / facture <span class="font-normal text-muted">(facultatif)</span>
                        <input wire:model="reference" type="text" maxlength="60" class="mt-1 w-full h-11 px-3 rounded-xl bg-white border border-border-strong font-normal">
                    </label>
                </div>
                @if ($fournisseurOuvert)
                    <div class="flex flex-wrap gap-2 items-end rounded-xl bg-fond-tableau p-3">
                        <input wire:model="nomFournisseur" type="text" placeholder="Nom du fournisseur" class="h-10 px-3 rounded-xl bg-white border border-border-strong flex-1 min-w-0">
                        <input wire:model="telFournisseur" type="tel" placeholder="Téléphone" class="h-10 px-3 rounded-xl bg-white border border-border-strong w-40">
                        <button type="button" wire:click="creerFournisseur" class="h-10 px-4 rounded-lg bg-ink text-white font-bold">Créer</button>
                        @error('nomFournisseur') <span class="w-full text-sm text-danger-fg">{{ $message }}</span> @enderror
                    </div>
                @endif

                <div class="flex flex-col gap-2">
                    <span class="text-sm font-semibold">Articles reçus</span>
                    @foreach ($lignes as $i => $l)
                        <div class="grid grid-cols-[1fr_70px_110px_32px] gap-2 items-center" wire:key="l-{{ $i }}">
                            <select wire:model.live="lignes.{{ $i }}.produit_id" class="h-10 px-2 rounded-xl bg-white border border-border-strong bg-white min-w-0">
                                <option value="">Article…</option>
                                @foreach ($produits as $p)<option value="{{ $p->id }}">{{ $p->nom }}{{ $p->format ? ' · '.$p->format : '' }}</option>@endforeach
                            </select>
                            <input wire:model="lignes.{{ $i }}.quantite" type="text" inputmode="decimal" placeholder="Qté" class="h-10 px-2 rounded-xl bg-white border border-border-strong">
                            <input wire:model="lignes.{{ $i }}.prix_achat" type="text" inputmode="decimal" placeholder="Prix d’achat" class="h-10 px-2 rounded-xl bg-white border border-border-strong">
                            <button type="button" wire:click="retirerLigne({{ $i }})" class="h-10 rounded-lg text-danger-fg font-bold" title="Retirer">×</button>
                            @php($choisi = $produits->firstWhere('id', $l['produit_id']))
                            @if ($choisi && ! empty($choisi->paliers))
                                {{-- Reçu au carton, à la boîte… : le stock compte les unités de base. --}}
                                <select wire:model="lignes.{{ $i }}.palier" class="col-span-4 h-9 px-2 rounded-xl bg-white border border-border-strong bg-white text-sm">
                                    <option value="">À l'unité ({{ $choisi->unite ? \App\Support\Quantite::LIBELLES[$choisi->unite] ?? $choisi->unite : 'pièce' }})</option>
                                    @foreach (collect($choisi->paliers)->sortByDesc('contenance') as $pl)
                                        <option value="{{ $pl['unite'] }}">{{ \App\Support\Quantite::LIBELLES[$pl['unite']] ?? $pl['unite'] }} de {{ $pl['contenance'] }}</option>
                                    @endforeach
                                </select>
                            @endif
                            @if ($pharmacie)
                                <input wire:model="lignes.{{ $i }}.numero_lot" type="text" placeholder="N° de lot" class="col-span-2 h-9 px-2 rounded-xl bg-white border border-border-strong text-sm">
                                <input wire:model="lignes.{{ $i }}.peremption" type="date" title="Date de péremption" class="col-span-2 h-9 px-2 rounded-xl bg-white border border-border-strong text-sm">
                            @endif
                            @error("lignes.$i") <span class="col-span-4 text-xs text-danger-fg">{{ $message }}</span> @enderror
                        </div>
                    @endforeach
                    <button type="button" wire:click="ajouterLigne" class="self-start text-sm text-accent font-bold">+ Ajouter un article</button>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <label class="text-sm font-semibold">Payé maintenant <span class="font-normal text-muted">(le reste s’ajoute à la dette)</span>
                        <input wire:model="montantPaye" type="text" inputmode="decimal" placeholder="0" class="mt-1 w-full h-11 px-3 rounded-xl bg-white border border-border-strong font-normal">
                        @error('montant_paye') <span class="block text-danger-fg font-normal">{{ $message }}</span> @enderror
                    </label>
                    <label class="text-sm font-semibold">Payé par
                        <select wire:model="moyen" class="mt-1 w-full h-11 px-3 rounded-xl bg-white border border-border-strong bg-white font-normal">
                            @foreach (['especes' => 'Espèces', 'orange_money' => 'Orange Money', 'moov_money' => 'Moov Money', 'wave' => 'Wave', 'carte' => 'Carte', 'virement' => 'Virement'] as $c => $lib)<option value="{{ $c }}">{{ $lib }}</option>@endforeach
                        </select>
                    </label>
                </div>
                <div class="flex gap-3 mt-2">
                    <button type="button" wire:click="$set('receptionOuverte', false)" class="flex-1 h-11 rounded-xl bg-white border border-border-strong font-bold">Annuler</button>
                    <button type="submit" class="flex-1 h-11 rounded-xl bg-accent text-white font-bold">Enregistrer la réception</button>
                </div>
            </form>
        </div>
    @endif

    @if ($fournisseurEdite)
        <div class="fixed inset-0 bg-black/40 flex items-end md:items-center justify-center z-50 md:p-4" wire:click.self="$set('fournisseurEdite', null)">
            <form wire:submit="enregistrerFournisseur" class="bg-paper rounded-t-[24px] md:rounded-[24px] p-6 shadow-carte-haute w-full md:max-w-sm flex flex-col gap-3">
                <h2 class="font-display font-extrabold text-xl text-accent">Modifier le fournisseur</h2>
                <label class="text-sm font-semibold">Nom
                    <input wire:model="nomEdite" type="text" class="mt-1 w-full h-11 px-3 rounded-xl bg-white border border-border-strong font-normal">
                </label>
                @error('nomEdite') <p class="text-sm text-danger-fg">{{ $message }}</p> @enderror
                <label class="text-sm font-semibold">Téléphone
                    <input wire:model="telEdite" type="tel" class="mt-1 w-full h-11 px-3 rounded-xl bg-white border border-border-strong font-normal">
                    <span class="block text-xs text-muted font-normal mt-1">Pour lui envoyer vos commandes sur WhatsApp depuis l’application.</span>
                </label>
                @error('telEdite') <p class="text-sm text-danger-fg">{{ $message }}</p> @enderror
                <div class="flex gap-3 mt-2">
                    <button type="button" wire:click="$set('fournisseurEdite', null)" class="flex-1 h-11 rounded-xl bg-white border border-border-strong font-bold">Annuler</button>
                    <button type="submit" class="flex-1 h-11 rounded-xl bg-accent text-white font-bold">Enregistrer</button>
                </div>
            </form>
        </div>
    @endif

    @if ($paiementPour)
        <div class="fixed inset-0 bg-black/40 flex items-end md:items-center justify-center z-50 md:p-4" wire:click.self="$set('paiementPour', null)">
            <form wire:submit="payer" class="bg-paper rounded-t-[24px] md:rounded-[24px] p-6 shadow-carte-haute w-full md:max-w-sm flex flex-col gap-3">
                <h2 class="font-display font-extrabold text-xl text-accent">Paiement fournisseur</h2>
                <label class="text-sm font-semibold">Montant
                    <input wire:model="paiementMontant" type="text" inputmode="decimal" class="mt-1 w-full h-11 px-3 rounded-xl bg-white border border-border-strong font-normal">
                </label>
                @error('paiementMontant') <p class="text-sm text-danger-fg">{{ $message }}</p> @enderror
                <label class="text-sm font-semibold">Payé par
                    <select wire:model="moyen" class="mt-1 w-full h-11 px-3 rounded-xl bg-white border border-border-strong bg-white font-normal">
                        @foreach (['especes' => 'Espèces', 'orange_money' => 'Orange Money', 'moov_money' => 'Moov Money', 'wave' => 'Wave', 'carte' => 'Carte', 'virement' => 'Virement'] as $c => $lib)<option value="{{ $c }}">{{ $lib }}</option>@endforeach
                    </select>
                </label>
                <div class="flex gap-3 mt-2">
                    <button type="button" wire:click="$set('paiementPour', null)" class="flex-1 h-11 rounded-xl bg-white border border-border-strong font-bold">Annuler</button>
                    <button type="submit" class="flex-1 h-11 rounded-xl bg-accent text-white font-bold">Enregistrer</button>
                </div>
            </form>
        </div>
    @endif
</div>
