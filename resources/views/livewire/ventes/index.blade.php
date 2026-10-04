<div class="flex flex-col gap-5" wire:poll.visible.30s>
    {{-- Même habit que l'écran Ventes de l'application. --}}
    <div class="flex flex-col gap-1">
        <h1 class="font-display font-extrabold text-2xl md:text-3xl text-accent">Ventes</h1>
        <span class="text-muted text-sm">Historique des tickets encaissés.</span>
    </div>

    @if ($aujourdhui)
        <x-charte.carte-heros icone="payments" titre="Aujourd’hui" :valeur="\App\Support\Money\Montant::format($aujourdhui['ventes']['total'])">
            <span>{{ $aujourdhui['ventes']['nombre'] }} ticket{{ $aujourdhui['ventes']['nombre'] > 1 ? 's' : '' }}</span>
            @if ($aujourdhui['credit']['encore_du'] > 0)
                <span class="text-white">Encaissé {{ \App\Support\Money\Montant::format($aujourdhui['encaisse']['total']) }}</span>
                <a href="{{ route('clients.index') }}" class="text-[#ffb4a9] no-underline hover:underline">Reste à encaisser −{{ \App\Support\Money\Montant::format($aujourdhui['credit']['encore_du']) }} ›</a>
            @endif
        </x-charte.carte-heros>
    @else
        <x-charte.carte-heros icone="receipt_long" titre="Vos tickets" :valeur="$mesTickets.' ticket'.($mesTickets > 1 ? 's' : '')">
            <span>Ouvrez un ticket pour le revoir ou l’annuler.</span>
        </x-charte.carte-heros>
    @endif

    <label class="flex items-center gap-2 h-12 px-4 rounded-2xl bg-white shadow-carte focus-within:ring-2 focus-within:ring-accent">
        <x-charte.icone nom="search" class="text-muted" />
        <input wire:model.live.debounce.400ms="recherche" type="text" placeholder="N° de ticket, client, article…" class="flex-1 min-w-0 bg-transparent outline-none">
    </label>

    <x-charte.carte class="overflow-hidden">
        @forelse ($ventes as $vente)
            @php($icone = match ($vente->moyen_paiement->value) { 'especes' => 'payments', 'carte' => 'credit_card', 'credit_client' => 'schedule', 'virement' => 'account_balance', 'paypal' => 'account_balance_wallet', default => 'smartphone' })
            <button wire:click="voir('{{ $vente->id }}')" class="w-full text-left flex items-center gap-3 px-4 py-3 border-b border-separateur last:border-b-0 hover:bg-paper/60" wire:key="vente-{{ $vente->id }}">
                <x-charte.pastille :icone="$vente->estAnnulee() ? 'block' : $icone" :danger="$vente->estAnnulee()" :taille="40" />
                <span class="flex-1 min-w-0 flex flex-col">
                    <span class="font-bold text-ink truncate">Ticket n° {{ $vente->numeroFormate() }}</span>
                    <span class="text-xs text-muted truncate">{{ \App\Support\Fuseau::heure($vente->created_at) }} · {{ $vente->moyen_paiement->label() }}@if ($vente->client) · {{ $vente->client->nom }}@endif · {{ $vente->caissier->name }}@if ($vente->vendue_hors_ligne) · encaissée hors ligne @endif</span>
                </span>
                <span class="flex flex-col items-end gap-1">
                    <span class="font-extrabold tabular-nums {{ $vente->estAnnulee() ? 'text-muted line-through' : 'text-accent' }}">{{ \App\Support\Money\Montant::format($vente->total) }}</span>
                    @if ($vente->estAnnulee())
                        <x-charte.puce ton="danger">ANNULÉE</x-charte.puce>
                    @elseif ($vente->reste_du > 0)
                        <x-charte.puce ton="warn" icone="schedule">{{ $vente->montant_paye > 0 ? 'Reste '.\App\Support\Money\Montant::format($vente->reste_du) : 'Crédit' }}</x-charte.puce>
                    @endif
                </span>
            </button>
        @empty
            <x-charte.etat-vide icone="receipt_long" :titre="trim($recherche) === '' ? 'Aucune vente enregistrée.' : 'Aucune vente ne correspond.'" />
        @endforelse
        <div class="border-t border-separateur">
            <x-charte.pagination :pages="$ventes" mot="ventes" />
        </div>
    </x-charte.carte>

    @if ($detail)
        <div class="fixed inset-0 bg-black/40 flex items-end md:items-center justify-center z-50 md:p-4" wire:click.self="fermer">
            <div class="bg-paper rounded-t-[24px] md:rounded-[24px] p-6 shadow-carte-haute w-full md:max-w-md max-h-[90vh] overflow-y-auto flex flex-col gap-3">
                <div class="flex justify-between items-baseline">
                    <h2 class="font-display font-extrabold text-xl text-accent">Ticket n° {{ $detail->numeroFormate() }}@if ($detail->numero_jour) <span class="text-muted text-base">· n° {{ $detail->numero_jour }} du jour</span>@endif</h2>
                    <span class="text-xs text-muted">{{ \App\Support\Fuseau::heure($detail->created_at) }}</span>
                </div>
                <div class="text-sm text-muted">
                    {{ $detail->caissier->name }} @if($detail->client) · {{ $detail->client->nom }} @endif
                    @if ($detail->vendue_hors_ligne)
                        <span class="ml-2 text-xs font-bold text-warn-fg bg-warn-bg rounded px-2 py-0.5">Encaissée hors ligne</span>
                    @endif
                </div>
                @if (! empty($detail->ordonnance))
                    {{-- Pharmacie : l'ordonnance relevée à la délivrance. --}}
                    <div class="text-sm text-muted">
                        Ordonnance{{ ! empty($detail->ordonnance['numero']) ? ' n° '.$detail->ordonnance['numero'] : '' }}{{ ! empty($detail->ordonnance['prescripteur']) ? ' · '.$detail->ordonnance['prescripteur'] : '' }}{{ ! empty($detail->ordonnance['patient']) ? ' · patient : '.$detail->ordonnance['patient'] : '' }}
                    </div>
                @endif

                <div class="flex flex-col divide-y divide-separateur border-y border-separateur">
                    @foreach ($detail->lignes as $ligne)
                        <div class="flex justify-between py-2 text-sm">
                            <span>{{ $ligne->nom_produit }} × {{ \App\Support\Quantite::formater($ligne->quantite, $ligne->unite) }}</span>
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
                    <div class="flex justify-between text-muted"><span>Payé par</span><span>{{ $detail->moyen_paiement->label() }}</span></div>
                    {{-- Paiement partiel : ce qui a été payé, et la dette du client. --}}
                    @if ($detail->reste_du > 0)
                        <div class="flex justify-between"><span>Payé maintenant</span><span>{{ \App\Support\Money\Montant::format($detail->montant_paye) }}</span></div>
                        <div class="flex justify-between font-bold text-danger-fg"><span>Reste dû</span><span>−{{ \App\Support\Money\Montant::format($detail->reste_du) }}</span></div>
                    @endif
                    @if ($detail->montant_recu)
                        <div class="flex justify-between text-muted"><span>Monnaie rendue</span><span>{{ \App\Support\Money\Montant::format($detail->monnaie_rendue) }}</span></div>
                    @endif
                </div>

                @if ($detail->estAnnulee())
                    <div class="rounded-xl bg-danger-bg text-danger-fg px-4 py-3 text-sm">
                        <strong>Vente annulée</strong> le {{ \App\Support\Fuseau::heure($detail->annulee_le, 'd/m/Y à H:i') }}@if ($detail->annule_par_nom) par {{ $detail->annule_par_nom }}@endif.
                        @if ($detail->motif_annulation)<br>Motif : {{ $detail->motif_annulation }}@endif
                    </div>
                @elseif (auth()->user()->can('ventes.delete'))
                    <form wire:submit="annuler" class="rounded-xl border border-[--color-border] p-3 flex flex-col gap-2">
                        <label class="text-sm font-semibold">Annuler cette vente <span class="font-normal text-muted">(le stock est remis, la vente reste tracée)</span></label>
                        <input wire:model="motif" type="text" maxlength="255" placeholder="Motif : erreur de saisie, retour client…" class="h-10 px-3 rounded-xl bg-white border border-border-strong">
                        @error('motif') <p class="text-sm text-danger-fg">{{ $message }}</p> @enderror
                        <button type="submit" wire:confirm="Annuler définitivement cette vente ?" class="h-10 rounded-lg border border-danger-fg text-danger-fg font-bold">Annuler la vente</button>
                    </form>
                @endif

                <button wire:click="fermer" class="h-11 rounded-xl bg-white border border-border-strong font-bold mt-2">Fermer</button>
            </div>
        </div>
    @endif
</div>
