{{--
    Site vitrine de Ngoni Caisse : une page, servie à la racine du domaine aux
    visiteurs non connectés. Les tarifs viennent des plans de la console :
    un prix changé là-bas l'est ici aussi.
--}}
@php
    $titre = 'Ngoni Caisse — La caisse de votre commerce, dans votre téléphone';
    $description = "Encaissez, imprimez vos tickets, suivez vos stocks et votre équipe depuis votre téléphone, même sans réseau. Essai gratuit de {$essaiJours} jours.";
    $apercu = asset('images/og-ngoni-caisse.jpg').'?v='.@filemtime(public_path('images/og-ngoni-caisse.jpg'));
    $wa = \App\Support\WhatsApp::link($whatsapp);
    $waMessage = $wa ? $wa.'?text='.rawurlencode('Bonjour, je voudrais en savoir plus sur Ngoni Caisse.') : null;
    $prix = fn (?int $m) => $m === null ? null : number_format($m, 0, ',', ' ');
    $donnees = [
        '@context' => 'https://schema.org',
        '@type' => 'SoftwareApplication',
        'name' => 'Ngoni Caisse',
        'description' => $description,
        'url' => route('vitrine'),
        'image' => $apercu,
        'applicationCategory' => 'BusinessApplication',
        'operatingSystem' => 'Android',
        'inLanguage' => 'fr',
        'softwareVersion' => $version,
        'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'XOF', 'description' => "Essai gratuit de {$essaiJours} jours"],
    ];
    $fonctions = [
        ['Caisse tactile', "Touchez vos articles, le total se calcule tout seul. Un service ou un article hors catalogue ? Saisissez un montant libre.", 'M3 3h18v4H3zM5 7v14h14V7M9 11h6M9 15h6'],
        ['Tickets et reçus', "Ticket imprimé sur imprimante Bluetooth, ou reçu PDF envoyé au client par WhatsApp. Numéroté à votre nom : PLC-2026-0042.", 'M6 2h12v20l-3-2-3 2-3-2-3 2zM9 7h6M9 11h6M9 15h4'],
        ['Stocks et marges', "Stock mis à jour à chaque vente, alerte avant la rupture. Prix d'achat et marge par article, en un coup d'œil.", 'M3 7l9-4 9 4-9 4-9-4zM3 7v10l9 4 9-4V7M12 11v10'],
        ['Même sans réseau', "Coupure internet ? Continuez à vendre. Les ventes partent d'elles-mêmes dès le retour de la connexion.", 'M2 8.5a15 15 0 0 1 20 0M5 12a10 10 0 0 1 14 0M8.5 15.5a5 5 0 0 1 7 0M12 19h.01'],
        ['Votre équipe', "Ajoutez caissiers et gérants. Chacun voit ce qu'il doit voir : le caissier encaisse, le gérant pilote, vous gardez la main.", 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8'],
        ['Plusieurs boutiques', "Une pharmacie, une annexe, un dépôt : passez de l'une à l'autre en un geste, chacune avec son stock et son équipe.", 'M3 9l1-5h16l1 5M3 9h18v11H3zM3 9a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0M9 20v-6h6v6'],
        ['Pilotage du jour', "Chiffre d'affaires, panier moyen, meilleures ventes, moyens de paiement — comparés à la période précédente : ça va mieux ou pas, d'un coup d'œil.", 'M3 3v18h18M7 15l4-4 3 3 5-6'],
        ['Statistiques avancées', "Avec le plan Pro : heures d'affluence, marge par article, moins vendus, articles bientôt finis, meilleurs clients, ventes de chaque membre de l'équipe.", 'M4 20V10M10 20V4M16 20v-7M22 20H2'],
        ['Back-office web', "Sur ordinateur, gérez tout le catalogue, les stocks, les ventes et l'équipe confortablement — et installez-le comme une application.", 'M3 4h18v12H3zM8 20h8M12 16v4'],
        ['Parrainage', "Recommandez Ngoni Caisse à un commerçant : il a {$parrainage['filleul']} jours d'essai, et vous gagnez un mois offert dès qu'il s'abonne.", 'M20 12v10H4V12M2 7h20v5H2zM12 22V7M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7zM12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z'],
    ];
    $questions = [
        ['Faut-il une connexion internet ?', "Non pour encaisser : les ventes sont gardées dans le téléphone et envoyées au retour du réseau. Il en faut une pour la première connexion et pour synchroniser."],
        ['Quelle imprimante utiliser ?', "Une imprimante thermique Bluetooth de 58 ou 80 mm, comme on en trouve chez les revendeurs de matériel de caisse. Sans imprimante, le reçu PDF part par WhatsApp."],
        ['Dans quelle monnaie ?', "Franc CFA par défaut, et toutes les devises courantes : euro, cedi, dirham… Les centimes sont gérés quand la devise en a."],
        ['Que se passe-t-il à la fin de l’essai ?', "Sans abonnement, votre caisse reste ouverte : vous encaissez toujours les articles de votre catalogue et imprimez vos tickets. En revanche, plus rien ne se modifie — articles, prix, stocks, clients, équipe — et les ventes hors catalogue s’arrêtent. Deux jours avant la fin, un rappel vous prévient. Toutes vos données restent consultables."],
        ['Comment fonctionne le parrainage ?', "Votre code est dans le menu Parrainage de l’application. Le commerçant que vous recommandez le saisit en créant son compte : il reçoit {$parrainage['filleul']} jours d’essai au lieu de {$essaiJours}. Dès qu’il paie son premier abonnement, vous gagnez un mois offert. Les conditions sont détaillées plus haut, dans la section Parrainage."],
        ['Comment payer l’abonnement ?', "Par Orange Money, Wave, Moov Money, espèces ou virement. Vous envoyez la preuve depuis l’application et l’abonnement est activé à réception."],
        ['J’utilisais Ngoni Pay, et mes données ?', "Elles sont conservées : Ngoni Caisse est la nouvelle version de Ngoni Pay. Mettez à jour l’application et reconnectez-vous avec le même numéro et le même mot de passe."],
        ['Et sur iPhone ?', "L’application est disponible sur Android. Le back-office fonctionne dans n’importe quel navigateur, iPhone compris."],
    ];
@endphp
@php
    $url = route('vitrine');
    // Données structurées tirées du contenu même de la page : une question
    // ajoutée à la FAQ apparaît aussi dans les résultats Google, sans rien d'autre à faire.
    $faq = [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => collect($questions)->map(fn ($q) => [
            '@type' => 'Question',
            'name' => $q[0],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]],
        ])->values()->all(),
    ];
    $organisation = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'Organization',
        'name' => 'Ngoni Caisse',
        'url' => $url,
        'logo' => asset('icone-512.png'),
        'contactPoint' => $whatsapp ? ['@type' => 'ContactPoint', 'telephone' => $whatsapp, 'contactType' => 'customer support', 'availableLanguage' => 'French'] : null,
    ]);
    $texteDePartage = 'Ngoni Caisse, la caisse moderne pour les commerçants : ventes, stocks et tickets depuis le téléphone, même hors ligne.';
    $partage = [
        'facebook' => 'https://www.facebook.com/sharer/sharer.php?u='.rawurlencode($url),
        'whatsapp' => 'https://wa.me/?text='.rawurlencode($texteDePartage.' '.$url),
    ];
@endphp
<!doctype html>
<html lang="fr" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $titre }}</title>
    <meta name="description" content="{{ $description }}">
    <link rel="canonical" href="{{ route('vitrine') }}">
    <meta name="robots" content="index, follow">
    <x-tete-commune :indexer="true" />
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Ngoni Caisse">
    <meta property="og:locale" content="fr_FR">
    <meta property="og:url" content="{{ route('vitrine') }}">
    <meta property="og:title" content="{{ $titre }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:image" content="{{ $apercu }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="Ngoni Caisse, la caisse moderne pour les commerçants en Afrique">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $titre }}">
    <meta name="twitter:description" content="{{ $description }}">
    <meta name="twitter:image" content="{{ $apercu }}">
    <script type="application/ld+json">@json($donnees, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)</script>
    <script type="application/ld+json">@json($faq, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)</script>
    <script type="application/ld+json">@json($organisation, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)</script>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-paper text-ink font-sans antialiased">

{{-- Navigation --}}
<header class="sticky top-0 z-40 bg-paper/90 backdrop-blur border-b border-border/70">
    <div class="max-w-6xl mx-auto px-4 md:px-6 h-16 flex items-center gap-6">
        <a href="{{ route('vitrine') }}" aria-label="Ngoni Caisse, accueil">
            <x-logo taille="w-10 h-10" :nom="true" couleur-nom="text-accent" />
        </a>
        <nav class="hidden md:flex items-center gap-6 text-sm font-semibold text-muted">
            <a href="#fonctions" class="hover:text-ink">Fonctions</a>
            <a href="#tarifs" class="hover:text-ink">Tarifs</a>
            <a href="#parrainage" class="hover:text-ink">Parrainage</a>
            <a href="#questions" class="hover:text-ink">Questions</a>
            <a href="#partager" class="hover:text-ink">Partager</a>
        </nav>
        <div class="flex-grow"></div>
        <a href="{{ route('connexion') }}" class="hidden sm:inline-flex h-10 px-4 items-center rounded-xl font-bold text-sm hover:bg-white">Se connecter</a>
        <a href="{{ $storeUrl }}" rel="noopener" class="inline-flex h-10 px-4 items-center rounded-xl bg-accent text-white font-bold text-sm hover:bg-accent-dark">Télécharger</a>
    </div>
    {{-- Téléphone : les mêmes liens, en rangée qui défile sous l'en-tête (la page n'a pas de JavaScript). --}}
    <nav class="md:hidden border-t border-border/70" aria-label="Sections de la page">
        <div class="max-w-6xl mx-auto px-4 flex gap-2 overflow-x-auto whitespace-nowrap py-2 text-sm font-bold [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
            @foreach (['fonctions' => 'Fonctions', 'tarifs' => 'Tarifs', 'parrainage' => 'Parrainage', 'questions' => 'Questions', 'partager' => 'Partager'] as $ancre => $libelle)
                <a href="#{{ $ancre }}" class="shrink-0 inline-flex items-center h-9 px-3.5 rounded-full bg-white border border-border text-accent-dark active:bg-accent-soft">{{ $libelle }}</a>
            @endforeach
            <a href="{{ route('connexion') }}" class="sm:hidden shrink-0 inline-flex items-center h-9 px-3.5 rounded-full bg-accent-soft text-accent-dark">Se connecter</a>
        </div>
    </nav>
</header>

<main>
    {{-- Accroche --}}
    <section class="relative overflow-hidden">
        <div class="absolute -top-40 -right-40 w-[520px] h-[520px] rounded-full bg-accent-soft blur-3xl opacity-70" aria-hidden="true"></div>
        <div class="relative max-w-6xl mx-auto px-4 md:px-6 pt-12 md:pt-20 pb-16 md:pb-24 grid md:grid-cols-[1.1fr_0.9fr] gap-12 items-center">
            <div>
                <span class="inline-flex items-center gap-2 rounded-full bg-white border border-border px-3 py-1 text-xs font-bold text-accent-dark">
                    <span class="w-2 h-2 rounded-full bg-accent"></span> Nouveau : Ngoni Pay devient Ngoni Caisse
                </span>
                <h1 class="mt-5 font-display font-extrabold text-4xl md:text-6xl leading-[1.05] tracking-tight">
                    La caisse de votre commerce, <span class="text-accent">dans votre téléphone.</span>
                </h1>
                <p class="mt-5 text-lg text-muted max-w-xl">
                    Encaissez en quelques secondes, imprimez vos tickets, suivez vos stocks, vos marges et votre équipe —
                    même quand le réseau coupe. Pour la boutique de quartier comme pour la supérette.
                </p>
                <div class="mt-8 flex flex-wrap gap-3">
                    <a href="{{ $storeUrl }}" rel="noopener" class="inline-flex items-center gap-3 h-14 pl-4 pr-6 rounded-2xl bg-ink text-white hover:bg-black">
                        <svg viewBox="0 0 24 24" class="w-7 h-7" aria-hidden="true"><path fill="#34A853" d="M3.6 1.8 13.3 12l-9.7 10.2c-.4-.2-.6-.7-.6-1.2V3c0-.5.2-1 .6-1.2z"/><path fill="#FBBC04" d="m16.8 8.5-3.5 3.5 3.5 3.5 3.9-2.2c.8-.5.8-2.1 0-2.6z"/><path fill="#4285F4" d="M3.6 1.8c.3-.2.8-.2 1.2 0l12 6.7-3.5 3.5z"/><path fill="#EA4335" d="m13.3 12 3.5 3.5-12 6.7c-.4.2-.9.2-1.2 0z"/></svg>
                        <span class="flex flex-col leading-tight"><span class="text-[11px] opacity-80">Disponible sur</span><span class="font-bold text-lg">Google Play</span></span>
                    </a>
                    <a href="#tarifs" class="inline-flex items-center h-14 px-6 rounded-2xl border-2 border-ink/80 font-bold hover:bg-white">Essai gratuit {{ $essaiJours }} jours</a>
                </div>
                <ul class="mt-8 flex flex-wrap gap-x-6 gap-y-2 text-sm text-muted">
                    <li class="flex items-center gap-2"><span class="text-accent font-bold">✓</span> Sans engagement</li>
                    <li class="flex items-center gap-2"><span class="text-accent font-bold">✓</span> Orange Money, Wave, Moov</li>
                    <li class="flex items-center gap-2"><span class="text-accent font-bold">✓</span> Fonctionne hors ligne</li>
                </ul>
            </div>

            {{-- Téléphone dessiné en HTML : l'écran de caisse, sans image à charger. --}}
            <div class="relative mx-auto w-[290px] md:w-[320px]" aria-label="Aperçu de l'écran de caisse de Ngoni Caisse" role="img">
                <div class="absolute -inset-6 rounded-[56px] bg-accent/8 rotate-3" aria-hidden="true"></div>
                <div class="relative rounded-[44px] bg-ink p-3 shadow-2xl">
                    <div class="rounded-[34px] bg-paper overflow-hidden">
                        <div class="h-7 flex items-center justify-center bg-accent"><span class="w-20 h-4 rounded-full bg-ink"></span></div>
                        <div class="px-4 pb-3">
                            <div class="-mx-4 px-4 pb-2 bg-accent text-[11px] font-extrabold text-white flex items-center gap-2"><x-logo taille="w-5 h-5" /> PHARMACIE LES CASTORS</div>
                            <div class="mt-2 h-8 rounded-lg bg-white border border-border text-[11px] text-muted flex items-center px-3">Scanner ou rechercher…</div>
                            <div class="mt-2 flex gap-1.5 text-[10px] font-bold">
                                <span class="rounded-lg bg-jaune text-accent px-2.5 py-1">Tous</span>
                                <span class="rounded-lg bg-puce text-accent px-2.5 py-1">Aliments</span>
                                <span class="rounded-lg bg-puce text-accent px-2.5 py-1">Boissons</span>
                            </div>
                            <div class="mt-3 grid grid-cols-2 gap-2">
                                @foreach ([['Riz parfumé', '4 750', 'RI'], ['Huile 1 L', '1 500', 'HU'], ['Sucre 1 kg', '900', 'SU'], ['Lait en poudre', '2 800', 'LA']] as [$nom, $p, $c])
                                    <div class="rounded-xl bg-white border border-border p-2.5">
                                        <span class="inline-flex w-6 h-6 rounded-md bg-accent-soft text-accent-dark text-[9px] font-bold items-center justify-center">{{ $c }}</span>
                                        <div class="mt-1.5 text-[11px] font-bold leading-tight">{{ $nom }}</div>
                                        <div class="text-[12px] font-extrabold">{{ $p }} F</div>
                                    </div>
                                @endforeach
                            </div>
                            <div class="mt-3 rounded-xl bg-white border border-border p-3">
                                <div class="flex justify-between text-[11px] text-muted"><span>3 articles</span><span>Espèces</span></div>
                                <div class="flex justify-between items-baseline mt-1.5 rounded-lg bg-jaune text-accent px-2.5 py-1.5"><span class="text-[11px] font-extrabold">Total</span><span class="font-display font-extrabold text-lg">9 150 F</span></div>
                                <div class="mt-2 h-9 rounded-lg bg-succes text-white text-[12px] font-bold flex items-center justify-center">Encaisser</div>
                            </div>
                        </div>
                        <div class="h-14 bg-white border-t border-border flex items-center justify-around text-[9px] text-muted relative">
                            <span>Pilotage</span><span>Stocks</span>
                            <span class="w-12 h-12 -mt-7 rounded-full bg-accent border-4 border-white shadow-lg"></span>
                            <span>Ventes</span><span>Plus</span>
                        </div>
                    </div>
                </div>
                <div class="absolute -left-12 -bottom-5 hidden sm:flex items-center gap-2 rounded-2xl bg-white shadow-xl border border-border px-3 py-2">
                    <span class="w-8 h-8 rounded-full bg-accent-soft text-accent-dark flex items-center justify-center font-bold">✓</span>
                    <span class="text-xs"><span class="font-bold block">Ticket PLC-2026-0042</span><span class="text-muted">envoyé par WhatsApp</span></span>
                </div>
            </div>
        </div>
    </section>

    {{-- Pour qui --}}
    <section class="border-y border-border bg-white">
        <div class="max-w-6xl mx-auto px-4 md:px-6 py-6 flex flex-wrap items-center justify-center gap-x-8 gap-y-3 text-sm font-semibold text-muted">
            <span class="text-ink">Pensé pour :</span>
            @foreach (['Boutiques de quartier', 'Pharmacies', 'Supérettes', 'Pressings', 'Quincailleries', 'Salons', 'Restaurants'] as $metier)
                <span>{{ $metier }}</span>
            @endforeach
        </div>
    </section>

    {{-- Fonctions --}}
    <section id="fonctions" class="max-w-6xl mx-auto px-4 md:px-6 py-16 md:py-24">
        <div class="max-w-2xl">
            <p class="text-sm font-bold text-accent uppercase tracking-wider">Fonctions</p>
            <h2 class="mt-2 font-display font-extrabold text-3xl md:text-4xl">Tout ce qu’il faut pour tenir sa caisse, rien de compliqué.</h2>
        </div>
        <div class="mt-10 grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
            @foreach ($fonctions as [$nom, $texte, $icone])
                <div class="rounded-2xl bg-white border border-border p-6 hover:shadow-lg hover:-translate-y-0.5 transition">
                    <span class="w-11 h-11 rounded-xl bg-accent-soft text-accent-dark flex items-center justify-center">
                        <svg viewBox="0 0 24 24" class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $icone }}"/></svg>
                    </span>
                    <h3 class="mt-4 font-display font-bold text-lg">{{ $nom }}</h3>
                    <p class="mt-2 text-sm text-muted leading-relaxed">{{ $texte }}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Comment ça marche --}}
    <section class="bg-accent text-white">
        <div class="max-w-6xl mx-auto px-4 md:px-6 py-16 md:py-24">
            <p class="text-sm font-bold text-jaune uppercase tracking-wider">En 3 minutes</p>
            <h2 class="mt-2 font-display font-extrabold text-3xl md:text-4xl">Votre caisse prête avant le prochain client.</h2>
            <ol class="mt-10 grid md:grid-cols-3 gap-6">
                @foreach ([
                    ['Installez l’application', 'Depuis le Play Store, gratuitement. Créez votre boutique avec votre numéro de téléphone.'],
                    ['Ajoutez vos articles', 'Nom, prix, stock. Ou commencez tout de suite avec le montant libre, sans catalogue.'],
                    ['Encaissez', 'Touchez, encaissez, imprimez ou envoyez le reçu. Votre journée se retrouve dans le pilotage.'],
                ] as $i => [$etape, $texte])
                    <li class="rounded-2xl bg-white/5 border border-white/10 p-6">
                        <span class="font-display font-extrabold text-4xl text-jaune">{{ $i + 1 }}</span>
                        <h3 class="mt-3 font-bold text-lg">{{ $etape }}</h3>
                        <p class="mt-2 text-sm text-white/70 leading-relaxed">{{ $texte }}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- Tarifs --}}
    <section id="tarifs" class="max-w-6xl mx-auto px-4 md:px-6 py-16 md:py-24">
        <div class="text-center max-w-2xl mx-auto">
            <p class="text-sm font-bold text-accent uppercase tracking-wider">Tarifs</p>
            <h2 class="mt-2 font-display font-extrabold text-3xl md:text-4xl">Commencez gratuitement, payez quand ça vous sert.</h2>
            <p class="mt-3 text-muted">{{ $essaiJours }} jours d’essai avec toutes les fonctions, sans carte bancaire. Ensuite, l’offre qui vous correspond.</p>
        </div>
        <div class="mt-10 grid md:grid-cols-2 gap-6 max-w-4xl mx-auto">
            @foreach ($plans as $plan)
                @php($pro = $plan['code'] === 'pro')
                <div class="relative rounded-3xl p-7 md:p-8 {{ $pro ? 'bg-accent text-white shadow-2xl' : 'bg-white border border-border' }}">
                    @if ($pro)
                        <span class="absolute -top-3 right-6 rounded-full bg-jaune text-accent text-xs font-extrabold px-3 py-1">Le plus complet</span>
                    @endif
                    <h3 class="font-display font-extrabold text-2xl">{{ $plan['nom'] }}</h3>
                    <p class="mt-1 text-sm {{ $pro ? 'text-white/80' : 'text-muted' }}">{{ $plan['description'] }}</p>
                    @if ($plan['mensuel'])
                        <p class="mt-6"><span class="font-display font-extrabold text-4xl">{{ $prix($plan['mensuel']) }}</span> <span class="font-semibold">F CFA / mois</span></p>
                    @endif
                    @if ($plan['autres'] !== [])
                        <p class="mt-1 text-sm {{ $pro ? 'text-white/80' : 'text-muted' }}">ou {{ collect($plan['autres'])->map(fn ($t) => $prix($t['montant']).' F CFA par '.$t['unite'])->implode(' · ') }}</p>
                    @endif
                    <ul class="mt-6 flex flex-col gap-2.5 text-sm">
                        <li>✓ {{ $plan['max_boutiques'] === null ? 'Boutiques illimitées' : ($plan['max_boutiques'] === 1 ? '1 boutique' : "Jusqu’à {$plan['max_boutiques']} boutiques") }}</li>
                        <li>✓ {{ $plan['max_membres'] === null ? 'Équipe illimitée' : "{$plan['max_membres']} membres par boutique" }}</li>
                        @foreach ($communes as $commune)
                            <li>✓ {{ $commune }}</li>
                        @endforeach
                        @foreach ($plan['fonctions'] as $fonction)
                            <li class="{{ $fonction['inclus'] ? '' : 'opacity-50' }}">{{ $fonction['inclus'] ? '✓' : '—' }} {{ $fonction['libelle'] }}</li>
                        @endforeach
                    </ul>
                    <a href="{{ $storeUrl }}" rel="noopener"
                       class="mt-8 flex h-12 items-center justify-center rounded-xl font-bold {{ $pro ? 'bg-white text-accent-dark hover:bg-accent-soft' : 'bg-ink text-white hover:bg-black' }}">
                        Essayer gratuitement
                    </a>
                </div>
            @endforeach
        </div>
        <p class="mt-6 text-center text-sm text-muted">Paiement par Orange Money, Wave, Moov Money, espèces ou virement. Tarifs trimestriels et semestriels dans l’application.</p>
        <p class="mt-2 text-center text-sm text-muted">Après l’essai, même sans abonnement, votre caisse reste ouverte sur les articles de votre catalogue.</p>

        {{-- Abonnement à vie : sur demande, réglé une fois. Prix et période des
             conditions d'utilisation (config/conditions.php), boutiques du plan
             Pro de la console : la vitrine ne contredit ni l'un ni l'autre. --}}
        @if ($aVie['ouverte'])
        @php($offresAVie = [
            ['Basic à vie', $aVie['basic'], 'Toutes les fonctions du Basic, sans jamais renouveler.'],
            ['Pro à vie', $aVie['pro'], 'Tout le Pro'.($aVie['pro_boutiques'] ? ", jusqu’à {$aVie['pro_boutiques']} boutiques" : '').', une fois pour toutes.'],
        ])
        <div id="a-vie" class="mt-10 max-w-4xl mx-auto rounded-3xl border-2 border-jaune bg-white p-7 md:p-8">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <h3 class="font-display font-extrabold text-2xl">Abonnement à vie</h3>
                <span class="rounded-full bg-jaune text-accent text-xs font-extrabold px-3 py-1">Offre de lancement</span>
            </div>
            <p class="mt-1 text-sm text-muted">Réglez une seule fois et gardez Ngoni Caisse sans échéance. <strong class="text-ink">Proposé seulement pendant les six premiers mois.</strong></p>
            <div class="mt-6 grid sm:grid-cols-2 gap-4">
                @foreach ($offresAVie as [$nom, $montant, $texte])
                    <div class="rounded-2xl bg-paper border border-border p-5">
                        <p class="font-bold">{{ $nom }}</p>
                        <p class="mt-2"><span class="font-display font-extrabold text-3xl">{{ $prix($montant) }}</span> <span class="font-semibold">F CFA</span> <span class="text-sm text-muted">une seule fois</span></p>
                        <p class="mt-2 text-sm text-muted">{{ $texte }}</p>
                    </div>
                @endforeach
            </div>
            <ul class="mt-5 flex flex-col gap-1.5 text-xs text-muted">
                <li>• Offre de lancement : proposée pendant les six premiers mois seulement, puis retirée.</li>
                <li>• « À vie » : tant que le service Ngoni Caisse existe.</li>
                <li>• Un seul compte, non transférable, dans les limites du plan choisi (boutiques, membres).</li>
                <li>• Remboursable sous certaines conditions.</li>
                @php($jour = fn (string $d) => (($c = \Illuminate\Support\Carbon::parse($d)->locale('fr'))->day === 1 ? '1er' : $c->day).' '.$c->isoFormat('MMMM YYYY'))
                <li>• Offre valable pour toute souscription du {{ $jour($aVie['debut']) }} au {{ $jour($aVie['fin']) }}. <a href="{{ route('conditions') }}#a-vie" class="font-bold text-accent underline">Conditions complètes</a></li>
            </ul>
            @if ($waMessage)
                <a href="{{ $wa }}?text={{ rawurlencode('Bonjour, je suis intéressé(e) par l’abonnement à vie de Ngoni Caisse.') }}" target="_blank" rel="noopener"
                   class="mt-6 inline-flex h-12 px-6 items-center gap-2 rounded-xl bg-whatsapp text-white font-bold hover:opacity-90">
                    <x-icone nom="whatsapp" class="w-5 h-5" /> Demander l’abonnement à vie
                </a>
            @endif
        </div>
        @endif
    </section>

    {{-- Parrainage : l'offre et ses conditions, lisibles avant l'inscription. Les
         durées viennent du service qui les applique : un chiffre changé là-bas
         l'est ici aussi. --}}
    <section id="parrainage" class="max-w-6xl mx-auto px-4 md:px-6 pb-16 md:pb-24">
        <div class="rounded-3xl bg-white border border-border p-7 md:p-10 grid lg:grid-cols-[1fr_1.2fr] gap-8 md:gap-12">
            <div>
                <p class="text-sm font-bold text-accent uppercase tracking-wider">Parrainage</p>
                <h2 class="mt-2 font-display font-extrabold text-3xl md:text-4xl">Invitez un commerçant, gagnez un mois offert.</h2>
                <ol class="mt-6 flex flex-col gap-4">
                    @foreach ([
                        ['Votre code', 'Dans le menu Parrainage de l’application, ou sur la carte « Gagnez 1 mois offert » du Pilotage. Un bouton l’envoie sur WhatsApp.'],
                        ['Il s’inscrit avec', "Le commerçant saisit votre code en créant son compte : {$parrainage['filleul']} jours d’essai au lieu de {$essaiJours}."],
                        ['Vous gagnez un mois', 'Dès qu’il paie son premier abonnement, un mois s’ajoute au vôtre. Vous êtes prévenu dans l’application et par e-mail.'],
                    ] as $i => [$etape, $texte])
                        <li class="flex gap-4">
                            <span class="shrink-0 w-9 h-9 rounded-full bg-accent text-white font-extrabold flex items-center justify-center">{{ $i + 1 }}</span>
                            <div>
                                <h3 class="font-bold">{{ $etape }}</h3>
                                <p class="text-sm text-muted leading-relaxed">{{ $texte }}</p>
                            </div>
                        </li>
                    @endforeach
                </ol>
            </div>
            <div class="rounded-2xl bg-paper border border-border p-6">
                <h3 class="font-display font-bold text-lg">Les conditions pour en bénéficier</h3>
                <ul class="mt-4 flex flex-col gap-2.5 text-sm leading-relaxed">
                    <li>✓ <b>Le parrain</b> est l’administrateur d’une boutique inscrite : c’est lui qui voit le code.</li>
                    <li>✓ <b>Le code se saisit à l’inscription</b>, et seulement à ce moment-là, avec un numéro de téléphone différent de celui du parrain.</li>
                    <li>✓ <b>Le mois est gagné au premier abonnement payé du filleul</b>, une fois le paiement validé. Un accès offert par Ngoni Caisse ne compte pas.</li>
                    <li>✓ <b>Un filleul rapporte une seule fois</b> : ses renouvellements ne comptent pas.</li>
                    <li>✓ <b>Abonnement payé en cours</b> : le mois s’ajoute tout de suite à votre échéance. <b>En essai ou abonnement échu</b> : il est mis de côté et s’ajoute à votre prochain abonnement. Un abonnement illimité ne peut pas être prolongé.</li>
                    <li>✓ <b>Au plus {{ $parrainage['plafond'] }} mois offerts</b> par période de douze mois.</li>
                    <li>✓ <b>Les mois offerts</b> ne s’échangent pas contre de l’argent et ne se transfèrent pas ; ils peuvent être annulés en cas d’abus (comptes créés pour l’occasion, faux paiements).</li>
                </ul>
                <p class="mt-4 text-xs text-muted">Le programme peut évoluer ; les mois déjà gagnés restent acquis.</p>
            </div>
        </div>
    </section>

    {{-- Questions --}}
    <section id="questions" class="bg-white border-y border-border">
        <div class="max-w-3xl mx-auto px-4 md:px-6 py-16 md:py-24">
            <h2 class="font-display font-extrabold text-3xl md:text-4xl text-center">Questions fréquentes</h2>
            <div class="mt-10 flex flex-col gap-3">
                @foreach ($questions as [$question, $reponse])
                    <details class="group rounded-2xl border border-border bg-paper px-5 py-4">
                        <summary class="flex cursor-pointer items-center justify-between gap-4 font-bold list-none">
                            {{ $question }}
                            <span class="text-accent text-xl transition group-open:rotate-45">+</span>
                        </summary>
                        <p class="mt-3 text-muted leading-relaxed">{{ $reponse }}</p>
                    </details>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Appel final --}}
    <section class="max-w-6xl mx-auto px-4 md:px-6 pt-16 md:pt-24 pb-8 md:pb-10">
        <div class="rounded-3xl bg-accent text-white px-6 py-12 md:px-14 md:py-16 grid md:grid-cols-[1fr_auto] gap-8 items-center overflow-hidden relative">
            <div class="absolute -right-20 -bottom-24 w-80 h-80 rounded-full bg-white/10" aria-hidden="true"></div>
            <div class="relative">
                <h2 class="font-display font-extrabold text-3xl md:text-4xl">Prêt à tenir votre caisse autrement ?</h2>
                <p class="mt-3 text-white/85 max-w-xl">Installez Ngoni Caisse et encaissez votre première vente aujourd’hui. Une question ? Écrivez-nous, on vous répond.</p>
            </div>
            <div class="relative flex flex-wrap gap-3">
                <a href="{{ $storeUrl }}" rel="noopener" class="inline-flex h-12 px-6 items-center rounded-xl bg-white text-accent-dark font-bold hover:bg-accent-soft">Télécharger l’application</a>
            </div>
        </div>
    </section>
    {{-- Partager la page : sobre, l'icône de chaque réseau suffit à le reconnaître. --}}
    <section id="partager" class="max-w-6xl mx-auto px-4 md:px-6 pb-16 md:pb-24">
        <div class="rounded-3xl bg-white border border-border px-6 py-6 md:px-10 flex flex-col lg:flex-row lg:items-center gap-5">
            <div class="flex-grow">
                <h2 class="font-display font-bold text-xl md:text-2xl">Un commerçant autour de vous en a besoin&nbsp;?</h2>
                <p class="mt-1 text-muted">Partagez Ngoni Caisse en un geste.</p>
            </div>
            <div class="flex flex-wrap sm:flex-nowrap gap-2">
                @php($bouton = 'inline-flex items-center gap-2 h-11 px-4 rounded-xl border border-border-strong bg-white text-accent font-bold hover:bg-paper whitespace-nowrap')
                <a href="{{ $partage['whatsapp'] }}" target="_blank" rel="noopener" class="{{ $bouton }}">
                    <x-icone nom="whatsapp" class="w-5 h-5 text-whatsapp" /> WhatsApp
                </a>
                <a href="{{ $partage['facebook'] }}" target="_blank" rel="noopener" class="{{ $bouton }}">
                    <x-icone nom="facebook" class="w-5 h-5 text-[#1877F2]" /> Facebook
                </a>
                <button type="button" data-copier="{{ $url }}" class="{{ $bouton }}">
                    <x-icone nom="lien" class="w-5 h-5" /> <span data-libelle>Copier le lien</span>
                </button>
                <button type="button" data-partager data-titre="Ngoni Caisse" data-texte="{{ $texteDePartage }}" data-url="{{ $url }}" hidden class="{{ $bouton }}">
                    <x-icone nom="partager" class="w-5 h-5" /> Partager…
                </button>
            </div>
        </div>
    </section>
</main>

@if ($waMessage)
    <a href="{{ $waMessage }}" target="_blank" rel="noopener" aria-label="Nous écrire sur WhatsApp"
       class="fixed z-50 right-4 bottom-4 md:right-6 md:bottom-6 w-14 h-14 rounded-full bg-whatsapp text-white shadow-xl flex items-center justify-center hover:scale-105 transition">
        <x-icone nom="whatsapp" class="w-8 h-8" />
    </a>
@endif

<footer class="border-t border-border">
    <div class="max-w-6xl mx-auto px-4 md:px-6 py-10 flex flex-col md:flex-row gap-6 md:items-center justify-between text-sm text-muted">
        <x-logo taille="w-9 h-9" :nom="true" couleur-nom="text-accent" />
        <nav class="flex flex-wrap gap-x-6 gap-y-2">
            <a href="#fonctions" class="hover:text-ink">Fonctions</a>
            <a href="#tarifs" class="hover:text-ink">Tarifs</a>
            <a href="#parrainage" class="hover:text-ink">Parrainage</a>
            <a href="{{ route('connexion') }}" class="hover:text-ink">Back-office</a>
            <a href="{{ route('conditions') }}" class="hover:text-ink">Conditions d’utilisation</a>
            <a href="{{ route('confidentialite') }}" class="hover:text-ink">Confidentialité</a>
            <a href="{{ route('mentions-legales') }}" class="hover:text-ink">Mentions légales</a>
            @if ($waMessage)<a href="{{ $waMessage }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 hover:text-ink"><x-icone nom="whatsapp" class="w-4 h-4 text-whatsapp" /> Contact WhatsApp</a>@endif
        </nav>
        <span>© {{ now()->year }} Ngoni Caisse</span>
    </div>
</footer>
<script>
    // Copier le lien (repli pour les navigateurs sans presse-papiers asynchrone).
    document.querySelectorAll('[data-copier]').forEach((bouton) => bouton.addEventListener('click', async () => {
        const lien = bouton.dataset.copier;
        try {
            await navigator.clipboard.writeText(lien);
        } catch (e) {
            const zone = Object.assign(document.createElement('textarea'), { value: lien });
            document.body.append(zone); zone.select(); document.execCommand('copy'); zone.remove();
        }
        const libelle = bouton.querySelector('[data-libelle]');
        libelle.textContent = 'Lien copié ✓';
        setTimeout(() => (libelle.textContent = 'Copier le lien'), 2500);
    }));
    // Partage du téléphone (Android, iPhone) : proposé seulement là où il existe.
    document.querySelectorAll('[data-partager]').forEach((bouton) => {
        if (!navigator.share) return;
        bouton.hidden = false;
        bouton.addEventListener('click', () => navigator.share({ title: bouton.dataset.titre, text: bouton.dataset.texte, url: bouton.dataset.url }).catch(() => {}));
    });
</script>
</body>
</html>
