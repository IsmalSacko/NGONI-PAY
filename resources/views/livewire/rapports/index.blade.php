@php($m = fn ($v) => \App\Support\Money\Montant::format((int) $v))
@php($devise = $boutique?->devise)
@php($q = ['du' => $r['du'], 'au' => $r['au']])
<div class="flex flex-col gap-5">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="flex flex-col gap-1">
            <h1 class="font-display font-extrabold text-2xl md:text-3xl text-accent">Rapports</h1>
            <span class="text-muted text-sm">
                {{ $boutique?->nom }} · du {{ \Illuminate\Support\Carbon::parse($r['du'])->format('d/m/Y') }} au {{ \Illuminate\Support\Carbon::parse($r['au'])->format('d/m/Y') }}
            </span>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('rapports.imprimer', $q) }}" target="_blank" class="h-11 px-4 rounded-xl bg-white shadow-carte text-accent font-bold flex items-center">Imprimer / PDF</a>
            {{-- Exports Excel : dans l'offre (Plan::FACTURES_EXPORTS) seulement. --}}
            @if (app(\App\Services\AbonnementService::class)->permet($boutique, \App\Models\Plan::FACTURES_EXPORTS))
                <a href="{{ route('exports.ventes', $q) }}" class="h-11 px-4 rounded-xl bg-accent text-white font-bold flex items-center">Excel : ventes</a>
                <a href="{{ route('exports.stocks') }}" class="h-11 px-4 rounded-xl bg-white shadow-carte text-accent font-bold flex items-center">Excel : stocks</a>
                <a href="{{ route('exports.credits') }}" class="h-11 px-4 rounded-xl bg-white shadow-carte text-accent font-bold flex items-center">Excel : dettes</a>
            @endif
        </div>
    </div>

    @if ($statut)
        <p class="rounded-2xl bg-succes-doux text-succes-fonce px-4 py-3 text-sm font-bold">{{ $statut }}</p>
    @endif
    @error('journee') <div class="rounded-xl bg-danger-bg text-danger-fg px-4 py-3 font-semibold">{{ $message }}</div> @enderror

    <div class="bg-white rounded-[20px] shadow-carte p-5 flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-start gap-3">
        <x-charte.pastille icone="calendar_today" :taille="44" />
        <div class="flex flex-col gap-1">
            <span class="text-sm font-semibold text-muted">Journée en cours</span>
            <span class="font-display font-extrabold text-xl text-accent">{{ $journee->translatedFormat('l d/m/Y') }} · {{ $ticketsJour }} ticket{{ $ticketsJour > 1 ? 's' : '' }}</span>
            <span class="text-xs text-muted">
                La clôture fige les chiffres de la journée (ticket Z) ; les tickets repartent à 1 et les ventes suivantes comptent pour le lendemain.
            </span>
        </div>
        </div>
        <button wire:click="cloturer" wire:confirm="Clôturer la journée du {{ $journee->format('d/m/Y') }} ? Ses chiffres seront figés et ses ventes ne pourront plus être annulées."
                class="inline-flex items-center gap-2 h-11 px-5 rounded-xl bg-accent text-white font-bold shadow-carte"><x-charte.icone nom="lock_clock" />Clôturer la journée</button>
    </div>

    <div class="flex flex-wrap items-center gap-2">
        @foreach (['jour' => 'Aujourd’hui', 'hier' => 'Hier', '7j' => '7 jours', 'mois' => 'Ce mois', 'mois_dernier' => 'Mois dernier'] as $code => $libelle)
            <button wire:click="periode('{{ $code }}')" class="h-10 px-4 rounded-full text-sm font-bold bg-puce text-accent hover:bg-jaune">{{ $libelle }}</button>
        @endforeach
        <span class="flex items-center gap-2 text-sm">
            <input wire:model.live="du" type="date" class="h-10 px-3 rounded-xl bg-white border border-border-strong bg-white">
            →
            <input wire:model.live="au" type="date" class="h-10 px-3 rounded-xl bg-white border border-border-strong bg-white">
        </span>
    </div>

    {{-- Le chiffre d'affaires en tête, comme la carte du Pilotage de l'application. --}}
    <x-charte.carte-heros icone="payments" titre="Ventes (chiffre d’affaires)" :valeur="$m($r['ventes']['total']).' '.$devise">
        @if (isset($r['encaisse']))<span class="text-white">Encaissé {{ $m($r['encaisse']['total']) }} {{ $devise }}</span>@endif
        @if (($r['credit']['encore_du'] ?? 0) > 0)
            <a href="{{ route('clients.index') }}" class="text-[#ffb4a9] no-underline hover:underline">Reste à encaisser −{{ $m($r['credit']['encore_du']) }} {{ $devise }} ›</a>
        @endif
        <span>{{ $r['ventes']['nombre'] }} vente{{ $r['ventes']['nombre'] > 1 ? 's' : '' }}</span>
    </x-charte.carte-heros>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        @foreach ([
            // Mêmes mots partout : Encaissé (reçu), À crédit (en moins, en rouge), Remboursements.
            ...(isset($r['encaisse']) ? [['Encaissé', $m($r['encaisse']['total']).' '.$devise, '', 'account_balance_wallet']] : []),
            ['Vendu à crédit', $m($r['credit']['accorde']).' '.$devise, '', 'handshake'],
            ['Reste à encaisser', (($r['credit']['encore_du'] ?? 0) > 0 ? '−' : '').$m($r['credit']['encore_du'] ?? 0).' '.$devise, 'rouge', 'hourglass_bottom'],
            ['Remboursements', $m($r['credit']['rembourse']).' '.$devise, '', 'savings'],
            ['Tickets', $r['ventes']['nombre'].' · '.\App\Support\Quantite::formater($r['ventes']['articles']).' articles', '', 'receipt_long'],
            ['Panier moyen', $m($r['ventes']['panier_moyen']).' '.$devise, '', 'shopping_basket'],
            ['Marge brute', $r['marge']['taux'] === null ? 'Prix d’achat à renseigner' : $m($r['marge']['marge']).' '.$devise.' ('.$r['marge']['taux'].' %)', '', 'trending_up'],
            ['Remises accordées', $m($r['ventes']['remises']).' '.$devise, '', 'sell'],
            ['TVA collectée', $m($r['ventes']['tva']).' '.$devise, '', 'percent'],
            ['Ventes annulées', $r['annulees']['nombre'].' · '.$m($r['annulees']['total']).' '.$devise, '', 'cancel'],
        ] as $kpi)
            @php([$libelle, $valeur] = $kpi)
            {{-- Comme les cartes de chiffres du Pilotage : pastille, libellé, valeur. --}}
            <div class="bg-white rounded-[20px] shadow-carte p-4 flex flex-col gap-2">
                <span class="flex items-center gap-2">
                    <x-charte.pastille :icone="$kpi[3] ?? 'insights'" :danger="($kpi[2] ?? '') === 'rouge' && ($r['credit']['encore_du'] ?? 0) > 0" :taille="34" />
                    <span class="text-sm font-semibold text-muted">{{ $libelle }}</span>
                </span>
                <span class="font-display font-extrabold text-xl {{ ($kpi[2] ?? '') === 'rouge' && ($r['credit']['encore_du'] ?? 0) > 0 ? 'text-danger-fg' : 'text-ink' }}">{{ $valeur }}</span>
            </div>
        @endforeach
    </div>
    @if ($r['marge']['taux'] === null && $r['ventes']['nombre'] > 0)
        <p class="text-sm -mt-2 rounded-2xl bg-warn-bg text-warn-fg px-4 py-3">
            Pour voir votre marge, renseignez le <strong>prix d’achat</strong> de vos articles
            (<a href="{{ route('produits.index') }}" class="underline">Produits</a>, ou fiche article dans l’application).
        </p>
    @elseif ($r['marge']['taux'] !== null && ($r['marge']['couverture'] < 100 || $r['marge']['estimee']))
        <p class="text-xs text-muted -mt-2">
            Marge calculée sur {{ $r['marge']['couverture'] }} % des ventes (articles dont le prix d’achat est connu), avant remise.
            @if ($r['marge']['estimee']) Certaines ventes antérieures au prix d’achat utilisent le prix d’achat actuel (estimation). @endif
        </p>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <section class="bg-white rounded-[20px] shadow-carte p-5">
            <x-charte.en-tete-section icone="payments" titre="Par moyen de paiement" class="mb-3" />
            @forelse ($r['par_moyen'] as $l)
                @php($credit = in_array($l['libelle'], ['Vendu à crédit', 'Crédit client'], true))
                <div class="flex justify-between text-sm py-2 border-b border-separateur {{ $credit ? 'text-danger-fg font-semibold' : '' }}"><span>{{ $l['libelle'] }} <span class="{{ $credit ? '' : 'text-muted' }}">· {{ $l['nombre'] }} vente{{ $l['nombre'] > 1 ? 's' : '' }}</span></span><span class="font-bold">{{ $m($l['total']) }}</span></div>
            @empty
                <p class="text-sm text-muted">Aucune vente sur la période.</p>
            @endforelse
        </section>
        <section class="bg-white rounded-[20px] shadow-carte p-5">
            <x-charte.en-tete-section icone="badge" titre="Par caissier" class="mb-3" />
            @forelse ($r['par_caissier'] as $l)
                <div class="flex justify-between text-sm py-2 border-b border-separateur"><span>{{ $l['nom'] }} <span class="text-muted">· {{ $l['nombre'] }} vente{{ $l['nombre'] > 1 ? 's' : '' }}</span></span><span class="font-bold">{{ $m($l['total']) }}</span></div>
            @empty
                <p class="text-sm text-muted">Aucune vente sur la période.</p>
            @endforelse
        </section>
        <section class="bg-white rounded-[20px] shadow-carte p-5">
            <x-charte.en-tete-section icone="star" titre="Meilleurs articles" class="mb-3" />
            @forelse ($r['top_produits'] as $l)
                <div class="flex justify-between text-sm py-2 border-b border-separateur"><span>{{ $l['nom'] }} <span class="text-muted">× {{ \App\Support\Quantite::formater($l['quantite'], $l['unite'] ?? null) }}</span></span><span class="font-bold">{{ $m($l['total']) }}</span></div>
            @empty
                <p class="text-sm text-muted">Aucune vente sur la période.</p>
            @endforelse
        </section>
        <section class="bg-white rounded-[20px] shadow-carte p-5">
            <x-charte.en-tete-section icone="show_chart" titre="Jour par jour" class="mb-3" />
            @forelse ($r['par_jour'] as $l)
                <div class="flex justify-between text-sm py-2 border-b border-separateur"><span>{{ \Illuminate\Support\Carbon::parse($l['date'])->translatedFormat('D d/m') }} <span class="text-muted">· {{ $l['nombre'] }} vente{{ $l['nombre'] > 1 ? 's' : '' }}</span></span><span class="font-bold">{{ $m($l['total']) }}</span></div>
            @empty
                <p class="text-sm text-muted">Aucune vente sur la période.</p>
            @endforelse
        </section>
    </div>

    <div class="bg-white rounded-[20px] shadow-carte p-5 flex flex-col gap-3">
        <x-charte.en-tete-section icone="lock_clock" titre="Clôtures (tickets Z)" sous-titre="Les journées figées, à réimprimer." />
        @forelse ($clotures as $z)
            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-separateur pt-3 text-sm">
                <button wire:click="$set('du', '{{ $z->jour_affaire->toDateString() }}'); $set('au', '{{ $z->jour_affaire->toDateString() }}')" class="text-left">
                    <strong>Z n° {{ $z->numero }}</strong> · {{ $z->jour_affaire->format('d/m/Y') }}
                    <span class="text-muted">· clôturée le {{ \App\Support\Fuseau::heure($z->created_at, 'd/m/Y à H:i') }} par {{ $z->auteur?->name }}</span>
                </button>
                <span class="flex items-center gap-3">
                    <strong>{{ $m($z->totaux['ventes']['total'] ?? 0) }} {{ $devise }}</strong>
                    <span class="text-muted">{{ $z->totaux['ventes']['nombre'] ?? 0 }} tickets</span>
                    <a href="{{ route('rapports.imprimer', ['du' => $z->jour_affaire->toDateString(), 'au' => $z->jour_affaire->toDateString()]) }}" target="_blank" class="underline">Imprimer</a>
                </span>
            </div>
        @empty
            <p class="text-sm text-muted">Aucune journée clôturée pour l’instant.</p>
        @endforelse
    </div>
</div>
