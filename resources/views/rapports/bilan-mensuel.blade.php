{{-- Bilan du mois en PDF (dompdf) : tableaux plutôt que grilles, que dompdf ne sait pas mettre en page. --}}
@php
    $m = fn ($v) => \App\Support\Money\Montant::format((int) $v, $boutique->devise);
    $devise = in_array($boutique->devise, ['XOF', 'XAF'], true) ? 'F CFA' : $boutique->devise;
    $ecart = $precedent['ventes']['total'] > 0 ? (int) round(($r['ventes']['total'] - $precedent['ventes']['total']) * 100 / $precedent['ventes']['total']) : null;
@endphp
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #15191e; font-size: 11px; margin: 0; }
        .bandeau { background: #0F2A5C; color: #fff; padding: 18px 24px; }
        .bandeau h1 { margin: 0; font-size: 18px; } .bandeau p { margin: 4px 0 0; font-size: 11px; color: #d6dcea; }
        .page { padding: 18px 24px; }
        h2 { font-size: 13px; margin: 18px 0 6px; color: #0F2A5C; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 6px 8px; border-bottom: 1px solid #e3e8f0; text-align: left; }
        th { font-size: 10px; color: #5a6478; text-transform: uppercase; }
        .n { text-align: right; }
        .kpi td { border: 1px solid #e3e8f0; width: 25%; vertical-align: top; }
        .kpi b { display: block; font-size: 15px; margin-top: 3px; }
        .petit { color: #5a6478; font-size: 10px; }
        .hausse { color: #16a34a; } .baisse { color: #b42318; }
        .pied { margin-top: 24px; color: #5a6478; font-size: 9px; text-align: center; }
    </style>
</head>
<body>
    <div class="bandeau">
        <h1>{{ $boutique->nom }} — bilan de {{ $libelleMois }}</h1>
        <p>Du {{ \Illuminate\Support\Carbon::parse($r['du'])->format('d/m/Y') }} au {{ \Illuminate\Support\Carbon::parse($r['au'])->format('d/m/Y') }} · montants en {{ $devise }}</p>
    </div>
    <div class="page">
        <table class="kpi">
            <tr>
                <td>Ventes (chiffre d’affaires)<b>{{ $m($r['ventes']['total']) }}</b>
                    @if ($ecart !== null)<span class="{{ $ecart >= 0 ? 'hausse' : 'baisse' }}">{{ $ecart >= 0 ? '+' : '' }}{{ $ecart }} % par rapport au mois précédent</span>@endif</td>
                <td>Ventes<b>{{ $r['ventes']['nombre'] }}</b><span class="petit">{{ $r['ventes']['articles'] }} articles</span></td>
                <td>Panier moyen<b>{{ $m($r['ventes']['panier_moyen']) }}</b></td>
                <td>Marge brute<b>{{ $r['marge']['taux'] === null ? '—' : $m($r['marge']['marge']) }}</b>
                    <span class="petit">{{ $r['marge']['taux'] === null ? 'prix d’achat non renseignés' : $r['marge']['taux'].' %' }}</span></td>
            </tr>
            <tr>
                <td>Remises<b>{{ $m($r['ventes']['remises']) }}</b></td>
                <td>Vendu à crédit<b>{{ $m($r['credit']['accorde']) }}</b><span class="petit">Reste à encaisser : {{ ($r['credit']['encore_du'] ?? 0) > 0 ? '−' : '' }}{{ $m($r['credit']['encore_du'] ?? 0) }}@isset($r['encaisse']) · Encaissé : {{ $m($r['encaisse']['total']) }}@endisset</span></td>
                <td>Remboursements<b>{{ $m($r['credit']['rembourse']) }}</b></td>
                <td>Ventes annulées<b>{{ $r['annulees']['nombre'] }}</b><span class="petit">{{ $m($r['annulees']['total']) }}</span></td>
            </tr>
        </table>

        <h2>Meilleurs articles</h2>
        @if (count($r['top_produits']) === 0)
            <p class="petit">Aucune vente d’article sur le mois.</p>
        @else
            <table>
                <tr><th>Article</th><th class="n">Quantité</th><th class="n">Chiffre</th></tr>
                @foreach ($r['top_produits'] as $p)
                    <tr><td>{{ $p['nom'] }}</td><td class="n">{{ \App\Support\Quantite::formater($p['quantite'], $p['unite'] ?? null) }}</td><td class="n">{{ $m($p['total']) }}</td></tr>
                @endforeach
            </table>
        @endif

        <h2>Par moyen de paiement</h2>
        <table>
            <tr><th>Moyen</th><th class="n">Ventes</th><th class="n">Montant</th></tr>
            @foreach ($r['par_moyen'] as $l)
                <tr><td>{{ $l['libelle'] }}</td><td class="n">{{ $l['nombre'] }}</td><td class="n">{{ $m($l['total']) }}</td></tr>
            @endforeach
        </table>

        <h2>Par caissier</h2>
        <table>
            <tr><th>Caissier</th><th class="n">Ventes</th><th class="n">Montant</th></tr>
            @foreach ($r['par_caissier'] as $l)
                <tr><td>{{ $l['nom'] }}</td><td class="n">{{ $l['nombre'] }}</td><td class="n">{{ $m($l['total']) }}</td></tr>
            @endforeach
        </table>

        <p class="pied">Édité le {{ \App\Support\Fuseau::heure(now(), 'd/m/Y à H:i', $boutique->pays) }} par Ngoni Caisse · ngonipay.ismael-dev.com</p>
    </div>
</body>
</html>
