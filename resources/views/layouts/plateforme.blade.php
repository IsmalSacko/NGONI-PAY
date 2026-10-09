<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Console plateforme' }} · Ngoni Caisse</title>
    <x-tete-commune />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-paper text-ink font-sans antialiased">
    @php
        $enAttente = \App\Models\DemandeAbonnement::enAttente()->count();
        $liens = [
            ['route' => 'plateforme.tableau', 'label' => 'Tableau de bord'],
            ['route' => 'plateforme.comptes', 'label' => 'Comptes'],
            ['route' => 'plateforme.demandes', 'label' => 'Demandes', 'badge' => $enAttente],
            ['route' => 'plateforme.plans', 'label' => 'Plans et tarifs'],
            ['route' => 'plateforme.utilisateurs', 'label' => 'Utilisateurs'],
            ['route' => 'plateforme.annonces', 'label' => 'Annonces'],
            ['route' => 'plateforme.tutoriels', 'label' => 'Tutoriels'],
        ];
    @endphp
    <header class="bg-accent text-white sticky top-0 z-40">
        <div class="max-w-7xl mx-auto px-3 sm:px-4 md:px-6 h-16 flex items-center gap-1.5 sm:gap-3 md:gap-5">
            <a href="{{ route('plateforme.tableau') }}" class="flex items-center gap-3 min-w-0">
                {{-- Téléphone : le ticket seul ; le nom, à côté des boutons, n'y tient pas. --}}
                <span class="shrink-0 sm:hidden"><x-logo taille="w-10 h-10" /></span>
                <span class="shrink-0 hidden sm:block"><x-logo taille="w-10 h-10" :nom="true" /></span>
                <span class="hidden lg:inline pl-3 ml-1 border-l border-white/20 text-sm font-semibold text-rail">Console plateforme</span>
            </a>
            <div class="flex-grow"></div>
            <button type="button" onclick="location.reload()" title="Actualiser la page" aria-label="Actualiser la page"
                    class="h-10 w-10 shrink-0 rounded-xl text-rail hover:text-white hover:bg-nuit-clair inline-flex items-center justify-center">
                <x-icone nom="actualiser" />
            </button>
            {{-- La vitrine telle que la voient les visiteurs (aperçu, même connecté). --}}
            <a href="{{ route('vitrine', ['apercu' => 1]) }}" target="_blank" rel="noopener"
               class="shrink-0 inline-flex items-center gap-2 h-10 px-3 sm:px-4 rounded-xl bg-jaune text-accent text-sm font-extrabold" title="Ouvrir le site vitrine">
                <x-icone nom="site" class="w-4 h-4" />
                <span class="hidden sm:inline">Voir le site</span>
            </a>
            <button type="button" data-installer hidden class="h-10 px-3 rounded-xl text-sm font-extrabold bg-jaune text-accent">Installer</button>
            <a href="{{ route('mon-compte') }}" title="Mon compte"
               class="shrink-0 h-10 px-2.5 sm:px-3 rounded-xl text-sm font-bold inline-flex items-center {{ request()->routeIs('mon-compte') ? 'bg-jaune text-accent' : 'text-rail hover:text-white hover:bg-nuit-clair' }}">
                <span class="hidden sm:inline">{{ auth()->user()->name }}</span><span class="sm:hidden">Compte</span>
            </a>
            <form method="POST" action="{{ route('deconnexion') }}">
                @csrf
                <button class="shrink-0 h-10 px-2.5 sm:px-3 rounded-xl text-sm font-bold text-rail hover:text-white hover:bg-nuit-clair">Quitter</button>
            </form>
        </div>
        {{-- Onglets : défilants sur téléphone, l'actif en jaune. --}}
        <nav class="max-w-7xl mx-auto px-4 md:px-6 pb-3 flex gap-1.5 text-sm font-bold overflow-x-auto whitespace-nowrap [scrollbar-width:none]" aria-label="Navigation de la console">
            @foreach ($liens as $lien)
                <a href="{{ route($lien['route']) }}"
                   class="shrink-0 inline-flex items-center px-3.5 h-10 rounded-xl {{ request()->routeIs($lien['route']) ? 'bg-jaune text-accent' : 'text-rail hover:text-white hover:bg-nuit-clair' }}">
                    {{ $lien['label'] }}
                    @if (($lien['badge'] ?? 0) > 0)
                        <span class="ml-1.5 rounded-full bg-danger-fg text-white px-1.5 text-xs">{{ $lien['badge'] }}</span>
                    @endif
                </a>
            @endforeach
        </nav>
    </header>
    <main class="max-w-7xl mx-auto px-4 md:px-6 py-5 md:py-8">
        {{ $slot }}
    </main>
    <x-toast />
    @livewireScripts
</body>
</html>
