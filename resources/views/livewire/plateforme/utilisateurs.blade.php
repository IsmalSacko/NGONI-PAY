<div class="flex flex-col gap-5">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-display font-extrabold text-2xl md:text-3xl">Utilisateurs</h1>
            <p class="text-sm text-muted">Tous les comptes de la plateforme.</p>
        </div>
        <div class="flex flex-wrap md:flex-nowrap gap-3 w-full md:w-auto">
            <select wire:model.live="tri" class="h-11 px-3 rounded-xl border border-border-strong bg-white">
                <option value="recents">Derniers inscrits</option>
                <option value="nom">Par nom</option>
            </select>
            <input wire:model.live.debounce.300ms="recherche" type="text" placeholder="Nom, téléphone, e-mail…"
                   class="w-full md:w-80 h-11 px-4 rounded-xl border border-border-strong">
        </div>
    </div>

    @if ($alerte)<p class="rounded-xl bg-danger-bg text-danger-fg px-4 py-3 text-sm font-semibold">{{ $alerte }}</p>@endif

    @if ($info)<p class="rounded-xl bg-succes-doux text-succes px-4 py-3 text-sm font-semibold">{{ $info }}</p>@endif

    {{-- Suppression définitive : l'aperçu d'abord, puis SUPPRIMER à taper. --}}
    @if ($aSupprimer)
        <div class="fixed inset-0 z-50 bg-black/40 flex items-end md:items-center justify-center md:p-4" wire:click.self="annulerSuppression">
            <div class="bg-white rounded-t-2xl md:rounded-2xl p-6 w-full md:max-w-lg max-h-[90vh] overflow-y-auto flex flex-col gap-3">
                <h2 class="font-display font-extrabold text-xl text-danger-fg">Supprimer définitivement ?</h2>
                <p class="text-sm">Le compte, ses boutiques et tout leur contenu seront effacés. Une sauvegarde est gardée sur le serveur, mais le commerçant perd tout accès.</p>
                <ul class="text-sm rounded-xl bg-danger-bg px-4 py-3 flex flex-col gap-1">
                    <li><strong>Boutiques :</strong> {{ implode(', ', $apercu['boutiques'] ?? []) ?: 'aucune' }}</li>
                    <li><strong>{{ $apercu['articles'] ?? 0 }}</strong> articles, <strong>{{ $apercu['ventes'] ?? 0 }}</strong> ventes, <strong>{{ $apercu['clients'] ?? 0 }}</strong> clients</li>
                    <li><strong>Comptes supprimés :</strong> {{ implode(', ', $apercu['comptes_supprimes'] ?? []) }}</li>
                    @if (! empty($apercu['comptes_conserves']))
                        <li><strong>Conservés</strong> (ils travaillent aussi ailleurs) : {{ implode(', ', $apercu['comptes_conserves']) }}</li>
                    @endif
                </ul>
                @if ($apercu['blocage'] ?? null)
                    <p class="rounded-xl bg-warn-bg text-warn-fg px-4 py-3 text-sm font-semibold">{{ $apercu['blocage'] }}</p>
                    <button wire:click="annulerSuppression" class="h-11 rounded-lg border border-border-strong font-bold">Fermer</button>
                @else
                    <label class="text-sm font-semibold">Tapez <span class="font-mono">SUPPRIMER</span> pour confirmer
                        <input wire:model="confirmation" type="text" autocomplete="off" class="mt-1 w-full h-11 px-3 rounded-lg border border-border-strong font-mono">
                    </label>
                    @error('confirmation') <p class="text-sm text-danger-fg">{{ $message }}</p> @enderror
                    <div class="flex gap-3">
                        <button wire:click="annulerSuppression" class="flex-1 h-11 rounded-lg border border-border-strong font-bold">Annuler</button>
                        <button wire:click="supprimer" wire:loading.attr="disabled" class="flex-1 h-11 rounded-lg bg-danger-fg text-white font-bold disabled:opacity-60">Supprimer</button>
                    </div>
                @endif
            </div>
        </div>
    @endif

    @if ($motDePasseProvisoire)
        <div class="rounded-2xl border border-warn-fg/30 bg-warn-bg px-5 py-4 text-sm">
            <p class="font-bold">Mot de passe provisoire pour {{ $pour }}</p>
            <p class="mt-1 font-mono text-xl tracking-wider select-all">{{ $motDePasseProvisoire }}</p>
            <p class="mt-1 text-xs">Affiché une seule fois. Ses sessions ont été fermées.</p>
            <div class="mt-2 flex gap-4">
                @if ($lienWhatsApp)<a href="{{ $lienWhatsApp }}" target="_blank" rel="noopener" class="font-bold text-accent underline">Envoyer sur WhatsApp</a>@endif
                <button wire:click="fermerMotDePasse" class="underline">Fermer</button>
            </div>
        </div>
    @endif

    <section class="bg-white border border-border rounded-2xl overflow-x-auto">
        @foreach ($users as $user)
            <div class="grid min-w-[900px] grid-cols-[2.4fr_1.3fr_1fr_0.8fr_300px] gap-3 px-5 py-3 border-b border-separateur items-center text-sm" wire:key="u-{{ $user->id }}">
                @php
                    $activite = collect([$user->derniere_app ? \Illuminate\Support\Carbon::parse($user->derniere_app) : null,
                        $user->derniere_web ? \Illuminate\Support\Carbon::createFromTimestamp((int) $user->derniere_web) : null])->filter()->max();
                @endphp
                <span class="flex flex-col">
                    <span class="font-bold">{{ $user->name }}@if ($user->est_admin_plateforme) <span class="text-xs text-muted">(exploitant)</span>@endif</span>
                    <span class="text-xs text-muted">
                        Inscrit le {{ $user->created_at?->format('d/m/Y à H:i') }}
                        · {{ $activite ? 'actif '.$activite->locale('fr')->diffForHumans() : 'jamais connecté' }}
                        · {{ $user->nb_ventes }} vente{{ $user->nb_ventes > 1 ? 's' : '' }}
                        · push {{ $user->nb_appareils > 0 ? '✓' : '✗' }}
                        · e-mail {{ $user->email ? '✓' : '✗' }}
                    </span>
                </span>
                <x-telephone :numero="$user->phone" />
                <span class="truncate">{{ $user->boutique?->nom ?? '—' }}</span>
                <span>
                    @if ($user->is_active)
                        <span class="text-xs font-bold rounded px-2 py-1 bg-accent-soft text-accent-dark">Actif</span>
                    @else
                        <span class="text-xs font-bold rounded px-2 py-1 bg-puce text-muted">Désactivé</span>
                    @endif
                </span>
                <div class="flex gap-2 justify-end">
                    <button wire:click="motDePasse('{{ $user->id }}')" wire:confirm="Générer un mot de passe provisoire pour {{ $user->name }} ? L’actuel ne fonctionnera plus."
                            class="h-9 px-3 rounded-lg border border-border-strong text-xs font-bold">Mot de passe</button>
                    <button wire:click="basculer('{{ $user->id }}')" class="h-9 px-3 rounded-lg border border-border-strong text-xs font-bold">
                        {{ $user->is_active ? 'Désactiver' : 'Réactiver' }}
                    </button>
                    @unless ($user->est_admin_plateforme)
                        <button wire:click="preparerSuppression('{{ $user->id }}')" class="h-9 px-3 rounded-lg border border-danger-fg/40 text-xs font-bold text-danger-fg">Supprimer</button>
                    @endunless
                </div>
            </div>
        @endforeach
    </section>

    {{ $users->links() }}
</div>
