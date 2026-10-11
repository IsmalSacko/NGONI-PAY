@php($m = fn ($v) => \App\Support\Money\Montant::format((int) $v))
@php($q = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, ',', ' '), '0'), ','))
@php($ton = fn (string $statut) => match ($statut) { 'livree' => 'succes', 'refusee', 'annulee' => 'danger', 'envoyee' => 'warn', default => 'accent' })
<div class="flex flex-col gap-5">
    <div class="flex flex-col gap-1">
        <h1 class="font-display font-extrabold text-2xl md:text-3xl text-accent">Commandes</h1>
        <span class="text-muted text-sm">Vos commandes aux fournisseurs, et celles de vos revendeurs. Elles se confirment et se livrent dans l’application.</span>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        @if ($recues->isNotEmpty())
            <x-charte.carte class="p-5 flex flex-col gap-3">
                <x-charte.en-tete-section icone="move_to_inbox" titre="Reçues de vos revendeurs" :sous-titre="$recues->where('statut', 'envoyee')->count().' nouvelle(s) à traiter'" />
                @foreach ($recues as $c)
                    @php($libelle = match ($c->statut) { 'envoyee' => 'Nouvelle', 'confirmee' => 'À livrer', 'annulee' => 'Annulée par le client', default => \App\Models\CommandeFournisseur::STATUTS[$c->statut] ?? $c->statut })
                    <div class="py-3 border-b border-separateur last:border-b-0" wire:key="r-{{ $c->id }}">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-bold text-ink flex-1">{{ $c->boutique?->nom }} · N° {{ $c->numero }}</span>
                            <x-charte.puce :ton="$ton($c->statut)">{{ $libelle }}</x-charte.puce>
                        </div>
                        <span class="text-xs text-muted">{{ $c->created_at?->format('d/m/Y H:i') }}</span>
                        <ul class="text-sm mt-1">
                            @foreach ($c->lignes as $l)
                                <li>{{ $q($l->quantite / max(0.001, (float) $l->contenance)) }} × {{ $l->produitGrossiste?->nom ?? $l->nom }}@if ($l->prix_grossiste) — {{ $m($l->prix_grossiste) }}@endif</li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </x-charte.carte>
        @endif

        <x-charte.carte class="p-5 flex flex-col gap-3">
            <x-charte.en-tete-section icone="outbox" titre="Envoyées à vos fournisseurs" sous-titre="Sur Ngoni ou par WhatsApp." />
            @forelse ($envoyees as $c)
                @php($libelle = $c->statut === 'envoyee' && $c->vue_le ? 'Vue' : (\App\Models\CommandeFournisseur::STATUTS[$c->statut] ?? $c->statut))
                <div class="py-3 border-b border-separateur last:border-b-0" wire:key="e-{{ $c->id }}">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-bold text-ink flex-1">{{ $c->fournisseur?->nom }} · N° {{ $c->numero }}</span>
                        <x-charte.puce :ton="$ton($c->statut)">{{ $libelle }}</x-charte.puce>
                    </div>
                    <span class="text-xs text-muted">{{ $c->created_at?->format('d/m/Y H:i') }} · {{ $c->boutique_grossiste_id ? 'Ngoni' : 'WhatsApp' }} · estimé {{ $m($c->total_estime) }}</span>
                    <ul class="text-sm mt-1">
                        @foreach ($c->lignes as $l)
                            <li>{{ $q($l->quantite / max(0.001, (float) $l->contenance_commande)) }}{{ $l->unite_commande ? ' '.$l->unite_commande : ($l->unite ? ' '.$l->unite : '') }} × {{ $l->nom }}</li>
                        @endforeach
                    </ul>
                </div>
            @empty
                <x-charte.etat-vide icone="outbox" titre="Aucune commande pour le moment." texte="Commandez depuis l’application : Achats → Commander." />
            @endforelse
        </x-charte.carte>
    </div>
</div>
