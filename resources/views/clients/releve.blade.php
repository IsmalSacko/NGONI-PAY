@php($m = fn ($v) => \App\Support\Money\Montant::format((int) $v, $boutique->devise))
@php($etat = fn (int $s) => $s > 0 ? 'Doit '.$m($s) : ($s < 0 ? 'Vous devez '.$m(-$s) : 'Soldé'))
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Relevé de compte — {{ $client->nom }}</title>
    <style>
        body { font-family: -apple-system, 'Segoe UI', Roboto, Arial, sans-serif; color: #15191e; margin: 32px; }
        h1 { margin: 0; font-size: 22px; color: #0f2a5c; }
        .muted { color: #5b616b; font-size: 13px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 16px; }
        th { background: #0f2a5c; color: #fff; padding: 7px 8px; text-align: left; }
        td { padding: 6px 8px; border-bottom: 1px solid #e3ded3; vertical-align: top; }
        td.n, th.n { text-align: right; white-space: nowrap; }
        tr.report td { background: #f4f2ec; font-style: italic; }
        .doit { color: #b42318; font-weight: 700; } .garde { color: #15803d; font-weight: 700; }
        .total { margin-top: 14px; display: flex; gap: 24px; justify-content: flex-end; font-size: 14px; }
        .total b { font-size: 17px; }
        .actions { margin-bottom: 18px; display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        @media print { .actions { display: none; } body { margin: 0; } }
    </style>
</head>
<body>
    <form class="actions" method="get">
        <button type="button" onclick="window.print()">Imprimer ou enregistrer en PDF</button>
        <label>Du <input type="date" name="du" value="{{ $r['du'] }}"></label>
        <label>au <input type="date" name="au" value="{{ $r['au'] }}"></label>
        <button type="submit">Afficher</button>
        <a href="{{ route('clients.releve', $client) }}">Tout l’historique</a>
    </form>

    <h1>Relevé de compte</h1>
    <p class="muted">
        <b>{{ $boutique->nom }}</b>@if ($boutique->telephone) · {{ $boutique->telephone }}@endif @if ($boutique->adresse) · {{ $boutique->adresse }}@endif
        <br>Client : <b>{{ $client->nom }}</b>@if ($client->telephone) · {{ $client->telephone }}@endif
        <br>{{ $r['du'] ? 'Du '.\Illuminate\Support\Carbon::parse($r['du'])->format('d/m/Y') : 'Depuis le début' }}{{ $r['au'] ? ' au '.\Illuminate\Support\Carbon::parse($r['au'])->format('d/m/Y') : '' }}
        · édité le {{ \App\Support\Fuseau::heure(now(), 'd/m/Y à H:i', $boutique->pays) }} · montants en {{ $boutique->devise }}
    </p>

    <table>
        <thead>
            <tr><th>N°</th><th>Date</th><th>Détail</th><th class="n">Pris à crédit</th><th class="n">Versé</th><th class="n">Reste</th></tr>
        </thead>
        <tbody>
            @if ($r['du'])
                <tr class="report"><td></td><td></td><td>Solde au {{ \Illuminate\Support\Carbon::parse($r['du'])->format('d/m/Y') }}</td><td></td><td></td><td class="n {{ $r['report'] > 0 ? 'doit' : ($r['report'] < 0 ? 'garde' : '') }}">{{ $etat($r['report']) }}</td></tr>
            @endif
            @forelse ($r['lignes'] as $i => $l)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ \App\Support\Fuseau::heure(\Illuminate\Support\Carbon::parse($l['date']), 'd/m/Y H:i', $boutique->pays) }}</td>
                    <td>{{ $l['detail'] }}</td>
                    <td class="n">{{ $l['pris'] > 0 ? $m($l['pris']) : '' }}</td>
                    <td class="n">{{ $l['verse'] > 0 ? $m($l['verse']) : '' }}</td>
                    <td class="n {{ $l['solde'] > 0 ? 'doit' : ($l['solde'] < 0 ? 'garde' : '') }}">{{ $etat($l['solde']) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">Aucun achat à crédit ni versement sur cette période.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="total">
        <span>Pris à crédit<br><b>{{ $m($r['pris']) }}</b></span>
        <span>Versé<br><b>{{ $m($r['verse']) }}</b></span>
        <span>{{ $r['solde'] > 0 ? 'Il doit encore' : ($r['solde'] < 0 ? 'Vous lui devez' : 'Compte soldé') }}<br><b class="{{ $r['solde'] > 0 ? 'doit' : ($r['solde'] < 0 ? 'garde' : '') }}">{{ $m(abs($r['solde'])) }}</b></span>
    </div>
    <p class="muted" style="margin-top:24px">Établi avec Ngoni Caisse</p>
</body>
</html>
