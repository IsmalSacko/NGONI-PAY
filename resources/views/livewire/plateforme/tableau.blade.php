<div class="flex flex-col gap-6">
    <h1 class="font-display font-extrabold text-2xl md:text-3xl">Tableau de bord</h1>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        @foreach ([
            ['Comptes propriétaires', $comptes, ''],
            ['Abonnements actifs', $actifs, 'text-accent'],
            ['Expirés (lecture seule)', $expires, 'text-danger-fg'],
            ['Demandes à traiter', $demandes, $demandes > 0 ? 'text-danger-fg' : ''],
            ['Boutiques', $boutiques, ''],
            ['Utilisateurs', $utilisateurs, ''],
            ['Ventes du jour', $ventesJour, ''],
            ['Encaissé aujourd’hui (F CFA)', number_format($montantJour, 0, ',', ' '), ''],
        ] as [$libelle, $valeur, $couleur])
            <div class="bg-white border border-border rounded-2xl p-5">
                <p class="text-sm text-muted">{{ $libelle }}</p>
                <p class="mt-1 font-display font-extrabold text-2xl md:text-3xl {{ $couleur }}">{{ $valeur }}</p>
            </div>
        @endforeach
    </div>

    <div class="bg-white border border-border rounded-2xl p-5">
        <p class="text-sm text-muted mb-2">Répartition par plan</p>
        <div class="flex gap-6 text-sm">
            @foreach (['essai' => 'Essai', 'basic' => 'Basic', 'pro' => 'Pro'] as $code => $nom)
                <span><span class="font-bold">{{ $parPlan[$code] ?? 0 }}</span> {{ $nom }}</span>
            @endforeach
        </div>
    </div>

    @if ($demandes > 0)
        <a href="{{ route('plateforme.demandes') }}" class="self-start h-12 px-5 rounded-xl bg-accent text-white font-bold flex items-center">
            Traiter les {{ $demandes }} demande(s) en attente
        </a>
    @endif
</div>
