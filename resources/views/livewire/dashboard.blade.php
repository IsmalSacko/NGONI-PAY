<div class="flex flex-col gap-5" wire:poll.visible.30s>
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="flex flex-col gap-1">
            <h1 class="font-display font-extrabold text-2xl md:text-3xl">Tableau de bord</h1>
            <span class="text-[--color-muted] text-sm">{{ $boutique->nom }} · {{ now()->translatedFormat('l j F') }}</span>
        </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white border border-[--color-border] rounded-2xl p-5 flex flex-col gap-1">
            <span class="text-sm font-semibold text-[--color-muted]">Chiffre d'affaires du jour</span>
            <span class="font-display font-extrabold text-2xl md:text-3xl">{{ \App\Support\Money\Montant::format($total) }} <span class="text-base font-bold text-[--color-muted]">{{ $boutique->devise }}</span></span>
        </div>
        <div class="bg-white border border-[--color-border] rounded-2xl p-5 flex flex-col gap-1">
            <span class="text-sm font-semibold text-[--color-muted]">Tickets</span>
            <span class="font-display font-extrabold text-2xl md:text-3xl">{{ $tickets }}</span>
        </div>
        <div class="bg-white border border-[--color-border] rounded-2xl p-5 flex flex-col gap-1">
            <span class="text-sm font-semibold text-[--color-muted]">Panier moyen</span>
            <span class="font-display font-extrabold text-2xl md:text-3xl">{{ \App\Support\Money\Montant::format($panierMoyen) }} <span class="text-base font-bold text-[--color-muted]">{{ $boutique->devise }}</span></span>
        </div>
        <div class="bg-white border border-[--color-border] rounded-2xl p-5 flex flex-col gap-1">
            <span class="text-sm font-semibold text-[--color-muted]">TVA collectée</span>
            <span class="font-display font-extrabold text-2xl md:text-3xl">{{ \App\Support\Money\Montant::format($tva) }} <span class="text-base font-bold text-[--color-muted]">{{ $boutique->devise }}</span></span>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <section class="lg:col-span-2 bg-white border border-[--color-border] rounded-2xl p-5">
            <h2 class="font-bold text-base mb-3">Ventes par heure</h2>
            <div class="flex items-end gap-1 md:gap-2 h-40">
                @foreach ($ventesParHeure as $point)
                    <div class="flex-1 min-w-0 flex flex-col items-center justify-end h-full gap-1">
                        <div class="w-full rounded-t {{ $point['total'] === $maxHeure && $point['total'] > 0 ? 'bg-accent' : 'bg-accent-soft' }}"
                             style="height: {{ $maxHeure > 0 ? max(2, round($point['total'] / $maxHeure * 100)) : 0 }}%"
                             title="{{ $point['heure'] }}h : {{ \App\Support\Money\Montant::format($point['total']) }}"></div>
                        {{-- Téléphone : une heure sur trois, sinon les libellés se chevauchent. --}}
                        <span class="text-[10px] text-[--color-muted] {{ $loop->index % 3 === 0 ? '' : 'invisible md:visible' }}">{{ $point['heure'] }}h</span>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="bg-white border border-[--color-border] rounded-2xl p-5 flex flex-col gap-3">
            <h2 class="font-bold text-base">Moyens de paiement</h2>
            @forelse ($parMoyen as $moyen)
                <div class="flex flex-col gap-1">
                    <div class="flex justify-between text-sm">
                        <span class="font-semibold">{{ $moyen['label'] }}</span>
                        <span class="font-bold">{{ $moyen['pct'] }} % <span class="text-[--color-muted] font-normal">· {{ \App\Support\Money\Montant::format($moyen['total']) }}</span></span>
                    </div>
                    <div class="h-2 rounded-full bg-[#F1EDE4]"><div class="h-2 rounded-full bg-accent" style="width: {{ $moyen['pct'] }}%"></div></div>
                </div>
            @empty
                <p class="text-sm text-[--color-muted]">Aucune vente aujourd'hui.</p>
            @endforelse
        </section>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <section class="bg-white border border-[--color-border] rounded-2xl p-5">
            <h2 class="font-bold text-base mb-3">Meilleures ventes du jour</h2>
            <div class="grid grid-cols-[1fr_70px_110px] gap-3 text-xs font-bold text-[--color-muted] pb-2 border-b border-[--color-border] uppercase">
                <span>Article</span><span class="text-right">Qté</span><span class="text-right">CA</span>
            </div>
            @forelse ($topProduits as $p)
                <div class="grid grid-cols-[1fr_70px_110px] gap-3 text-sm py-2 border-b border-[#EEEAE1] items-center">
                    <span class="font-semibold">{{ $p->nom_produit }}</span>
                    <span class="text-right">{{ $p->quantite }}</span>
                    <span class="text-right font-bold">{{ \App\Support\Money\Montant::format($p->total) }}</span>
                </div>
            @empty
                <p class="text-sm text-[--color-muted] py-2">Aucune vente aujourd'hui.</p>
            @endforelse
        </section>

        <section class="bg-white border border-[--color-border] rounded-2xl p-5">
            <div class="flex justify-between items-baseline mb-3">
                <h2 class="font-bold text-base">Alertes stock</h2>
                <a href="{{ route('stocks.index') }}" class="text-sm font-bold">Tout voir</a>
            </div>
            @forelse ($alertes as $produit)
                <div class="flex items-center gap-3 py-2 border-b border-[#EEEAE1]">
                    <span class="text-xs font-bold rounded px-2 py-1 min-w-[70px] text-center {{ $produit->estEnRupture() ? 'bg-danger-bg text-danger-fg' : 'bg-warn-bg text-warn-fg' }}">
                        {{ $produit->estEnRupture() ? 'Rupture' : 'Stock bas' }}
                    </span>
                    <div class="flex flex-col">
                        <span class="text-sm font-bold">{{ $produit->nom }}</span>
                        <span class="text-xs text-[--color-muted]">{{ $produit->stock }} restant(s) · seuil {{ $produit->seuil_alerte }}</span>
                    </div>
                </div>
            @empty
                <p class="text-sm text-[--color-muted]">Aucune alerte.</p>
            @endforelse
        </section>
    </div>
</div>
