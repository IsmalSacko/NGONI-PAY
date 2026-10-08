{{-- Reçu d'abonnement en PDF (dompdf, A5) : tableaux plutôt que grilles. --}}
@php
    $devise = in_array($d->devise, ['XOF', 'XAF'], true) ? 'F CFA' : $d->devise;
    $m = fn ($v) => \App\Support\Money\Montant::format((int) $v, $d->devise).' '.$devise;
@endphp
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #15191e; font-size: 11px; margin: 0; }
        .bandeau { background: #0F2A5C; color: #fff; padding: 16px 22px; }
        .bandeau h1 { margin: 0; font-size: 17px; } .bandeau p { margin: 4px 0 0; color: #d6dcea; }
        .page { padding: 16px 22px; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 6px 0; border-bottom: 1px solid #e3e8f0; vertical-align: top; }
        td.l { color: #5a6478; width: 42%; }
        .total td { border-bottom: none; font-size: 14px; font-weight: bold; padding-top: 10px; }
        .paye { display: inline-block; margin-top: 12px; padding: 4px 10px; border-radius: 10px; background: #dcfce7; color: #166534; font-weight: bold; }
        .pied { margin-top: 22px; color: #5a6478; font-size: 9px; line-height: 1.5; }
    </style>
</head>
<body>
    <div class="bandeau">
        <h1>Reçu de paiement</h1>
        <p>N° {{ $d->recu_numero }} · {{ $d->decide_le?->format('d/m/Y à H:i') }}</p>
    </div>
    <div class="page">
        <table>
            <tr><td class="l">Client</td><td>{{ $client }}</td></tr>
            <tr><td class="l">Offre</td><td>Ngoni Caisse {{ $plan }} · {{ $d->cycle->libelle() }}</td></tr>
            @if ($d->periode_debut)
                <tr><td class="l">Période couverte</td><td>du {{ $d->periode_debut->format('d/m/Y') }}{{ $d->periode_fin ? ' au '.$d->periode_fin->format('d/m/Y') : ', sans échéance' }}</td></tr>
            @endif
            <tr><td class="l">Abonnement</td><td>{{ $m($d->montant) }}</td></tr>
            @if ($d->frais_mobile > 0)
                <tr><td class="l">Frais de paiement</td><td>{{ $m($d->frais_mobile) }}</td></tr>
            @endif
            <tr><td class="l">Moyen de paiement</td><td>{{ $moyen }}</td></tr>
            @if ($reference)
                <tr><td class="l">Référence</td><td>{{ $reference }}</td></tr>
            @endif
            <tr class="total"><td class="l">Total payé</td><td>{{ $m($d->montant + $d->frais_mobile) }}</td></tr>
        </table>
        <span class="paye">PAYÉ</span>

        <div class="pied">
            Ngoni Caisse est édité par Ismaila SACKO, entrepreneur individuel (IsmaelDev) —
            RCS Lyon 108 559 998, SIRET 108 559 998 00019 — 18 avenue Maurice Thorez, 69200 Vénissieux, France.
            TVA non applicable, article 293 B du Code général des impôts. Contact : contact@ismael-dev.com
        </div>
    </div>
</body>
</html>
