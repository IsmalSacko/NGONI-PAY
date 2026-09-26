<div class="bg-white border border-[--color-border] rounded-2xl p-8 shadow-sm">
    <h1 class="font-display font-extrabold text-2xl mb-1">Connexion</h1>
    <p class="text-[--color-muted] text-sm mb-6">Accédez au pilotage de votre boutique.</p>

    @if (session('alerte'))
        <p class="mb-4 rounded-xl bg-[--color-warn-bg] text-[--color-warn-fg] px-4 py-3 text-sm">{{ session('alerte') }}</p>
    @endif

    <form wire:submit="connexion" class="flex flex-col gap-4">
        <div>
            <label for="pays" class="block text-sm font-semibold mb-1">Pays</label>
            <x-choix-pays :liste-pays="$listePays" />
        </div>

        <div>
            <label for="telephone" class="block text-sm font-semibold mb-1">Téléphone</label>
            <input wire:model="telephone" id="telephone" type="text" autofocus
                   placeholder="+223 76 00 00 00"
                   class="w-full h-12 px-4 rounded-xl border border-[--color-border-strong] focus:outline-none focus:ring-2 focus:ring-accent">
            @error('telephone') <p class="text-sm text-[--color-danger-fg] mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="password" class="block text-sm font-semibold mb-1">Mot de passe</label>
            <input wire:model="password" id="password" type="password"
                   class="w-full h-12 px-4 rounded-xl border border-[--color-border-strong] focus:outline-none focus:ring-2 focus:ring-accent">
            @error('password') <p class="text-sm text-[--color-danger-fg] mt-1">{{ $message }}</p> @enderror
        </div>

        <button type="submit"
                class="h-12 rounded-xl bg-accent text-white font-bold hover:bg-accent-dark"
                wire:loading.attr="disabled" wire:target="connexion">
            Se connecter
        </button>
    </form>
</div>
