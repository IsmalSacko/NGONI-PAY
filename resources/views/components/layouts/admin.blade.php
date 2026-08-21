<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Admin' }} — NGONI PAY Admin</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-slate-50 text-slate-800 antialiased" x-data="{ sidebarOpen: false }">
    <div class="flex min-h-screen">
        <!-- Overlay mobile -->
        <div x-show="sidebarOpen" x-cloak @click="sidebarOpen = false"
             x-transition.opacity
             class="fixed inset-0 z-30 bg-slate-950/50 lg:hidden"></div>

        <aside
            x-cloak
            :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
            class="fixed inset-y-0 left-0 z-40 w-64 shrink-0 bg-slate-950 text-slate-300 flex flex-col
                   transform transition-transform duration-200 ease-out
                   lg:static lg:translate-x-0">
            <div class="px-5 py-5 flex items-center gap-2.5 border-b border-white/5">
                <div class="h-8 w-8 rounded-lg bg-gradient-to-br from-violet-500 to-indigo-500 flex items-center justify-center text-white font-bold text-sm shrink-0">
                    N
                </div>
                <div class="leading-tight">
                    <p class="text-white font-semibold text-sm">NGONI PAY</p>
                    <p class="text-slate-500 text-xs">Administration</p>
                </div>
                <button @click="sidebarOpen = false" class="ml-auto text-slate-500 hover:text-white lg:hidden" aria-label="Fermer le menu">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 6 6 18M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <nav class="flex-1 px-3 py-5 space-y-0.5 overflow-y-auto">
                @php
                    $items = [
                        ['pattern' => 'admin.dashboard', 'route' => 'admin.dashboard', 'label' => 'Tableau de bord', 'icon' => 'M3 13h8V3H3v10Zm0 8h8v-6H3v6Zm10 0h8V11h-8v10Zm0-18v6h8V3h-8Z'],
                        ['pattern' => 'admin.users.*', 'route' => 'admin.users.index', 'label' => 'Utilisateurs', 'icon' => 'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0ZM12 14c-5 0-8 2.5-8 5v1h16v-1c0-2.5-3-5-8-5Z'],
                        ['pattern' => 'admin.businesses.*', 'route' => 'admin.businesses.index', 'label' => 'Entreprises', 'icon' => 'M4 21V7l8-4 8 4v14M9 21v-6h6v6M4 21h16'],
                        ['pattern' => 'admin.subscriptions.*', 'route' => 'admin.subscriptions.index', 'label' => 'Abonnements', 'icon' => 'M5 5h14v14H5V5Zm3 4h8M8 12h8M8 15h5'],
                        ['pattern' => 'admin.plans.*', 'route' => 'admin.plans.index', 'label' => 'Plans et tarifs', 'icon' => 'M12 8c-2.2 0-4 .9-4 2s1.8 2 4 2 4 .9 4 2-1.8 2-4 2m0-10v12m-9-6a9 9 0 1 0 18 0 9 9 0 0 0-18 0Z'],
                        ['pattern' => 'admin.subscription-requests.*', 'route' => 'admin.subscription-requests.index', 'label' => 'Demandes', 'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2Z'],
                        ['pattern' => 'admin.payments.*', 'route' => 'admin.payments.index', 'label' => 'Paiements', 'icon' => 'M2 8h20M2 8v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8M2 8l2-4h16l2 4M6 15h4'],
                        ['pattern' => 'admin.campaigns.*', 'route' => 'admin.campaigns.index', 'label' => 'Campagnes', 'icon' => 'M3 8l9 6 9-6M4 5h16a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Z'],
                    ];
                @endphp

                @foreach ($items as $item)
                    @php($active = request()->routeIs($item['pattern']))
                    <a href="{{ route($item['route']) }}"
                       class="group flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm transition
                              {{ $active ? 'bg-white/10 text-white font-medium' : 'text-slate-400 hover:bg-white/5 hover:text-white' }}">
                        <svg class="h-4.5 w-4.5 shrink-0 {{ $active ? 'text-indigo-400' : 'text-slate-500 group-hover:text-slate-300' }}"
                             viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="{{ $item['icon'] }}" />
                        </svg>
                        <span class="flex-1">{{ $item['label'] }}</span>
                        @if ($item['pattern'] === 'admin.subscription-requests.*')
                            @php($enAttente = \App\Models\SubscriptionRequest::pending()->count())
                            @if ($enAttente > 0)
                                <span class="rounded-full bg-red-600 px-1.5 py-0.5 text-[11px] font-bold text-white">
                                    {{ $enAttente > 99 ? '99+' : $enAttente }}
                                </span>
                            @endif
                        @endif
                    </a>
                @endforeach
            </nav>

            <div class="px-3 py-4 border-t border-white/5">
                <div class="flex items-center gap-2.5 px-2 pb-3">
                    <div class="h-8 w-8 rounded-full bg-indigo-500/20 text-indigo-300 flex items-center justify-center text-xs font-semibold shrink-0">
                        {{ strtoupper(substr(auth()->user()?->name ?? '?', 0, 1)) }}
                    </div>
                    <div class="min-w-0">
                        <p class="text-white text-sm truncate">{{ auth()->user()?->name }}</p>
                        <p class="text-slate-500 text-xs">Super-admin</p>
                    </div>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit"
                            class="w-full flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm text-slate-400 hover:bg-white/5 hover:text-white transition">
                        <svg class="h-4.5 w-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9" />
                        </svg>
                        Se déconnecter
                    </button>
                </form>
            </div>
        </aside>

        <main class="flex-1 min-w-0">
            <header class="sticky top-0 z-20 bg-white border-b border-slate-200 px-4 sm:px-8 py-4 sm:py-5 flex items-center gap-3">
                <button @click="sidebarOpen = true" class="text-slate-500 hover:text-slate-900 lg:hidden -ml-1 p-1" aria-label="Ouvrir le menu">
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                </button>
                <h1 class="text-lg sm:text-xl font-semibold text-slate-900 truncate">{{ $title ?? 'Admin' }}</h1>

                {{-- Demandes à instruire. Rien ne les signalait : il fallait
                     ouvrir la page pour savoir qu'un commerçant attendait. --}}
                @php($aInstruire = \App\Models\SubscriptionRequest::pending()->count())
                <a href="{{ route('admin.subscription-requests.index') }}"
                   class="relative ml-auto shrink-0 rounded-lg p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-900"
                   title="{{ $aInstruire === 0 ? 'Aucune demande en attente' : $aInstruire . ' demande(s) en attente' }}">
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0" />
                    </svg>
                    @if ($aInstruire > 0)
                        <span class="absolute -right-0.5 -top-0.5 flex h-5 min-w-5 items-center justify-center rounded-full bg-red-600 px-1 text-[11px] font-bold text-white ring-2 ring-white">
                            {{ $aInstruire > 99 ? '99+' : $aInstruire }}
                        </span>
                    @endif
                </a>
            </header>
            <div class="p-4 sm:p-8">
                {{ $slot }}
            </div>
        </main>
    </div>

    @livewireScripts
</body>
</html>
