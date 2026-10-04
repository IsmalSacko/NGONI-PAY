@php($m = fn ($v) => \App\Support\Money\Montant::format((int) $v, $boutique->devise))
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Rapport {{ $boutique->nom }} — {{ $r['du'] }} au {{ $r['au'] }}</title>
    <style>
        body { font-family: -apple-system, 'Segoe UI', Roboto, Arial, sans-serif; color: #15191e; margin: 32px; }
        h1 { margin: 0; font-size: 22px; } h2 { font-size: 15px; margin: 22px 0 6px; }
        .muted { color: #5b616b; font-size: 13px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        td, th { padding: 6px 8px; border-bottom: 1px solid #e3ded3; text-align: left; }
        td.n, th.n { text-align: right; }
        .kpi { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-top: 16px; }
        .kpi div { border: 1px solid #e3ded3; border-radius: 10px; padding: 10px; }
        .kpi b { display: block; font-size: 16px; margin-top: 4px; }
        .actions { margin-bottom: 18px; } @media print { .actions { display: none; } body { margin: 0; } }
    </style>
</head>
<body>
    <div class="actions"><button onclick="window.print()">Imprimer ou enregistrer en PDF</button></div>
    <h1>{{ $boutique->nom }} — @if ($z) Clôture Z n° {{ $z->numero }} @else Rapport d’activité @endif</h1>
    @if ($z)<p class="muted">Journée clôturée le {{ \App\Support\Fuseau::heure($z->created_at, 'd/m/Y à H:i', $boutique->pays) }} par {{ $z->auteur?->name }}</p>@endif
    <p class="muted">
        Du {{ \Illuminate\Support\Carbon::parse($r['du'])->format('d/m/Y') }} au {{ \Illuminate\Support\Carbon::parse($r['au'])->format('d/m/Y') }}
        @if ($boutique->identifiant_fiscal) · NIF {{ $boutique->identifiant_fiscal }} @endif
        @if ($boutique->rccm) · RCCM {{ $boutique->rccm }} @endif
        · édité le {{ \App\Support\Fuseau::heure(now(), 'd/m/Y à H:i', $boutique->pays) }} · montants en {{ $boutique->devise }}
    </p>
    <div class="kpi">
        <div>Ventes (chiffre d’affaires)<b>{{ $m($r['ventes']['total']) }}</b></div>
        @isset($r['encaisse'])<div>Encaissé<b>{{ $m($r['encaisse']['total']) }}</b></div>@endisset
        <div>Vendu à crédit<b>{{ $m($r['credit']['accorde']) }}</b></div>
        <div>Reste à encaisser<b>{{ ($r['credit']['encore_du'] ?? 0) > 0 ? '−' : '' }}{{ $m($r['credit']['encore_du'] ?? 0) }}</b></div>
        <div>Remboursements<b>{{ $m($r['credit']['rembourse']) }}</b></div>
        <div>Tickets<b>{{ $r['ventes']['nombre'] }}</b></div>
        <div>Panier moyen<b>{{ $m($r['ventes']['panier_moyen']) }}</b></div>
        <div>Marge brute<b>{{ $r['marge']['taux'] === null ? 'prix d’achat non renseignés' : $m($r['marge']['marge']).' ('.$r['marge']['taux'].' %)' }}</b>@if ($r['marge']['taux'] !== null && $r['marge']['couverture'] < 100)<small>sur {{ $r['marge']['couverture'] }} % des ventes</small>@endif</div>
        <div>Remises<b>{{ $m($r['ventes']['remises']) }}</b></div>
        <div>TVA collectée<b>{{ $m($r['ventes']['tva']) }}</b></div>
        <div>Annulées<b>{{ $r['annulees']['nombre'] }} · {{ $m($r['annulees']['total']) }}</b></div>
    </div>
    @foreach ([['Par moyen de paiement', $r['par_moyen'], 'libelle'], ['Par caissier', $r['par_caissier'], 'nom']] as [$titre, $lignes, $cle])
        <h2>{{ $titre }}</h2>
        <table><tr><th></th><th class="n">Ventes</th><th class="n">Total</th></tr>
            @foreach ($lignes as $l)<tr><td>{{ $l[$cle] }}</td><td class="n">{{ $l['nombre'] }}</td><td class="n">{{ $m($l['total']) }}</td></tr>@endforeach
        </table>
    @endforeach
    <h2>Meilleurs articles</h2>
    <table><tr><th>Article</th><th class="n">Quantité</th><th class="n">Total</th></tr>
        @foreach ($r['top_produits'] as $l)<tr><td>{{ $l['nom'] }}</td><td class="n">{{ \App\Support\Quantite::formater($l['quantite'], $l['unite'] ?? null) }}</td><td class="n">{{ $m($l['total']) }}</td></tr>@endforeach
    </table>
    <h2>Jour par jour</h2>
    <table><tr><th>Date</th><th class="n">Ventes</th><th class="n">Total</th></tr>
        @foreach ($r['par_jour'] as $l)<tr><td>{{ \Illuminate\Support\Carbon::parse($l['date'])->format('d/m/Y') }}</td><td class="n">{{ $l['nombre'] }}</td><td class="n">{{ $m($l['total']) }}</td></tr>@endforeach
    </table>
    <p class="muted" style="margin-top:24px">Ngoni Caisse</p>
</body>
</html>
