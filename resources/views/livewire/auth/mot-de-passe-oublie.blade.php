<div class="bg-white border border-[--color-border] rounded-2xl p-6 md:p-8 shadow-sm">
    <h1 class="font-display font-extrabold text-2xl mb-1">Mot de passe oublié</h1>

    @if ($etape === 'demande')
        <p class="text-[--color-muted] text-sm mb-6">Si votre compte a une adresse e-mail, vous y recevrez un code pour choisir un nouveau mot de passe.</p>
        <form wire:submit="demander" class="flex flex-col gap-4">
            <div>
                <label class="block text-sm font-semibold mb-1">Pays</label>
                <x-choix-pays :liste-pays="$listePays" />
            </div>
            <div>
                <label for="telephone" class="block text-sm font-semibold mb-1">Téléphone</label>
                <input wire:model="telephone" id="telephone" type="tel" autofocus class="w-full h-12 px-4 rounded-xl border border-[--color-border-strong] focus:outline-none focus:ring-2 focus:ring-accent">
                @error('telephone') <p class="text-sm text-[--color-danger-fg] mt-1">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="h-12 rounded-xl bg-accent text-white font-bold hover:bg-accent-dark">Recevoir un code</button>
        </form>
    @elseif ($etape === 'code')
        <p class="text-[--color-muted] text-sm mb-6">Si ce numéro correspond à un compte avec une adresse e-mail, un code à 6 chiffres vient d’y être envoyé. Pensez aux courriers indésirables.</p>
        <form wire:submit="reinitialiser" class="flex flex-col gap-4">
            <div>
                <label for="code" class="block text-sm font-semibold mb-1">Code reçu</label>
                <input wire:model="code" id="code" type="text" inputmode="numeric" maxlength="6" autocomplete="one-time-code" class="w-full h-12 px-4 rounded-xl border border-[--color-border-strong] tracking-[0.4em] text-lg">
                @error('code') <p class="text-sm text-[--color-danger-fg] mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="password" class="block text-sm font-semibold mb-1">Nouveau mot de passe (8 caractères minimum)</label>
                <input wire:model="password" id="password" type="password" class="w-full h-12 px-4 rounded-xl border border-[--color-border-strong]">
                @error('password') <p class="text-sm text-[--color-danger-fg] mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="password_confirmation" class="block text-sm font-semibold mb-1">Confirmer</label>
                <input wire:model="password_confirmation" id="password_confirmation" type="password" class="w-full h-12 px-4 rounded-xl border border-[--color-border-strong]">
            </div>
            <button type="submit" class="h-12 rounded-xl bg-accent text-white font-bold hover:bg-accent-dark">Changer le mot de passe</button>
            <button type="button" wire:click="$set('etape', 'demande')" class="text-sm text-accent font-semibold">Renvoyer un code</button>
        </form>
    @else
        <p class="rounded-xl bg-accent-soft text-accent-dark px-4 py-3 text-sm font-semibold mb-4">Mot de passe changé. Connectez-vous avec le nouveau, dans l’application comme ici.</p>
        <a href="{{ route('connexion') }}" class="h-12 rounded-xl bg-accent text-white font-bold flex items-center justify-center">Se connecter</a>
    @endif

    @if ($etape !== 'termine' && $lienWhatsApp)
        <div class="mt-6 pt-5 border-t border-[--color-border] text-sm">
            <p class="text-[--color-muted] mb-2">Pas d’adresse e-mail, ou pas de code reçu ? Écrivez-nous : nous vous donnons un mot de passe provisoire.</p>
            <a href="{{ $lienWhatsApp }}" target="_blank" rel="noopener" class="inline-flex items-center h-10 px-4 rounded-lg bg-whatsapp text-white font-bold">WhatsApp {{ $support }}</a>
        </div>
    @endif

    <p class="mt-5 text-center text-sm"><a href="{{ route('connexion') }}" class="text-accent font-semibold">Retour à la connexion</a></p>
</div>
