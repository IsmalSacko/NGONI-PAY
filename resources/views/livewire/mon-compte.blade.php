@php
    $champ = 'w-full h-12 px-3 rounded-xl border border-border-strong bg-white focus:outline-none focus:ring-2 focus:ring-accent';
    $erreur = fn (string $cle) => $errors->first($cle);
@endphp

<div class="max-w-3xl flex flex-col gap-6">
    <div>
        <h1 class="font-display font-extrabold text-2xl md:text-3xl text-accent">Mon compte</h1>
        <p class="text-sm text-muted">Vos informations de connexion.@unless (auth()->user()->est_admin_plateforme) Votre rôle, lui, se règle par le titulaire de la boutique.@endunless</p>
    </div>

    {{-- Même habit que l'écran Mon compte de l'application. --}}
    @php($initiales = mb_strtoupper(collect(preg_split('/\s+/', trim(auth()->user()->name)))->take(2)->map(fn ($m) => mb_substr($m, 0, 1))->join('')))
    <section class="rounded-[20px] p-5 text-white bg-linear-to-br from-nuit-clair to-accent shadow-[0_10px_22px_rgba(15,42,92,.30)] flex items-center gap-4">
        <span class="w-14 h-14 shrink-0 rounded-full bg-jaune text-accent font-display font-extrabold text-xl flex items-center justify-center">{{ $initiales }}</span>
        <span class="flex-1 min-w-0 flex flex-col">
            <span class="font-display font-extrabold text-xl truncate">{{ auth()->user()->name }}</span>
            <span class="text-white/80 text-sm truncate">{{ auth()->user()->phone }}@if (auth()->user()->email) · {{ auth()->user()->email }}@endif</span>
            <span class="text-jaune text-sm font-bold">{{ ['admin' => 'Admin', 'gerant' => 'Gérant', 'caissier' => 'Caissier'][auth()->user()->getRoleNames()->first()] ?? (auth()->user()->est_admin_plateforme ? 'Exploitant' : 'Membre') }}</span>
        </span>
    </section>

    <form wire:submit="enregistrerProfil" class="bg-white rounded-[20px] shadow-carte p-5 md:p-6 flex flex-col gap-4">
        <x-charte.en-tete-section icone="person" titre="Profil" sous-titre="Votre nom, votre téléphone de connexion." />
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <label class="flex flex-col gap-1.5 text-sm font-semibold sm:col-span-2">Nom
                <input wire:model="name" type="text" autocomplete="name" class="{{ $champ }}">
                @if ($erreur('name'))<span class="text-danger-fg text-xs">{{ $erreur('name') }}</span>@endif
            </label>
            <label class="flex flex-col gap-1.5 text-sm font-semibold">Téléphone <span class="font-normal text-muted text-xs -mt-1">Il sert à vous connecter</span>
                <input wire:model="telephone" type="tel" autocomplete="tel" class="{{ $champ }}">
                @if ($erreur('telephone'))<span class="text-danger-fg text-xs">{{ $erreur('telephone') }}</span>@endif
            </label>
            <label class="flex flex-col gap-1.5 text-sm font-semibold">E-mail <span class="font-normal text-muted text-xs -mt-1">Facultatif</span>
                <input wire:model="email" type="email" autocomplete="email" class="{{ $champ }}">
                @if ($erreur('email'))<span class="text-danger-fg text-xs">{{ $erreur('email') }}</span>@endif
            </label>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <button type="submit" class="h-12 px-5 rounded-xl bg-accent text-white font-bold" wire:loading.attr="disabled" wire:target="enregistrerProfil">Enregistrer</button>
            @if ($statutProfil)<span class="text-sm font-bold text-succes-fonce" role="status">{{ $statutProfil }}</span>@endif
        </div>
    </form>

    @if ($titulaire)
        <section class="bg-white rounded-[20px] shadow-carte p-5 md:p-6">
            <label class="flex items-start justify-between gap-4 cursor-pointer">
                <x-charte.en-tete-section icone="notifications_active" titre="Bilan du soir" sous-titre="Chaque soir à 20 h, une notification avec les ventes du jour de vos boutiques." />
                <input type="checkbox" wire:model.live="bilanQuotidien" class="mt-2 w-6 h-6 shrink-0 accent-[#0f2a5c]" aria-label="Recevoir le bilan du soir">
            </label>
        </section>
    @endif

    <form wire:submit="changerMotDePasse" class="bg-white rounded-[20px] shadow-carte p-5 md:p-6 flex flex-col gap-4">
        <x-charte.en-tete-section icone="lock" titre="Mot de passe" sous-titre="Au moins 8 caractères. Vos téléphones et tablettes connectés devront se reconnecter." />
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4" x-data="{ voir: false }">
            <label class="flex flex-col gap-1.5 text-sm font-semibold sm:col-span-2">Mot de passe actuel
                <input wire:model="mot_de_passe_actuel" :type="voir ? 'text' : 'password'" autocomplete="current-password" class="{{ $champ }}">
                @if ($erreur('mot_de_passe_actuel'))<span class="text-danger-fg text-xs">{{ $erreur('mot_de_passe_actuel') }}</span>@endif
            </label>
            <label class="flex flex-col gap-1.5 text-sm font-semibold">Nouveau mot de passe
                <input wire:model="mot_de_passe" :type="voir ? 'text' : 'password'" autocomplete="new-password" class="{{ $champ }}">
                @if ($erreur('mot_de_passe'))<span class="text-danger-fg text-xs">{{ $erreur('mot_de_passe') }}</span>@endif
            </label>
            <label class="flex flex-col gap-1.5 text-sm font-semibold">Confirmer
                <input wire:model="mot_de_passe_confirmation" :type="voir ? 'text' : 'password'" autocomplete="new-password" class="{{ $champ }}">
            </label>
            <label class="sm:col-span-2 inline-flex items-center gap-2 text-sm text-muted">
                <input type="checkbox" x-model="voir" class="w-4 h-4"> Afficher les mots de passe
            </label>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <button type="submit" class="h-12 px-5 rounded-xl bg-accent text-white font-bold" wire:loading.attr="disabled" wire:target="changerMotDePasse">Changer le mot de passe</button>
            @if ($statutMotDePasse)<span class="text-sm font-bold text-succes-fonce" role="status">{{ $statutMotDePasse }}</span>@endif
        </div>
    </form>
</div>
