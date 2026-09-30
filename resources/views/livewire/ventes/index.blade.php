<div class="flex flex-col gap-5" wire:poll.visible.30s>
    <div class="flex flex-col gap-1">
        <h1 class="font-display font-extrabold text-2xl md:text-3xl">Ventes</h1>
        <span class="text-[--color-muted] text-sm">Historique des tickets encaissés.</span>
    </div>

    <section class="bg-white border border-[--color-border] rounded-2xl overflow-hidden">
        <div class="hidden md:grid grid-cols-[150px_1fr_1fr_1fr_1fr_100px] gap-3 px-5 py-3 bg-fond-tableau border-b border-[--color-border] text-xs font-bold text-[--color-muted] uppercase">
            <span>N°</span><span>Caissier</span><span>Client</span><span>Moyen</span><span class="text-right">Total</span><span></span>
        </div>
        @forelse ($ventes as $vente)
            {{-- Téléphone : numéro et moyen de paiement, puis total et détail. --}}
            <div class="grid grid-cols-[1fr_auto] md:grid-cols-[150px_1fr_1fr_1fr_1fr_100px] gap-x-3 gap-y-2 px-5 py-3 border-b border-separateur items-center text-sm">
                <span class="font-mono">{{ $vente->numeroFormate() }}@if ($vente->estAnnulee()) <span class="ml-1 rounded bg-danger-bg px-1.5 text-xs font-bold text-danger-fg">annulée</span>@endif</span>
                <span class="hidden md:block">{{ $vente->caissier->name }}</span>
                <span class="hidden md:block">{{ $vente->client->nom ?? '—' }}</span>
                <span class="text-right md:text-left text-[--color-muted] md:text-inherit">{{ $vente->moyen_paiement->label() }}</span>
                <span class="md:text-right font-bold">{{ \App\Support\Money\Montant::format($vente->total) }}</span>
                <button wire:click="voir('{{ $vente->id }}')" class="h-9 px-3 rounded-lg border border-[--color-border-strong] text-xs font-bold justify-self-end">Détail</button>
            </div>
        @empty
            <p class="text-sm text-[--color-muted] p-5">Aucune vente enregistrée.</p>
        @endforelse
    </section>

    {{ $ventes->links() }}

    @if ($detail)
        <div class="fixed inset-0 bg-black/40 flex items-end md:items-center justify-center z-50 md:p-4" wire:click.self="fermer">
            <div class="bg-white rounded-t-2xl md:rounded-2xl p-6 w-full md:max-w-md max-h-[90vh] overflow-y-auto flex flex-col gap-3">
                <div class="flex justify-between items-baseline">
                    <h2 class="font-display font-extrabold text-xl">Ticket n° {{ $detail->numeroFormate() }}@if ($detail->numero_jour) <span class="text-[--color-muted] text-base">· n° {{ $detail->numero_jour }} du jour</span>@endif</h2>
                    <span class="text-xs text-[--color-muted]">{{ \App\Support\Fuseau::heure($detail->created_at) }}</span>
                </div>
                <div class="text-sm text-[--color-muted]">
                    {{ $detail->caissier->name }} @if($detail->client) · {{ $detail->client->nom }} @endif
                    @if ($detail->vendue_hors_ligne)
                        <span class="ml-2 text-xs font-bold text-warn-fg bg-warn-bg rounded px-2 py-0.5">Encaissée hors ligne</span>
                    @endif
                </div>

                <div class="flex flex-col divide-y divide-separateur border-y border-separateur">
                    @foreach ($detail->lignes as $ligne)
                        <div class="flex justify-between py-2 text-sm">
                            <span>{{ $ligne->nom_produit }} × {{ $ligne->quantite }}</span>
                            <span class="font-semibold">{{ \App\Support\Money\Montant::format($ligne->total_ligne) }}</span>
                        </div>
                    @endforeach
                </div>

                <div class="flex flex-col gap-1 text-sm">
                    <div class="flex justify-between"><span>Sous-total</span><span>{{ \App\Support\Money\Montant::format($detail->sous_total) }}</span></div>
                    @if ($detail->remise > 0)
                        <div class="flex justify-between text-danger-fg"><span>Remise</span><span>−{{ \App\Support\Money\Montant::format($detail->remise) }}</span></div>
                    @endif
                    <div class="flex justify-between font-bold text-base"><span>Total</span><span>{{ \App\Support\Money\Montant::format($detail->total) }}</span></div>
                    <div class="flex justify-between text-[--color-muted]"><span>Payé par</span><span>{{ $detail->moyen_paiement->label() }}</span></div>
                    @if ($detail->montant_recu)
                        <div class="flex justify-between text-[--color-muted]"><span>Monnaie rendue</span><span>{{ \App\Support\Money\Montant::format($detail->monnaie_rendue) }}</span></div>
                    @endif
                </div>

                @if ($detail->estAnnulee())
                    <div class="rounded-xl bg-danger-bg text-danger-fg px-4 py-3 text-sm">
                        <strong>Vente annulée</strong> le {{ \App\Support\Fuseau::heure($detail->annulee_le, 'd/m/Y à H:i') }}@if ($detail->annule_par_nom) par {{ $detail->annule_par_nom }}@endif.
                        @if ($detail->motif_annulation)<br>Motif : {{ $detail->motif_annulation }}@endif
                    </div>
                @elseif (auth()->user()->can('ventes.delete'))
                    <form wire:submit="annuler" class="rounded-xl border border-[--color-border] p-3 flex flex-col gap-2">
                        <label class="text-sm font-semibold">Annuler cette vente <span class="font-normal text-[--color-muted]">(le stock est remis, la vente reste tracée)</span></label>
                        <input wire:model="motif" type="text" maxlength="255" placeholder="Motif : erreur de saisie, retour client…" class="h-10 px-3 rounded-lg border border-[--color-border-strong]">
                        @error('motif') <p class="text-sm text-danger-fg">{{ $message }}</p> @enderror
                        <button type="submit" wire:confirm="Annuler définitivement cette vente ?" class="h-10 rounded-lg border border-danger-fg text-danger-fg font-bold">Annuler la vente</button>
                    </form>
                @endif

                <button wire:click="fermer" class="h-11 rounded-lg border border-[--color-border-strong] font-bold mt-2">Fermer</button>
            </div>
        </div>
    @endif
</div>
