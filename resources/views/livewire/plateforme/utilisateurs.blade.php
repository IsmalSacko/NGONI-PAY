@php
    $champ = 'h-11 px-3.5 rounded-xl border border-border-strong bg-white text-sm focus:outline-none focus:border-accent/40 focus:ring-2 focus:ring-accent/30';
@endphp
<div class="flex flex-col gap-5">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-display font-extrabold text-2xl md:text-3xl">Utilisateurs</h1>
            <p class="text-sm text-muted">Tous les comptes de la plateforme.</p>
        </div>
        <div class="flex flex-wrap md:flex-nowrap gap-3 w-full md:w-auto">
            <select wire:model.live="tri" class="{{ $champ }} pr-8">
                <option value="recents">Derniers inscrits</option>
                <option value="nom">Par nom</option>
            </select>
            <label class="relative flex-1 md:flex-none">
                <span class="sr-only">Rechercher</span>
                <x-plateforme.picto nom="recherche" class="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-muted pointer-events-none" />
                <input wire:model.live.debounce.300ms="recherche" type="text" placeholder="Nom, téléphone, e-mail…"
                       class="{{ $champ }} w-full md:w-80 pl-10">
            </label>
        </div>
    </div>

    @if ($alerte)<p class="rounded-xl bg-danger-bg text-danger-fg px-4 py-3 text-sm font-semibold">{{ $alerte }}</p>@endif

    @if ($info)<p class="rounded-xl bg-succes-doux text-succes px-4 py-3 text-sm font-semibold">{{ $info }}</p>@endif

    {{-- Suppression définitive : l'aperçu d'abord, puis SUPPRIMER à taper. --}}
    @if ($aSupprimer)
        <div class="fixed inset-0 z-50 bg-accent-dark/40 backdrop-blur-[2px] flex items-end md:items-center justify-center md:p-4" wire:click.self="annulerSuppression">
            <div class="bg-white rounded-t-3xl md:rounded-2xl shadow-carte-haute p-6 w-full md:max-w-lg max-h-[90vh] overflow-y-auto flex flex-col gap-3">
                <div class="flex items-center gap-3">
                    <x-plateforme.icone-chip nom="corbeille" ton="danger" />
                    <h2 class="font-display font-extrabold text-xl text-danger-fg">Supprimer définitivement ?</h2>
                </div>
                <p class="text-sm">Le compte, ses boutiques et tout leur contenu seront effacés. Une sauvegarde est gardée sur le serveur, mais le commerçant perd tout accès.</p>
                <ul class="text-sm rounded-xl bg-danger-bg/70 px-4 py-3 flex flex-col gap-1">
                    <li><strong>Boutiques :</strong> {{ implode(', ', $apercu['boutiques'] ?? []) ?: 'aucune' }}</li>
                    <li><strong>{{ $apercu['articles'] ?? 0 }}</strong> articles, <strong>{{ $apercu['ventes'] ?? 0 }}</strong> ventes, <strong>{{ $apercu['clients'] ?? 0 }}</strong> clients</li>
                    <li><strong>Comptes supprimés :</strong> {{ implode(', ', $apercu['comptes_supprimes'] ?? []) }}</li>
                    @if (! empty($apercu['comptes_conserves']))
                        <li><strong>Conservés</strong> (ils travaillent aussi ailleurs) : {{ implode(', ', $apercu['comptes_conserves']) }}</li>
                    @endif
                </ul>
                @if ($apercu['blocage'] ?? null)
                    <p class="rounded-xl bg-warn-bg text-warn-fg px-4 py-3 text-sm font-semibold">{{ $apercu['blocage'] }}</p>
                    <button wire:click="annulerSuppression" class="h-11 rounded-xl ring-1 ring-border-strong font-bold hover:bg-puce">Fermer</button>
                @else
                    <label class="text-sm font-semibold">Tapez <span class="font-mono">SUPPRIMER</span> pour confirmer
                        <input wire:model="confirmation" type="text" autocomplete="off" class="{{ $champ }} mt-1 w-full font-mono">
                    </label>
                    @error('confirmation') <p class="text-sm text-danger-fg">{{ $message }}</p> @enderror
                    <div class="flex gap-3">
                        <button wire:click="annulerSuppression" class="flex-1 h-11 rounded-xl ring-1 ring-border-strong font-bold hover:bg-puce">Annuler</button>
                        <button wire:click="supprimer" wire:loading.attr="disabled" class="flex-1 h-11 rounded-xl bg-danger-fg text-white font-bold shadow-sm disabled:opacity-60">Supprimer</button>
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- Le moment fort de l'écran, quand il existe : le mot de passe à transmettre. --}}
    @if ($motDePasseProvisoire)
        <div class="rounded-2xl bg-jaune-doux shadow-carte ring-1 ring-jaune/60 px-5 py-4 text-sm flex gap-4 items-start">
            <x-plateforme.icone-chip nom="cle" ton="jaune-fort" />
            <div class="min-w-0">
                <p class="font-bold">Mot de passe provisoire pour {{ $pour }}</p>
                <p class="mt-1 inline-block rounded-lg bg-white px-3 py-1 font-mono text-xl tracking-wider select-all break-all">{{ $motDePasseProvisoire }}</p>
                <p class="mt-1.5 text-xs text-warn-fg">Affiché une seule fois. Ses sessions ont été fermées.</p>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    @if ($lienWhatsApp)
                        <a href="{{ $lienWhatsApp }}" target="_blank" rel="noopener" class="h-10 px-4 rounded-xl bg-accent text-white font-bold inline-flex items-center gap-2">
                            <x-icone nom="whatsapp" class="w-4 h-4" />Envoyer sur WhatsApp
                        </a>
                    @endif
                    <button wire:click="fermerMotDePasse" class="h-10 px-4 rounded-xl font-bold text-accent hover:bg-white/70">Fermer</button>
                </div>
            </div>
        </div>
    @endif

    <section class="bg-white rounded-2xl shadow-carte divide-y divide-separateur">
        @foreach ($users as $user)
            @php
                $activite = collect([$user->derniere_app ? \Illuminate\Support\Carbon::parse($user->derniere_app) : null,
                    $user->derniere_web ? \Illuminate\Support\Carbon::createFromTimestamp((int) $user->derniere_web) : null])->filter()->max();
            @endphp
            {{-- Téléphone : avatar, nom et menu en haut, coordonnées dessous. Grand écran : une ligne en colonnes. --}}
            <div class="px-4 md:px-5 py-3.5 flex items-start gap-3 lg:grid lg:grid-cols-[minmax(0,2.4fr)_minmax(0,1.3fr)_minmax(0,1fr)_7rem_2.25rem] lg:items-center lg:gap-4 text-sm hover:bg-fond-tableau/60 first:rounded-t-2xl last:rounded-b-2xl"
                 wire:key="u-{{ $user->id }}">
                <div class="flex items-start gap-3 min-w-0 flex-1">
                    <x-plateforme.avatar :nom="$user->name" />
                    <div class="min-w-0 flex flex-col">
                        <span class="font-bold flex flex-wrap items-center gap-x-2">
                            {{ $user->name }}
                            @if ($user->est_admin_plateforme)<span class="rounded-full bg-accent text-white px-2 py-px text-[11px] font-bold">exploitant</span>@endif
                        </span>
                        <span class="text-xs text-muted">
                            Inscrit le {{ $user->created_at?->format('d/m/Y à H:i') }}
                            · <span class="{{ $activite ? 'text-ink' : '' }}">{{ $activite ? 'actif '.$activite->locale('fr')->diffForHumans() : 'jamais connecté' }}</span>
                            · {{ $user->nb_ventes }} vente{{ $user->nb_ventes > 1 ? 's' : '' }}
                        </span>
                        <span class="mt-1 flex flex-wrap gap-1.5 text-[11px] font-bold">
                            <span class="rounded-md px-1.5 py-px {{ $user->nb_appareils > 0 ? 'bg-succes-doux text-succes' : 'bg-puce text-muted' }}">push {{ $user->nb_appareils > 0 ? '✓' : '✗' }}</span>
                            <span class="rounded-md px-1.5 py-px {{ $user->email ? 'bg-succes-doux text-succes' : 'bg-puce text-muted' }}">e-mail {{ $user->email ? '✓' : '✗' }}</span>
                        </span>
                        {{-- Téléphone : coordonnées et statut sous le nom. --}}
                        <div class="lg:hidden mt-2 flex flex-wrap items-center gap-x-3 gap-y-1.5">
                            <x-telephone :numero="$user->phone" />
                            @if ($user->boutique)
                                <span class="inline-flex items-center gap-1 text-muted min-w-0"><x-plateforme.picto nom="boutique" class="w-3.5 h-3.5 shrink-0" /><span class="truncate">{{ $user->boutique->nom }}</span></span>
                            @endif
                            @if ($user->is_active)<x-plateforme.pastille ton="succes">Actif</x-plateforme.pastille>@else<x-plateforme.pastille ton="danger">Désactivé</x-plateforme.pastille>@endif
                        </div>
                    </div>
                </div>
                <span class="hidden lg:block min-w-0"><x-telephone :numero="$user->phone" /></span>
                <span class="hidden lg:flex items-center gap-1.5 min-w-0 text-ink">
                    @if ($user->boutique)
                        <x-plateforme.picto nom="boutique" class="w-4 h-4 shrink-0 text-muted" /><span class="truncate" title="{{ $user->boutique->nom }}">{{ $user->boutique->nom }}</span>
                    @else
                        <span class="text-muted">—</span>
                    @endif
                </span>
                <span class="hidden lg:block">
                    @if ($user->is_active)<x-plateforme.pastille ton="succes">Actif</x-plateforme.pastille>@else<x-plateforme.pastille ton="danger">Désactivé</x-plateforme.pastille>@endif
                </span>
                <x-plateforme.menu :libelle="'Actions pour '.$user->name">
                    <x-plateforme.menu-item icone="cle" wire:click="motDePasse('{{ $user->id }}')" wire:confirm="Générer un mot de passe provisoire pour {{ $user->name }} ? L’actuel ne fonctionnera plus.">Mot de passe</x-plateforme.menu-item>
                    <x-plateforme.menu-item icone="power" wire:click="basculer('{{ $user->id }}')">{{ $user->is_active ? 'Désactiver' : 'Réactiver' }}</x-plateforme.menu-item>
                    @unless ($user->est_admin_plateforme)
                        <span class="my-1 h-px bg-separateur" aria-hidden="true"></span>
                        <x-plateforme.menu-item icone="corbeille" :danger="true" wire:click="preparerSuppression('{{ $user->id }}')">Supprimer</x-plateforme.menu-item>
                    @endunless
                </x-plateforme.menu>
            </div>
        @endforeach
    </section>

    {{ $users->links() }}
</div>
