{{--
    Page juridique (conditions, mentions légales, confidentialité) : même
    habillage que le site vitrine, texte lisible sur téléphone, version et date
    en tête. Chaque page passe son contenu dans $slot.
--}}
@props(['titre', 'actif', 'canonique'])
@php
    $version = \App\Services\ConditionsUtilisation::version();
    $miseAJour = \Illuminate\Support\Carbon::parse($version)->locale('fr')->isoFormat('D MMMM YYYY');
    $pages = ['conditions' => 'Conditions d’utilisation', 'mentions' => 'Mentions légales', 'confidentialite' => 'Confidentialité'];
    $liens = ['conditions' => route('conditions'), 'mentions' => route('mentions-legales'), 'confidentialite' => route('confidentialite')];
@endphp
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $titre }} · Ngoni Caisse</title>
    <link rel="canonical" href="{{ $canonique }}">
    <x-tete-commune :indexer="true" />
    @vite(['resources/css/app.css'])
    <style>
        .juridique h2 { font-family: var(--font-display); font-weight: 800; font-size: 1.3rem; margin: 2.2rem 0 .7rem; color: var(--color-ink); scroll-margin-top: 5rem; }
        .juridique h3 { font-weight: 800; font-size: 1.05rem; margin: 1.4rem 0 .4rem; color: var(--color-ink); }
        .juridique p { margin: 0 0 .8rem; line-height: 1.7; }
        .juridique ul { margin: 0 0 .9rem 1.2rem; list-style: disc; }
        .juridique li { margin: .3rem 0; line-height: 1.6; }
        .juridique a { color: var(--color-accent); font-weight: 700; text-decoration: underline; }
        .juridique .encadre { background: var(--color-jaune-doux); border-radius: 1rem; padding: 1rem 1.2rem; margin: 1rem 0; }
        .juridique table { width: 100%; border-collapse: collapse; margin: .6rem 0 1rem; font-size: .95rem; }
        .juridique th, .juridique td { text-align: left; padding: .5rem .6rem; border-bottom: 1px solid var(--color-border); vertical-align: top; }
    </style>
</head>
<body class="bg-paper text-ink font-sans antialiased">
<header class="sticky top-0 z-40 bg-paper/90 backdrop-blur border-b border-border/70">
    <div class="max-w-4xl mx-auto px-4 md:px-6 h-16 flex items-center justify-between gap-4">
        <a href="{{ route('vitrine') }}" aria-label="Ngoni Caisse, accueil"><x-logo taille="w-9 h-9" :nom="true" couleur-nom="text-accent" /></a>
        <a href="{{ route('vitrine') }}" class="text-sm font-bold text-accent hover:underline">← Retour au site</a>
    </div>
</header>
<main class="max-w-4xl mx-auto px-4 md:px-6 py-8 md:py-12">
    <nav class="flex flex-wrap gap-2 mb-6" aria-label="Pages juridiques">
        @foreach ($pages as $cle => $libelle)
            <a href="{{ $liens[$cle] }}" @if ($cle === $actif) aria-current="page" @endif
               class="rounded-xl px-3.5 py-2 text-sm font-bold {{ $cle === $actif ? 'bg-jaune text-accent' : 'bg-white text-accent shadow-carte hover:bg-accent-soft' }}">{{ $libelle }}</a>
        @endforeach
    </nav>
    <article class="juridique rounded-3xl bg-white shadow-carte p-6 md:p-10 text-[15.5px] text-ink/90">
        <h1 class="font-display font-extrabold text-3xl md:text-4xl text-ink">{{ $titre }}</h1>
        <p class="mt-2 text-sm text-muted">Version du {{ $miseAJour }} · en vigueur à cette date</p>
        {{ $slot }}
    </article>
    <p class="mt-6 text-center text-xs text-muted">© {{ now()->year }} Ngoni Caisse — édité par IsmaelDev (Ismaila SACKO), RCS Lyon 108 559 998</p>
</main>
</body>
</html>
