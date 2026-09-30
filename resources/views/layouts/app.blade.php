<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Ngoni Caisse' }}</title>
    <x-tete-commune />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-paper text-ink font-sans antialiased" x-data="{ menu: false }">
    @php
        $liens = [
            ['route' => 'tableau-de-bord', 'label' => 'Pilotage', 'permission' => 'dashboard.view'],
            ['route' => 'produits.index', 'label' => 'Produits', 'permission' => 'produits.view'],
            ['route' => 'stocks.index', 'label' => 'Stocks', 'permission' => 'stocks.view'],
            ['route' => 'achats.index', 'label' => 'Achats', 'permission' => 'achats.view'],
            ['route' => 'ventes.index', 'label' => 'Ventes', 'permission' => 'ventes.view'],
            ['route' => 'statistiques.index', 'label' => 'Statistiques', 'permission' => 'rapports.view'],
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
    <header class="md:hidden sticky top-0 z-40 bg-accent text-white">
        <div class="h-16 px-4 flex items-center gap-3">
            <x-logo taille="w-10 h-10" />
            <span class="flex-grow min-w-0 flex flex-col leading-tight">
                <span class="truncate font-extrabold">{{ $boutiqueActive?->nom }}</span>
                <span class="truncate text-xs text-rail">{{ auth()->user()->name }}</span>
            </span>
            <button type="button" @click="menu = !menu" :aria-expanded="menu" aria-controls="menu-telephone"
                    class="h-10 px-4 rounded-xl bg-jaune text-accent text-sm font-extrabold">
                <span x-text="menu ? 'Fermer' : 'Menu'">Menu</span>
            </button>
        </div>
        <nav id="menu-telephone" x-show="menu" x-cloak x-transition.opacity @click.outside="menu = false"
             class="px-4 pb-4 grid grid-cols-2 gap-1.5 max-h-[75vh] overflow-y-auto" aria-label="Navigation principale">
            @foreach ($liens as $lien)
                @can($lien['permission'])
                    <a href="{{ route($lien['route']) }}"
                       class="h-12 px-3 rounded-xl flex items-center font-bold no-underline {{ request()->routeIs($lien['route']) ? 'bg-jaune text-accent' : 'bg-nuit-clair text-white' }}">
                        {{ $lien['label'] }}
                    </a>
                @endcan
            @endforeach
            <a href="{{ route('mon-compte') }}"
               class="h-12 px-3 rounded-xl flex items-center font-bold no-underline {{ request()->routeIs('mon-compte') ? 'bg-jaune text-accent' : 'bg-nuit-clair text-white' }}">Mon compte</a>
            <button type="button" data-installer hidden class="col-span-2 h-12 px-3 rounded-xl bg-jaune text-accent font-extrabold text-left">Installer l’app sur ce téléphone</button>
            <form method="POST" action="{{ route('deconnexion') }}">
                @csrf
                <button type="submit" class="h-12 px-3 w-full text-left rounded-xl font-bold text-rail">Quitter</button>
            </form>
        </nav>
    </header>

    <div class="flex min-h-screen">
        {{-- Ordinateur et tablette : colonne de navigation, onglet actif en jaune.
             Étroite sur tablette, large sur ordinateur : des libellés lisibles. --}}
        <nav aria-label="Navigation principale" class="hidden md:flex w-28 lg:w-56 shrink-0 bg-accent flex-col items-center py-4 gap-1.5 sticky top-0 h-screen overflow-y-auto">
            <x-logo taille="w-12 h-12" class="mb-4" />

            @foreach ($liens as $lien)
                @can($lien['permission'])
                    <a href="{{ route($lien['route']) }}"
                       class="w-24 lg:w-48 h-12 rounded-2xl flex items-center justify-center lg:justify-start text-center lg:text-left px-2 lg:px-4 text-[13px] lg:text-[15px] font-bold leading-tight no-underline {{ request()->routeIs($lien['route']) ? 'bg-jaune text-accent' : 'text-rail hover:text-white hover:bg-nuit-clair' }}">
                        {{ $lien['label'] }}
                    </a>
                @endcan
            @endforeach

            <div class="flex-grow"></div>

            <button type="button" data-installer hidden title="Installer l’app sur cet appareil"
                    class="w-24 lg:w-48 h-12 rounded-2xl text-[13px] lg:text-[15px] font-extrabold leading-tight bg-jaune text-accent">Installer l’app</button>
            <a href="{{ route('mon-compte') }}"
               class="w-24 lg:w-48 h-12 rounded-2xl flex items-center justify-center lg:justify-start text-center lg:text-left px-2 lg:px-4 text-[13px] lg:text-[15px] font-bold leading-tight no-underline {{ request()->routeIs('mon-compte') ? 'bg-jaune text-accent' : 'text-rail hover:text-white hover:bg-nuit-clair' }}">Mon compte</a>
            <form method="POST" action="{{ route('deconnexion') }}">
                @csrf
                <button type="submit" class="w-24 lg:w-48 h-12 rounded-2xl flex items-center justify-center lg:justify-start lg:px-4 text-[13px] lg:text-[15px] font-bold text-rail hover:text-white hover:bg-nuit-clair">
                    Quitter
                </button>
            </form>
        </nav>

        <main class="flex-grow min-w-0 p-4 md:p-8">
            {{-- Boutique de travail : un compte peut en gérer plusieurs. --}}
            <div class="flex items-center justify-end gap-3 mb-4">
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
                    <a href="{{ route('mon-compte') }}" title="Mon compte" class="hidden md:inline-flex items-center gap-2 rounded-xl bg-white border border-border px-3 h-10 text-sm font-bold text-accent no-underline hover:border-accent">{{ $mesBoutiques->first()?->nom }} <span class="font-semibold text-muted">· {{ auth()->user()->name }}</span></a>
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
                    <p class="mt-1">La caisse fonctionne toujours pour les articles de votre catalogue, mais plus rien ne se modifie : articles, stocks, clients, équipe. Abonnez-vous depuis l’application Ngoni Caisse.</p>
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
                "Votre essai ou abonnement est terminé : la caisse fonctionne toujours, "
                + "mais aucune modification n'est possible. Abonnez-vous depuis l'application Ngoni Caisse."
            ));
        });
    </script>
</body>
</html>
