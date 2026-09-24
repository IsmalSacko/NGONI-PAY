@php
    use App\Enums\StatutPaiement;

    [$titre, $message] = match ($statut) {
        StatutPaiement::Confirme => ['Paiement reçu', 'Merci ! '.$boutique.' a bien reçu votre paiement. Vous pouvez fermer cette page.'],
        StatutPaiement::Echoue => ['Paiement non abouti', 'Le paiement n\'a pas pu être effectué. Aucun montant n\'a été débité. Vous pouvez fermer cette page et réessayer auprès du caissier.'],
        StatutPaiement::Annule => ['Paiement annulé', 'Le paiement a été annulé. Vous pouvez fermer cette page.'],
        default => ['Paiement en cours', 'Nous attendons la confirmation de votre paiement. Cette page se met à jour toute seule.'],
    };
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    @if ($actualiser)
        <meta http-equiv="refresh" content="4">
    @endif
    <title>{{ $titre }} — {{ $boutique }}</title>
    <style>
        :root { color-scheme: light dark; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; box-sizing: border-box; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: #f4f6f5; color: #14201b; }
        main { max-width: 420px; text-align: center; }
        .pastille { width: 72px; height: 72px; border-radius: 50%; margin: 0 auto 20px; display: flex; align-items: center; justify-content: center; font-size: 36px; color: #fff; background: #0b6e4f; }
        .pastille.echec { background: #b3261e; }
        .pastille.attente { background: #8a8f8c; }
        h1 { font-size: 22px; margin: 0 0 8px; }
        p { font-size: 16px; line-height: 1.5; margin: 0; }
        @media (prefers-color-scheme: dark) { body { background: #101413; color: #e6ebe8; } }
    </style>
</head>
<body>
    <main>
        <div class="pastille {{ $statut === StatutPaiement::Confirme ? '' : ($statut === StatutPaiement::EnAttente ? 'attente' : 'echec') }}">
            {{ $statut === StatutPaiement::Confirme ? '✓' : ($statut === StatutPaiement::EnAttente ? '…' : '✕') }}
        </div>
        <h1>{{ $titre }}</h1>
        <p>{{ $message }}</p>
    </main>
</body>
</html>
