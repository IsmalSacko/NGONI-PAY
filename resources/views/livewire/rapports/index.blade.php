@php($m = fn ($v) => \App\Support\Money\Montant::format((int) $v))
@php($devise = $boutique?->devise)
@php($q = ['du' => $r['du'], 'au' => $r['au']])
<div class="flex flex-col gap-5">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="flex flex-col gap-1">
            <h1 class="font-display font-extrabold text-2xl md:text-3xl">Rapports</h1>
            <span class="text-[--color-muted] text-sm">
                {{ $boutique?->nom }} · du {{ \Illuminate\Support\Carbon::parse($r['du'])->format('d/m/Y') }} au {{ \Illuminate\Support\Carbon::parse($r['au'])->format('d/m/Y') }}
            </span>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('rapports.imprimer', $q) }}" target="_blank" class="h-11 px-4 rounded-xl border border-[--color-border-strong] font-bold flex items-center">Imprimer / PDF</a>
            <a href="{{ route('exports.ventes', $q) }}" class="h-11 px-4 rounded-xl bg-accent text-white font-bold flex items-center">Excel : ventes</a>
            <a href="{{ route('exports.stocks') }}" class="h-11 px-4 rounded-xl border border-[--color-border-strong] font-bold flex items-center">Excel : stocks</a>
            <a href="{{ route('exports.credits') }}" class="h-11 px-4 rounded-xl border border-[--color-border-strong] font-bold flex items-center">Excel : dettes</a>
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-2">
        @foreach (['jour' => 'Aujourd’hui (clôture)', 'hier' => 'Hier', '7j' => '7 jours', 'mois' => 'Ce mois', 'mois_dernier' => 'Mois dernier'] as $code => $libelle)
            <button wire:click="periode('{{ $code }}')" class="h-10 px-4 rounded-full text-sm font-bold bg-white border border-[--color-border-strong] hover:border-accent">{{ $libelle }}</button>
        @endforeach
        <span class="flex items-center gap-2 text-sm">
            <input wire:model.live="du" type="date" class="h-10 px-3 rounded-xl border border-[--color-border-strong] bg-white">
            →
            <input wire:model.live="au" type="date" class="h-10 px-3 rounded-xl border border-[--color-border-strong] bg-white">
        </span>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        @foreach ([
            ['Chiffre d’affaires', $m($r['ventes']['total']).' '.$devise],
            ['Tickets', $r['ventes']['nombre'].' · '.$r['ventes']['articles'].' articles'],
            ['Panier moyen', $m($r['ventes']['panier_moyen']).' '.$devise],
            ['Marge brute', $r['marge']['taux'] === null ? '—' : $m($r['marge']['marge']).' '.$devise.' ('.$r['marge']['taux'].' %)'],
            ['Remises accordées', $m($r['ventes']['remises']).' '.$devise],
            ['TVA collectée', $m($r['ventes']['tva']).' '.$devise],
            ['Ventes annulées', $r['annulees']['nombre'].' · '.$m($r['annulees']['total']).' '.$devise],
            ['Crédit accordé / remboursé', $m($r['credit']['accorde']).' / '.$m($r['credit']['rembourse']).' '.$devise],
        ] as [$libelle, $valeur])
            <div class="bg-white border border-[--color-border] rounded-2xl p-5 flex flex-col gap-1">
                <span class="text-sm font-semibold text-[--color-muted]">{{ $libelle }}</span>
                <span class="font-display font-extrabold text-xl">{{ $valeur }}</span>
            </div>
        @endforeach
    </div>
    @if ($r['marge']['taux'] !== null && $r['marge']['chiffre_couvert'] < $r['ventes']['total'])
        <p class="text-xs text-[--color-muted] -mt-2">Marge calculée sur les articles dont le prix d’achat est renseigné ({{ $m($r['marge']['chiffre_couvert']) }} {{ $devise }} de ventes), avant remise.</p>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <section class="bg-white border border-[--color-border] rounded-2xl p-5">
            <h2 class="font-bold mb-3">Par moyen de paiement</h2>
            @forelse ($r['par_moyen'] as $l)
                <div class="flex justify-between text-sm py-2 border-b border-[#EEEAE1]"><span>{{ $l['libelle'] }} <span class="text-[--color-muted]">({{ $l['nombre'] }})</span></span><span class="font-bold">{{ $m($l['total']) }}</span></div>
            @empty
                <p class="text-sm text-[--color-muted]">Aucune vente sur la période.</p>
            @endforelse
        </section>
        <section class="bg-white border border-[--color-border] rounded-2xl p-5">
            <h2 class="font-bold mb-3">Par caissier</h2>
            @forelse ($r['par_caissier'] as $l)
                <div class="flex justify-between text-sm py-2 border-b border-[#EEEAE1]"><span>{{ $l['nom'] }} <span class="text-[--color-muted]">({{ $l['nombre'] }})</span></span><span class="font-bold">{{ $m($l['total']) }}</span></div>
            @empty
                <p class="text-sm text-[--color-muted]">Aucune vente sur la période.</p>
            @endforelse
        </section>
        <section class="bg-white border border-[--color-border] rounded-2xl p-5">
            <h2 class="font-bold mb-3">Meilleurs articles</h2>
            @forelse ($r['top_produits'] as $l)
                <div class="flex justify-between text-sm py-2 border-b border-[#EEEAE1]"><span>{{ $l['nom'] }} <span class="text-[--color-muted]">× {{ $l['quantite'] }}</span></span><span class="font-bold">{{ $m($l['total']) }}</span></div>
            @empty
                <p class="text-sm text-[--color-muted]">Aucune vente sur la période.</p>
            @endforelse
        </section>
        <section class="bg-white border border-[--color-border] rounded-2xl p-5">
            <h2 class="font-bold mb-3">Jour par jour</h2>
            @forelse ($r['par_jour'] as $l)
                <div class="flex justify-between text-sm py-2 border-b border-[#EEEAE1]"><span>{{ \Illuminate\Support\Carbon::parse($l['date'])->translatedFormat('D d/m') }} <span class="text-[--color-muted]">({{ $l['nombre'] }})</span></span><span class="font-bold">{{ $m($l['total']) }}</span></div>
            @empty
                <p class="text-sm text-[--color-muted]">Aucune vente sur la période.</p>
            @endforelse
        </section>
    </div>
</div>
