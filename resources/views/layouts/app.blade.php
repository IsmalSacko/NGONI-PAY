<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'e-caisse' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-paper text-ink font-sans antialiased">
    <div class="flex min-h-screen">
        <nav aria-label="Navigation principale" class="w-20 shrink-0 bg-ink flex flex-col items-center py-4 gap-2">
            <span class="w-11 h-11 rounded-2xl bg-accent text-white flex items-center justify-center font-display font-extrabold text-xl mb-3">e</span>

            @php
                $liens = [
                    ['route' => 'tableau-de-bord', 'label' => 'Pilotage', 'permission' => 'dashboard.view'],
                    ['route' => 'produits.index', 'label' => 'Produits', 'permission' => 'produits.view'],
                    ['route' => 'stocks.index', 'label' => 'Stocks', 'permission' => 'stocks.view'],
                    ['route' => 'ventes.index', 'label' => 'Ventes', 'permission' => 'ventes.view'],
                    ['route' => 'clients.index', 'label' => 'Clients', 'permission' => 'clients.view'],
                    ['route' => 'utilisateurs.index', 'label' => 'Équipe', 'permission' => 'utilisateurs.view'],
                ];
            @endphp

            @foreach ($liens as $lien)
                @can($lien['permission'])
                    <a href="{{ route($lien['route']) }}"
                       class="w-17 h-16 rounded-xl flex flex-col items-center justify-center gap-1 text-[11px] font-semibold no-underline {{ request()->routeIs($lien['route']) ? 'bg-[#263039] text-white' : 'text-[#B9BEC6] hover:text-white' }}">
                        {{ $lien['label'] }}
                    </a>
                @endcan
            @endforeach

            <div class="flex-grow"></div>

            <form method="POST" action="{{ route('deconnexion') }}">
                @csrf
                <button type="submit" class="w-17 h-16 rounded-xl flex flex-col items-center justify-center gap-1 text-[11px] font-semibold text-[#B9BEC6] hover:text-white">
                    Quitter
                </button>
            </form>
        </nav>

        <main class="flex-grow min-w-0 p-8">
            {{ $slot }}
        </main>
    </div>
    @livewireScripts
</body>
</html>
