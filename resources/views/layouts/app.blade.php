<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Ngoni Caisse' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-paper text-ink font-sans antialiased" x-data="{ menu: false }">
    @php
        $liens = [
            ['route' => 'tableau-de-bord', 'label' => 'Pilotage', 'permission' => 'dashboard.view'],
            ['route' => 'produits.index', 'label' => 'Produits', 'permission' => 'produits.view'],
            ['route' => 'stocks.index', 'label' => 'Stocks', 'permission' => 'stocks.view'],
            ['route' => 'ventes.index', 'label' => 'Ventes', 'permission' => 'ventes.view'],
            ['route' => 'rapports.index', 'label' => 'Rapports', 'permission' => 'rapports.view'],
            ['route' => 'clients.index', 'label' => 'Clients', 'permission' => 'clients.view'],
            ['route' => 'utilisateurs.index', 'label' => 'Équipe', 'permission' => 'utilisateurs.view'],
            ['route' => 'boutiques.index', 'label' => 'Boutiques', 'permission' => 'backoffice.access'],
        ];
        $boutiqueActiveId = app(\App\Support\Tenancy\TenantContext::class)->boutiqueId();
        $mesBoutiques = \App\Models\Boutique::whereIn('id', auth()->user()->boutiquesBackOffice())->orderBy('nom')->get(['id', 'nom']);
        $boutiqueActive = $mesBoutiques->firstWhere('id', $boutiqueActiveId);
    @endphp

    {{-- Téléphone : barre du haut et menu déroulant. --}}
    <header class="md:hidden sticky top-0 z-40 bg-ink text-white">
        <div class="h-14 px-4 flex items-center gap-3">
            <span class="w-9 h-9 rounded-xl bg-accent flex items-center justify-center font-display font-extrabold">N</span>
            <span class="flex-grow truncate font-semibold text-sm">{{ $boutiqueActive?->nom }}</span>
            <button type="button" @click="menu = !menu" :aria-expanded="menu" class="h-10 px-3 rounded-lg bg-[#263039] text-sm font-bold">
                <span x-text="menu ? 'Fermer' : 'Menu'">Menu</span>
            </button>
        </div>
        <nav x-show="menu" x-cloak x-transition.opacity @click.outside="menu = false" class="px-4 pb-4 flex flex-col gap-1" aria-label="Navigation principale">
            @foreach ($liens as $lien)
                @can($lien['permission'])
                    <a href="{{ route($lien['route']) }}"
                       class="h-11 px-3 rounded-lg flex items-center font-semibold no-underline {{ request()->routeIs($lien['route']) ? 'bg-[#263039] text-white' : 'text-[#B9BEC6]' }}">
                        {{ $lien['label'] }}
                    </a>
                @endcan
            @endforeach
            <form method="POST" action="{{ route('deconnexion') }}">
                @csrf
                <button type="submit" class="h-11 px-3 w-full text-left rounded-lg font-semibold text-[#B9BEC6]">Quitter</button>
            </form>
        </nav>
    </header>

    <div class="flex min-h-screen">
        {{-- Ordinateur et tablette : colonne de navigation. --}}
        <nav aria-label="Navigation principale" class="hidden md:flex w-20 shrink-0 bg-ink flex-col items-center py-4 gap-2">
            <span class="w-11 h-11 rounded-2xl bg-accent text-white flex items-center justify-center font-display font-extrabold text-xl mb-3">N</span>

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

        <main class="flex-grow min-w-0 p-4 md:p-8">
            {{-- Boutique de travail : un compte peut en gérer plusieurs. --}}
            <div class="flex justify-end mb-4">
                @if ($mesBoutiques->count() > 1)
                    <form method="POST" action="{{ route('boutique-active') }}" class="flex items-center gap-2 text-sm w-full md:w-auto">
                        @csrf
                        <label for="boutique-active" class="text-[--color-muted] font-semibold">Boutique</label>
                        <select id="boutique-active" name="boutique" onchange="this.form.submit()"
                                class="h-10 px-3 rounded-xl border border-[--color-border-strong] bg-white font-semibold flex-grow md:flex-grow-0 min-w-0">
                            @foreach ($mesBoutiques as $b)
                                <option value="{{ $b->id }}" @selected($b->id === $boutiqueActiveId)>{{ $b->nom }}</option>
                            @endforeach
                        </select>
                    </form>
                @else
                    <span class="hidden md:inline text-sm font-semibold text-[--color-muted]">{{ $mesBoutiques->first()?->nom }}</span>
                @endif
            </div>
            @php
                $abonnementCourant = app(\App\Services\AbonnementService::class)
                    ->pourBoutique(\App\Models\Boutique::find($boutiqueActiveId));
            @endphp
            @if (! $abonnementCourant?->estEnCours())
                <div class="mb-5 rounded-2xl border border-danger-fg/30 bg-danger-bg px-5 py-4 text-sm">
                    <p class="font-bold text-danger-fg">
                        {{ $abonnementCourant?->estEssai() ? 'Essai gratuit terminé' : 'Abonnement expiré' }}
                        @if ($abonnementCourant?->fin) le {{ $abonnementCourant->fin->format('d/m/Y') }} @endif
                    </p>
                    <p class="mt-1">Vos données restent consultables, mais aucune modification n’est possible. Abonnez-vous depuis l’application Ngoni Caisse.</p>
                </div>
            @endif
            @if (session('abonnement_expire'))
                <p class="mb-4 rounded-xl bg-danger-bg text-danger-fg px-4 py-3 text-sm font-semibold">{{ session('abonnement_expire') }}</p>
            @endif
            {{ $slot }}
        </main>
    </div>
    @livewireScripts
    <script>
        // Action refusée faute d'abonnement : on le dit tout de suite, au clic.
        document.addEventListener('livewire:init', () => {
            Livewire.on('abonnement-expire', () => alert(
                "Votre essai ou abonnement est terminé : vos données restent consultables, "
                + "mais aucune modification n'est possible. Abonnez-vous depuis l'application Ngoni Caisse."
            ));
        });
    </script>
</body>
</html>
