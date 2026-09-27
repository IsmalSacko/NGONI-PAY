@php($m = fn ($v) => \App\Support\Money\Montant::format((int) $v))
<div class="flex flex-col gap-5">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="flex flex-col gap-1">
            <h1 class="font-display font-extrabold text-2xl md:text-3xl">Achats</h1>
            <span class="text-[--color-muted] text-sm">Réceptions de marchandise et ce que vous devez à vos fournisseurs.</span>
        </div>
        @can('achats.create')
            <button wire:click="nouvelleReception" class="h-12 px-5 rounded-xl bg-accent text-white font-bold">+ Réception de marchandise</button>
        @endcan
    </div>

    @if ($info)<p class="rounded-xl bg-accent-soft text-accent-dark px-4 py-3 text-sm font-semibold">{{ $info }}</p>@endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <section class="bg-white border border-[--color-border] rounded-2xl p-5">
            <h2 class="font-bold mb-3">Fournisseurs</h2>
            @forelse ($fournisseurs as $f)
                @php($doit = max(0, (int) $f->achats_total - (int) $f->paiements_total))
                <div class="flex flex-wrap items-center gap-2 justify-between py-2 border-b border-separateur text-sm" wire:key="f-{{ $f->id }}">
                    <span><span class="font-semibold">{{ $f->nom }}</span> <span class="text-[--color-muted]">{{ $f->telephone }}</span></span>
                    <span class="flex items-center gap-2">
                        <span class="font-bold {{ $doit > 0 ? 'text-danger-fg' : 'text-[--color-muted]' }}">{{ $doit > 0 ? 'Vous devez '.$m($doit) : 'Rien à payer' }}</span>
                        @if ($doit > 0)
                            @can('achats.create')
                                <button wire:click="ouvrirPaiement('{{ $f->id }}')" class="h-8 px-3 rounded-lg bg-accent text-white text-xs font-bold">Payer</button>
                            @endcan
                        @endif
                    </span>
                </div>
            @empty
                <p class="text-sm text-[--color-muted]">Aucun fournisseur. Créez-le pendant une réception.</p>
            @endforelse
        </section>
        <section class="bg-white border border-[--color-border] rounded-2xl p-5">
            <h2 class="font-bold mb-3">Dernières réceptions</h2>
            @forelse ($achats as $a)
                <div class="py-2 border-b border-separateur text-sm" wire:key="a-{{ $a->id }}">
                    <div class="flex justify-between"><span class="font-semibold">{{ $a->created_at->format('d/m/Y H:i') }} · {{ $a->fournisseur?->nom ?? 'Sans fournisseur' }} @if ($a->reference) · {{ $a->reference }} @endif</span><span class="font-bold">{{ $m($a->total) }}</span></div>
                    <div class="text-xs text-[--color-muted]">{{ $a->lignes->map(fn ($l) => $l->nom_produit.' × '.$l->quantite)->join(', ') }}</div>
                </div>
            @empty
                <p class="text-sm text-[--color-muted]">Aucune réception pour l’instant.</p>
            @endforelse
        </section>
    </div>

    @if ($receptionOuverte)
        <div class="fixed inset-0 bg-black/40 flex items-end md:items-center justify-center z-50 md:p-4" wire:click.self="$set('receptionOuverte', false)">
            <form wire:submit="enregistrerReception" class="bg-white rounded-t-2xl md:rounded-2xl p-6 w-full md:max-w-2xl max-h-[92vh] overflow-y-auto flex flex-col gap-3">
                <h2 class="font-display font-extrabold text-xl">Réception de marchandise</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <label class="text-sm font-semibold">Fournisseur
                        <select wire:model="fournisseurId" class="mt-1 w-full h-11 px-3 rounded-lg border border-[--color-border-strong] bg-white font-normal">
                            <option value="">— Sans fournisseur (payé comptant) —</option>
                            @foreach ($fournisseurs as $f)<option value="{{ $f->id }}">{{ $f->nom }}</option>@endforeach
                        </select>
                        <button type="button" wire:click="$toggle('fournisseurOuvert')" class="mt-1 text-xs text-accent font-bold">+ Nouveau fournisseur</button>
                        @error('fournisseur_id') <span class="block text-danger-fg font-normal">{{ $message }}</span> @enderror
                    </label>
                    <label class="text-sm font-semibold">N° de bon / facture <span class="font-normal text-[--color-muted]">(facultatif)</span>
                        <input wire:model="reference" type="text" maxlength="60" class="mt-1 w-full h-11 px-3 rounded-lg border border-[--color-border-strong] font-normal">
                    </label>
                </div>
                @if ($fournisseurOuvert)
                    <div class="flex flex-wrap gap-2 items-end rounded-xl bg-fond-tableau p-3">
                        <input wire:model="nomFournisseur" type="text" placeholder="Nom du fournisseur" class="h-10 px-3 rounded-lg border border-[--color-border-strong] flex-1 min-w-0">
                        <input wire:model="telFournisseur" type="tel" placeholder="Téléphone" class="h-10 px-3 rounded-lg border border-[--color-border-strong] w-40">
                        <button type="button" wire:click="creerFournisseur" class="h-10 px-4 rounded-lg bg-ink text-white font-bold">Créer</button>
                        @error('nomFournisseur') <span class="w-full text-sm text-danger-fg">{{ $message }}</span> @enderror
                    </div>
                @endif

                <div class="flex flex-col gap-2">
                    <span class="text-sm font-semibold">Articles reçus</span>
                    @foreach ($lignes as $i => $l)
                        <div class="grid grid-cols-[1fr_70px_110px_32px] gap-2 items-center" wire:key="l-{{ $i }}">
                            <select wire:model.live="lignes.{{ $i }}.produit_id" class="h-10 px-2 rounded-lg border border-[--color-border-strong] bg-white min-w-0">
                                <option value="">Article…</option>
                                @foreach ($produits as $p)<option value="{{ $p->id }}">{{ $p->nom }}{{ $p->format ? ' · '.$p->format : '' }}</option>@endforeach
                            </select>
                            <input wire:model="lignes.{{ $i }}.quantite" type="number" min="1" placeholder="Qté" class="h-10 px-2 rounded-lg border border-[--color-border-strong]">
                            <input wire:model="lignes.{{ $i }}.prix_achat" type="text" inputmode="decimal" placeholder="Prix d’achat" class="h-10 px-2 rounded-lg border border-[--color-border-strong]">
                            <button type="button" wire:click="retirerLigne({{ $i }})" class="h-10 rounded-lg text-danger-fg font-bold" title="Retirer">×</button>
                            @error("lignes.$i") <span class="col-span-4 text-xs text-danger-fg">{{ $message }}</span> @enderror
                        </div>
                    @endforeach
                    <button type="button" wire:click="ajouterLigne" class="self-start text-sm text-accent font-bold">+ Ajouter un article</button>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <label class="text-sm font-semibold">Payé maintenant <span class="font-normal text-[--color-muted]">(le reste s’ajoute à la dette)</span>
                        <input wire:model="montantPaye" type="text" inputmode="decimal" placeholder="0" class="mt-1 w-full h-11 px-3 rounded-lg border border-[--color-border-strong] font-normal">
                        @error('montant_paye') <span class="block text-danger-fg font-normal">{{ $message }}</span> @enderror
                    </label>
                    <label class="text-sm font-semibold">Payé par
                        <select wire:model="moyen" class="mt-1 w-full h-11 px-3 rounded-lg border border-[--color-border-strong] bg-white font-normal">
                            @foreach (['especes' => 'Espèces', 'orange_money' => 'Orange Money', 'moov_money' => 'Moov Money', 'wave' => 'Wave', 'carte' => 'Carte', 'virement' => 'Virement'] as $c => $lib)<option value="{{ $c }}">{{ $lib }}</option>@endforeach
                        </select>
                    </label>
                </div>
                <div class="flex gap-3 mt-2">
                    <button type="button" wire:click="$set('receptionOuverte', false)" class="flex-1 h-11 rounded-lg border border-[--color-border-strong] font-bold">Annuler</button>
                    <button type="submit" class="flex-1 h-11 rounded-lg bg-accent text-white font-bold">Enregistrer la réception</button>
                </div>
            </form>
        </div>
    @endif

    @if ($paiementPour)
        <div class="fixed inset-0 bg-black/40 flex items-end md:items-center justify-center z-50 md:p-4" wire:click.self="$set('paiementPour', null)">
            <form wire:submit="payer" class="bg-white rounded-t-2xl md:rounded-2xl p-6 w-full md:max-w-sm flex flex-col gap-3">
                <h2 class="font-display font-extrabold text-xl">Paiement fournisseur</h2>
                <label class="text-sm font-semibold">Montant
                    <input wire:model="paiementMontant" type="text" inputmode="decimal" class="mt-1 w-full h-11 px-3 rounded-lg border border-[--color-border-strong] font-normal">
                </label>
                @error('paiementMontant') <p class="text-sm text-danger-fg">{{ $message }}</p> @enderror
                <label class="text-sm font-semibold">Payé par
                    <select wire:model="moyen" class="mt-1 w-full h-11 px-3 rounded-lg border border-[--color-border-strong] bg-white font-normal">
                        @foreach (['especes' => 'Espèces', 'orange_money' => 'Orange Money', 'moov_money' => 'Moov Money', 'wave' => 'Wave', 'carte' => 'Carte', 'virement' => 'Virement'] as $c => $lib)<option value="{{ $c }}">{{ $lib }}</option>@endforeach
                    </select>
                </label>
                <div class="flex gap-3 mt-2">
                    <button type="button" wire:click="$set('paiementPour', null)" class="flex-1 h-11 rounded-lg border border-[--color-border-strong] font-bold">Annuler</button>
                    <button type="submit" class="flex-1 h-11 rounded-lg bg-accent text-white font-bold">Enregistrer</button>
                </div>
            </form>
        </div>
    @endif
</div>
