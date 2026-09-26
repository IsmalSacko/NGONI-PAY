<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $titre }}</title>
    <meta name="description" content="{{ $description }}">
    {{-- Balises Open Graph : un lien partagé sur WhatsApp affiche le nom, la
         description et l'icône de l'application, pas une simple adresse. --}}
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="e-caisse">
    <meta property="og:locale" content="fr_FR">
    <meta property="og:title" content="{{ $titre }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ url('/telecharger') }}">
    <meta property="og:image" content="{{ asset('images/e-caisse.png') }}">
    <meta property="og:image:alt" content="e-caisse">
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center;
               font-family: "Segoe UI", Arial, sans-serif; background: #f3f7f5; color: #1f2937; }
        main { max-width: 420px; margin: 24px 16px; text-align: center; background: #fff;
               border-radius: 20px; padding: 36px 28px; box-shadow: 0 10px 24px rgba(0,0,0,.08); }
        img { width: 96px; height: 96px; border-radius: 22px; }
        h1 { margin: 16px 0 4px; font-size: 26px; }
        .version { color: #0B6E4F; font-size: 16px; font-weight: 600; }
        p { color: #4b5563; line-height: 1.5; }
        a.bouton { display: inline-block; margin-top: 12px; padding: 14px 26px; border-radius: 12px;
                   background: #0B6E4F; color: #fff; font-weight: 700; text-decoration: none; }
    </style>
</head>
<body>
<main>
    <img src="{{ asset('images/e-caisse.png') }}" alt="e-caisse">
    <h1>e-caisse @if ($version)<span class="version">{{ $version }}</span>@endif</h1>
    <p>{{ $description }}</p>
    <a class="bouton" href="{{ $storeUrl }}" rel="noopener">Télécharger sur Google Play</a>
</main>
</body>
</html>
