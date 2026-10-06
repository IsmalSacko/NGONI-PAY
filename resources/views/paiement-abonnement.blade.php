<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Paiement de l’abonnement · Ngoni Caisse</title>
    <meta name="robots" content="noindex">
    <x-tete-commune :indexer="false" />
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center;
               font-family: "Segoe UI", Arial, sans-serif; background: #eef2f8; color: #1f2937; }
        main { max-width: 420px; margin: 24px 16px; text-align: center; background: #fff;
               border-radius: 20px; padding: 36px 28px; box-shadow: 0 10px 24px rgba(0,0,0,.08); }
        .pastille { width: 72px; height: 72px; margin: 0 auto; border-radius: 50%; display: grid; place-items: center;
                    font-size: 36px; background: {{ $etat === 'paye' ? '#dcfce7' : (in_array($etat, ['echec', 'abandon'], true) ? '#fee2e2' : '#fef3c7') }}; }
        h1 { margin: 16px 0 8px; font-size: 22px; color: #0F2A5C; }
        p { color: #4b5563; line-height: 1.5; }
        a.bouton { display: inline-block; margin-top: 12px; padding: 14px 26px; border-radius: 12px;
                   background: #0F2A5C; color: #fff; font-weight: 700; text-decoration: none; }
    </style>
</head>
<body>
<main>
    <div class="pastille">{{ $etat === 'paye' ? '✓' : (in_array($etat, ['echec', 'abandon'], true) ? '✕' : '⏳') }}</div>
    @if ($etat === 'paye')
        <h1>Paiement reçu, merci !</h1>
        <p>Votre abonnement {{ $plan }} est activé{{ $fin ? ' jusqu’au '.$fin : '' }}. Retournez dans Ngoni Caisse : toutes ses fonctions sont ouvertes.</p>
    @elseif ($etat === 'echec')
        <h1>Le paiement n’a pas abouti</h1>
        <p>Aucun montant n’a été prélevé pour cet abonnement. Retournez dans Ngoni Caisse pour réessayer, avec le même moyen ou un autre.</p>
    @elseif ($etat === 'abandon')
        <h1>Paiement abandonné</h1>
        <p>Vous avez quitté la page de paiement : rien n’a été prélevé. Retournez dans Ngoni Caisse pour réessayer, ou annuler la demande.</p>
    @else
        <h1>Paiement en cours de confirmation</h1>
        <p>Votre opérateur confirme le paiement. Retournez dans Ngoni Caisse : l’abonnement s’active dès la confirmation, en général en quelques secondes.</p>
    @endif
    <a class="bouton" href="{{ url('/app') }}">Retour à Ngoni Caisse</a>
</main>
</body>
</html>
