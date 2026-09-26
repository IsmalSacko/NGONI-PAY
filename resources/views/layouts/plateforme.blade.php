<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Console plateforme' }} · e-caisse</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-paper text-ink font-sans antialiased">
    <header class="bg-ink text-white">
        <div class="max-w-7xl mx-auto px-6 h-16 flex items-center gap-6">
            <span class="flex items-center gap-2 font-display font-extrabold">
                <span class="w-9 h-9 rounded-xl bg-accent flex items-center justify-center">e</span>
                Console plateforme
            </span>
            @php
                $enAttente = \App\Models\DemandeAbonnement::enAttente()->count();
                $liens = [
                    ['route' => 'plateforme.tableau', 'label' => 'Tableau de bord'],
                    ['route' => 'plateforme.comptes', 'label' => 'Comptes'],
                    ['route' => 'plateforme.demandes', 'label' => 'Demandes', 'badge' => $enAttente],
                    ['route' => 'plateforme.plans', 'label' => 'Plans et tarifs'],
                    ['route' => 'plateforme.utilisateurs', 'label' => 'Utilisateurs'],
                ];
            @endphp
            <nav class="flex gap-1 text-sm font-semibold">
                @foreach ($liens as $lien)
                    <a href="{{ route($lien['route']) }}"
                       class="px-3 py-2 rounded-lg {{ request()->routeIs($lien['route']) ? 'bg-[#263039]' : 'text-[#B9BEC6] hover:text-white' }}">
                        {{ $lien['label'] }}
                        @if (($lien['badge'] ?? 0) > 0)
                            <span class="ml-1 rounded-full bg-danger-fg px-1.5 text-xs">{{ $lien['badge'] }}</span>
                        @endif
                    </a>
                @endforeach
            </nav>
            <div class="flex-grow"></div>
            <form method="POST" action="{{ route('deconnexion') }}">
                @csrf
                <button class="text-sm text-[#B9BEC6] hover:text-white">Quitter</button>
            </form>
        </div>
    </header>
    <main class="max-w-7xl mx-auto px-6 py-8">
        {{ $slot }}
    </main>
    @livewireScripts
</body>
</html>
