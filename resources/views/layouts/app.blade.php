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
            @php
                $boutiqueActiveId = app(\App\Support\Tenancy\TenantContext::class)->boutiqueId();
                $mesBoutiques = \App\Models\Boutique::whereIn('id', auth()->user()->boutiqueIds())->orderBy('nom')->get(['id', 'nom']);
            @endphp
            {{-- Boutique de travail : un compte peut en gérer plusieurs. --}}
            <div class="flex justify-end mb-4">
                @if ($mesBoutiques->count() > 1)
                    <form method="POST" action="{{ route('boutique-active') }}" class="flex items-center gap-2 text-sm">
                        @csrf
                        <label for="boutique-active" class="text-[--color-muted] font-semibold">Boutique</label>
                        <select id="boutique-active" name="boutique" onchange="this.form.submit()"
                                class="h-10 px-3 rounded-xl border border-[--color-border-strong] bg-white font-semibold">
                            @foreach ($mesBoutiques as $b)
                                <option value="{{ $b->id }}" @selected($b->id === $boutiqueActiveId)>{{ $b->nom }}</option>
                            @endforeach
                        </select>
                    </form>
                @else
                    <span class="text-sm font-semibold text-[--color-muted]">{{ $mesBoutiques->first()?->nom }}</span>
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
                    <p class="mt-1">Vos données restent consultables, mais aucune modification n’est possible. Abonnez-vous depuis l’application e-caisse.</p>
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
                + "mais aucune modification n'est possible. Abonnez-vous depuis l'application e-caisse."
            ));
        });
    </script>
</body>
</html>
