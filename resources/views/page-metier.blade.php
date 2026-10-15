{{--
    Page de la vitrine pour un métier, et un pays s'il est donné : voir
    App\Support\PagesMetier. Ce que les commerçants cherchent dans Google
    (« caisse pour pharmacie au Sénégal »), et ce que l'application fait
    vraiment pour eux.
--}}
@php
    use App\Support\PagesMetier;
    $dans = $pays['dans'] ?? null;
    $titre = $metier['titre'].($dans ? ' '.$dans : '').' — Ngoni Caisse';
    $h1 = $metier['titre'].($dans ? ' '.$dans : '');
    $description = $metier['accroche'].($pays ? " Paiement par {$pays['paiements']}." : '')." Essai gratuit de {$essaiJours} jours.";
    $url = route('page-metier', array_filter([$slug, $paysSlug]));
    $apercu = asset('images/og-ngoni-caisse.jpg');
    $prix = $prixMensuel ? number_format($prixMensuel, 0, ',', ' ').' F CFA' : null;
    $questions = [
        ...$metier['questions'],
        $pays
            ? ['Comment payer l’abonnement '.$pays['dans'].' ?', "Par {$pays['paiements']}, directement depuis l’application. L’abonnement s’active dès le paiement reçu."]
            : ['Dans quels pays ?', 'Partout où l’on vend en franc CFA, et dans les autres monnaies courantes : '.collect(PagesMetier::PAYS)->pluck('nom')->implode(', ').'…'],
        ['Combien ça coûte ?', $prix
            ? "Essai gratuit de {$essaiJours} jours, puis à partir de {$prix} par mois, sans engagement."
            : "Essai gratuit de {$essaiJours} jours ; les tarifs s’affichent dans l’application, sans engagement."],
        ['Faut-il internet pour encaisser ?', 'Non : les ventes sont gardées dans le téléphone et envoyées au retour du réseau.'],
    ];
    $donnees = [
        '@context' => 'https://schema.org',
        '@graph' => [
            [
                '@type' => 'SoftwareApplication',
                'name' => 'Ngoni Caisse',
                'description' => $description,
                'url' => $url,
                'applicationCategory' => 'BusinessApplication',
                'operatingSystem' => 'Android',
                'inLanguage' => 'fr',
                'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => $pays['devise'] ?? 'XOF', 'description' => "Essai gratuit de {$essaiJours} jours"],
                ...($pays ? ['areaServed' => ['@type' => 'Country', 'name' => $pays['nom']]] : []),
            ],
            [
                '@type' => 'FAQPage',
                'mainEntity' => collect($questions)->map(fn ($q) => ['@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]])->values()->all(),
            ],
            [
                '@type' => 'BreadcrumbList',
                'itemListElement' => array_values(array_filter([
                    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Ngoni Caisse', 'item' => route('vitrine')],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => $metier['nom'], 'item' => route('page-metier', [$slug])],
                    $pays ? ['@type' => 'ListItem', 'position' => 3, 'name' => $pays['nom'], 'item' => $url] : null,
                ])),
            ],
        ],
    ];
@endphp
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $titre }}</title>
    <meta name="description" content="{{ $description }}">
    <link rel="canonical" href="{{ $url }}">
    <meta name="robots" content="index, follow">
    <x-tete-commune :indexer="true" />
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Ngoni Caisse">
    <meta property="og:locale" content="fr_FR">
    <meta property="og:url" content="{{ $url }}">
    <meta property="og:title" content="{{ $titre }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:image" content="{{ $apercu }}">
    <meta name="twitter:card" content="summary_large_image">
    <script type="application/ld+json">@json($donnees, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)</script>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-paper text-ink font-sans antialiased">

<header class="sticky top-0 z-40 bg-paper/90 backdrop-blur border-b border-border/70">
    <div class="max-w-6xl mx-auto px-4 md:px-6 h-16 flex items-center gap-6">
        <a href="{{ route('vitrine') }}" aria-label="Ngoni Caisse, accueil">
            <x-logo taille="w-10 h-10" :nom="true" couleur-nom="text-accent" />
        </a>
        <div class="flex-grow"></div>
        <a href="{{ route('vitrine') }}#tarifs" class="hidden sm:inline-flex h-10 px-4 items-center rounded-xl font-bold text-sm hover:bg-white">Tarifs</a>
        <a href="{{ $storeUrl }}" rel="noopener" class="inline-flex h-10 px-4 items-center rounded-xl bg-accent text-white font-bold text-sm hover:bg-accent-dark">Télécharger</a>
    </div>
</header>

<main>
    <section class="relative overflow-hidden">
        <div class="absolute -top-40 -right-40 w-[520px] h-[520px] rounded-full bg-accent-soft blur-3xl opacity-70" aria-hidden="true"></div>
        <div class="relative max-w-4xl mx-auto px-4 md:px-6 pt-10 md:pt-16 pb-14 md:pb-20">
            <nav class="text-sm text-muted" aria-label="Fil d’Ariane">
                <a href="{{ route('vitrine') }}" class="hover:text-ink">Ngoni Caisse</a> ›
                <a href="{{ route('page-metier', [$slug]) }}" class="hover:text-ink">{{ $metier['nom'] }}</a>
                @if ($pays) › <span>{{ $pays['nom'] }}</span> @endif
            </nav>
            <h1 class="mt-4 font-display font-extrabold text-3xl md:text-5xl leading-tight tracking-tight">{{ $h1 }}</h1>
            <p class="mt-5 text-lg text-muted max-w-2xl">{{ $metier['accroche'] }}</p>
            @if ($pays)
                <p class="mt-3 text-lg text-muted max-w-2xl">
                    Pour {{ $metier['pour'] }} à {{ $pays['ville'] }} ou ailleurs {{ $pays['dans'] }} : vos prix en {{ $pays['devise'] === 'GNF' ? 'franc guinéen' : 'franc CFA' }},
                    vos clients qui paient par {{ $pays['paiements'] }}, vos tickets à votre nom.
                </p>
            @endif
            <div class="mt-8 flex flex-wrap gap-3">
                <a href="{{ $storeUrl }}" rel="noopener" class="inline-flex items-center h-14 px-6 rounded-2xl bg-ink text-white font-bold hover:bg-black">Télécharger sur Google Play</a>
                <span class="inline-flex items-center h-14 px-6 rounded-2xl border-2 border-ink/80 font-bold">Essai gratuit {{ $essaiJours }} jours</span>
            </div>
            @if ($prix)
                <p class="mt-3 text-sm text-muted">Puis à partir de {{ $prix }} par mois, sans engagement.</p>
            @endif
        </div>
    </section>

    <section class="bg-white border-y border-border">
        <div class="max-w-6xl mx-auto px-4 md:px-6 py-14 md:py-20">
            <h2 class="font-display font-extrabold text-2xl md:text-3xl">Ce que Ngoni Caisse fait pour {{ $metier['pour'] }}</h2>
            <div class="mt-8 grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach ($metier['points'] as [$point, $detail])
                    <div class="rounded-2xl border border-border bg-paper p-5">
                        <h3 class="font-bold text-lg"><span class="text-accent">✓</span> {{ $point }}</h3>
                        <p class="mt-2 text-muted">{{ $detail }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="max-w-4xl mx-auto px-4 md:px-6 py-14 md:py-20">
        <h2 class="font-display font-extrabold text-2xl md:text-3xl">Questions fréquentes</h2>
        <div class="mt-6 divide-y divide-border border-y border-border">
            @foreach ($questions as [$question, $reponse])
                <details class="py-4 group">
                    <summary class="font-bold cursor-pointer">{{ $question }}</summary>
                    <p class="mt-2 text-muted">{{ $reponse }}</p>
                </details>
            @endforeach
        </div>
        <div class="mt-10 rounded-2xl bg-accent text-white p-6 md:p-8 flex flex-col md:flex-row md:items-center gap-4 justify-between">
            <p class="font-display font-extrabold text-xl">Essayez Ngoni Caisse gratuitement pendant {{ $essaiJours }} jours.</p>
            <a href="{{ $storeUrl }}" rel="noopener" class="inline-flex items-center h-12 px-6 rounded-xl bg-white text-accent-dark font-bold">Télécharger</a>
        </div>
    </section>

    {{-- Les pages voisines : d'autres pays pour ce métier, d'autres métiers. --}}
    <section class="bg-white border-t border-border">
        <div class="max-w-6xl mx-auto px-4 md:px-6 py-12 grid md:grid-cols-2 gap-8 text-sm">
            <div>
                <h2 class="font-bold text-base">{{ $metier['titre'] }}, par pays</h2>
                <ul class="mt-3 flex flex-wrap gap-2">
                    @foreach (PagesMetier::PAYS as $code => $p)
                        <li><a href="{{ route('page-metier', [$slug, $code]) }}" class="inline-flex h-9 px-3 items-center rounded-full border border-border hover:bg-paper {{ $code === $paysSlug ? 'bg-accent-soft font-bold' : '' }}">{{ $p['nom'] }}</a></li>
                    @endforeach
                </ul>
            </div>
            <div>
                <h2 class="font-bold text-base">Ngoni Caisse pour votre métier{{ $dans ? ' '.$dans : '' }}</h2>
                <ul class="mt-3 flex flex-wrap gap-2">
                    @foreach (PagesMetier::METIERS as $code => $m)
                        <li><a href="{{ route('page-metier', array_filter([$code, $paysSlug])) }}" class="inline-flex h-9 px-3 items-center rounded-full border border-border hover:bg-paper {{ $code === $slug ? 'bg-accent-soft font-bold' : '' }}">{{ $m['nom'] }}</a></li>
                    @endforeach
                </ul>
            </div>
        </div>
    </section>
</main>

<footer class="border-t border-border">
    <div class="max-w-6xl mx-auto px-4 md:px-6 py-8 flex flex-col md:flex-row gap-4 md:items-center justify-between text-sm text-muted">
        <a href="{{ route('vitrine') }}"><x-logo taille="w-8 h-8" :nom="true" couleur-nom="text-accent" /></a>
        <nav class="flex flex-wrap gap-x-6 gap-y-2">
            <a href="{{ route('vitrine') }}" class="hover:text-ink">Accueil</a>
            <a href="{{ route('telecharger') }}" class="hover:text-ink">Télécharger</a>
            <a href="{{ route('conditions') }}" class="hover:text-ink">Conditions d’utilisation</a>
            <a href="{{ route('confidentialite') }}" class="hover:text-ink">Confidentialité</a>
        </nav>
        <span>© {{ now()->year }} Ngoni Caisse</span>
    </div>
</footer>
</body>
</html>
