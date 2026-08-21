<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ $title }}</title>
    <meta name="description" content="{{ $description }}">

    {{--
        Open Graph : c'est ce qui fait qu'un lien partagé sur WhatsApp ou Facebook
        affiche le nom, la description et le visuel de l'application au lieu d'une
        adresse nue. Sans ces balises, l'annonce partagée par un commerçant à ses
        collègues ne ressemble à rien.

        L'URL absolue est indispensable : les moissonneurs ne résolvent pas les
        chemins relatifs.
    --}}
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="NGONI PAY">
    <meta property="og:locale" content="fr_FR">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ url('/telecharger') }}">
    <meta property="og:image" content="{{ asset('images/og-ngonipay.png') }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="NGONI PAY">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $title }}">
    <meta name="twitter:description" content="{{ $description }}">
    <meta name="twitter:image" content="{{ asset('images/og-ngonipay.png') }}">

    <link rel="icon" href="{{ asset('favicon.ico') }}">
    @vite(['resources/css/app.css'])
</head>
<body class="bg-slate-50">
    <div class="mx-auto flex min-h-screen max-w-3xl flex-col items-center justify-center px-6 py-16 text-center">
        <img src="{{ asset('images/og-ngonipay.png') }}" alt="NGONI PAY"
             class="mb-10 w-full max-w-md rounded-2xl shadow-lg">

        <h1 class="text-3xl font-bold text-slate-900 sm:text-4xl">
            NGONI PAY
            @if ($version)
                <span class="text-indigo-600">{{ $version }}</span>
            @endif
        </h1>

        <p class="mt-4 max-w-xl text-base leading-relaxed text-slate-600">
            {{ $description }}
        </p>

        <a href="{{ $storeUrl }}" rel="noopener"
           class="mt-10 inline-flex items-center gap-3 rounded-xl bg-indigo-600 px-8 py-4 text-base font-semibold text-white shadow-lg shadow-indigo-600/25 transition hover:bg-indigo-700">
            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 3v12m0 0-4-4m4 4 4-4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/>
            </svg>
            Télécharger sur Google Play
        </a>

        <p class="mt-6 text-sm text-slate-500">
            Déjà installée ? Ouvrez l'application : la mise à jour vous sera proposée.
        </p>

        <div class="mt-14 border-t border-slate-200 pt-8 text-xs text-slate-400">
            Encaissez par mobile money ou en espèces, éditez vos reçus, suivez vos
            recettes — au Mali comme ailleurs.
        </div>
    </div>
</body>
</html>
