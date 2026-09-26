<div class="flex flex-col gap-5">
    <div class="flex flex-col gap-1">
        <h1 class="font-display font-extrabold text-3xl">Ventes</h1>
        <span class="text-[--color-muted] text-sm">Historique des tickets encaissés.</span>
    </div>

    <section class="bg-white border border-[--color-border] rounded-2xl overflow-hidden">
        <div class="grid grid-cols-[100px_1fr_1fr_1fr_1fr_100px] gap-3 px-5 py-3 bg-[#F7F5F0] border-b border-[--color-border] text-xs font-bold text-[--color-muted] uppercase">
            <span>N°</span><span>Caissier</span><span>Client</span><span>Moyen</span><span class="text-right">Total</span><span></span>
        </div>
        @forelse ($ventes as $vente)
            <div class="grid grid-cols-[100px_1fr_1fr_1fr_1fr_100px] gap-3 px-5 py-3 border-b border-[#EEEAE1] items-center text-sm">
                <span class="font-mono">{{ $vente->numeroFormate() }}@if ($vente->estAnnulee()) <span class="ml-1 rounded bg-danger-bg px-1.5 text-xs font-bold text-danger-fg">annulée</span>@endif</span>
                <span>{{ $vente->caissier->name }}</span>
                <span>{{ $vente->client->nom ?? '—' }}</span>
                <span>{{ $vente->moyen_paiement->label() }}</span>
                <span class="text-right font-bold">{{ number_format($vente->total, 0, ',', ' ') }}</span>
                <button wire:click="voir('{{ $vente->id }}')" class="h-9 px-3 rounded-lg border border-[--color-border-strong] text-xs font-bold justify-self-end">Détail</button>
            </div>
        @empty
            <p class="text-sm text-[--color-muted] p-5">Aucune vente enregistrée.</p>
        @endforelse
    </section>

    {{ $ventes->links() }}

    @if ($detail)
        <div class="fixed inset-0 bg-black/40 flex items-center justify-center z-50" wire:click.self="fermer">
            <div class="bg-white rounded-2xl p-6 w-full max-w-md flex flex-col gap-3">
                <div class="flex justify-between items-baseline">
                    <h2 class="font-display font-extrabold text-xl">Ticket n° {{ $detail->numeroFormate() }}</h2>
                    <span class="text-xs text-[--color-muted]">{{ $detail->created_at->format('d/m/Y H:i') }}</span>
                </div>
                <div class="text-sm text-[--color-muted]">
                    {{ $detail->caissier->name }} @if($detail->client) · {{ $detail->client->nom }} @endif
                    @if ($detail->vendue_hors_ligne)
                        <span class="ml-2 text-xs font-bold text-warn-fg bg-warn-bg rounded px-2 py-0.5">Encaissée hors ligne</span>
                    @endif
                </div>

                <div class="flex flex-col divide-y divide-[#EEEAE1] border-y border-[#EEEAE1]">
                    @foreach ($detail->lignes as $ligne)
                        <div class="flex justify-between py-2 text-sm">
                            <span>{{ $ligne->nom_produit }} × {{ $ligne->quantite }}</span>
                            <span class="font-semibold">{{ number_format($ligne->total_ligne, 0, ',', ' ') }}</span>
                        </div>
                    @endforeach
                </div>

                <div class="flex flex-col gap-1 text-sm">
                    <div class="flex justify-between"><span>Sous-total</span><span>{{ number_format($detail->sous_total, 0, ',', ' ') }}</span></div>
                    @if ($detail->remise > 0)
                        <div class="flex justify-between text-danger-fg"><span>Remise</span><span>−{{ number_format($detail->remise, 0, ',', ' ') }}</span></div>
                    @endif
                    <div class="flex justify-between font-bold text-base"><span>Total</span><span>{{ number_format($detail->total, 0, ',', ' ') }}</span></div>
                    <div class="flex justify-between text-[--color-muted]"><span>Payé par</span><span>{{ $detail->moyen_paiement->label() }}</span></div>
                    @if ($detail->montant_recu)
                        <div class="flex justify-between text-[--color-muted]"><span>Monnaie rendue</span><span>{{ number_format($detail->monnaie_rendue, 0, ',', ' ') }}</span></div>
                    @endif
                </div>

                <button wire:click="fermer" class="h-11 rounded-lg border border-[--color-border-strong] font-bold mt-2">Fermer</button>
            </div>
        </div>
    @endif
</div>
