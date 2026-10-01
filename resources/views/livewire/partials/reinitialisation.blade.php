{{-- Fenêtre « Réinitialiser la boutique » (trait ReinitialiseUneBoutique) : console et back-office. --}}
{{-- Remise à zéro d'une boutique d'essai : l'aperçu d'abord, puis REINITIALISER à taper. --}}
@if ($aReinitialiser)
    <div class="fixed inset-0 z-50 bg-black/40 flex items-end md:items-center justify-center md:p-4" wire:click.self="annulerReinitialisation">
        <div class="bg-white rounded-t-2xl md:rounded-2xl p-6 w-full md:max-w-lg max-h-[90vh] overflow-y-auto flex flex-col gap-3">
            <h2 class="font-display font-extrabold text-xl text-danger-fg">Réinitialiser {{ $apercu['nom'] ?? '' }} ?</h2>
            <p class="text-sm">Les données d’essai sont effacées pour repartir à zéro : ventes, caisse, achats, clients. Réglages, logo, programme fidélité, équipe et abonnement sont gardés. Une sauvegarde est gardée 30 jours sur le serveur.</p>
            <ul class="text-sm rounded-xl bg-danger-bg px-4 py-3 flex flex-col gap-1">
                <li><strong>{{ $apercu['ventes'] ?? 0 }}</strong> vente{{ ($apercu['ventes'] ?? 0) > 1 ? 's' : '' }}, <strong>{{ $apercu['sessions'] ?? 0 }}</strong> séance{{ ($apercu['sessions'] ?? 0) > 1 ? 's' : '' }} de caisse</li>
                <li><strong>{{ $apercu['achats'] ?? 0 }}</strong> achat{{ ($apercu['achats'] ?? 0) > 1 ? 's' : '' }}, <strong>{{ $apercu['clients'] ?? 0 }}</strong> client{{ ($apercu['clients'] ?? 0) > 1 ? 's' : '' }}</li>
                <li>
                    @if ($garderCatalogue)
                        <strong>{{ $apercu['articles'] ?? 0 }}</strong> article{{ ($apercu['articles'] ?? 0) > 1 ? 's' : '' }} gardé{{ ($apercu['articles'] ?? 0) > 1 ? 's' : '' }}, stock remis à 0
                    @else
                        <strong>{{ $apercu['articles'] ?? 0 }}</strong> article{{ ($apercu['articles'] ?? 0) > 1 ? 's' : '' }} et <strong>{{ $apercu['categories'] ?? 0 }}</strong> catégorie{{ ($apercu['categories'] ?? 0) > 1 ? 's' : '' }} effacés
                    @endif
                </li>
                <li><strong>{{ $apercu['fournisseurs'] ?? 0 }}</strong> fournisseur{{ ($apercu['fournisseurs'] ?? 0) > 1 ? 's' : '' }} {{ $garderFournisseurs ? 'gardé' : 'effacé' }}{{ ($apercu['fournisseurs'] ?? 0) > 1 ? 's' : '' }}</li>
            </ul>
            <label class="flex items-center gap-2 text-sm"><input wire:model.live="garderCatalogue" type="checkbox"> Garder les articles (le stock repart de 0)</label>
            <label class="flex items-center gap-2 text-sm"><input wire:model.live="garderFournisseurs" type="checkbox"> Garder les fournisseurs</label>
            @if ($apercu['avertissement'] ?? null)
                <p class="rounded-xl bg-warn-bg text-warn-fg px-4 py-3 text-sm font-semibold">{{ $apercu['avertissement'] }}</p>
            @endif
            <label class="text-sm font-semibold">Tapez <span class="font-mono">REINITIALISER</span> pour confirmer
                <input wire:model="confirmation" type="text" autocomplete="off" class="mt-1 w-full h-11 px-3 rounded-lg border border-border-strong font-mono">
            </label>
            @error('confirmation') <p class="text-sm text-danger-fg">{{ $message }}</p> @enderror
            <div class="flex gap-3">
                <button wire:click="annulerReinitialisation" class="flex-1 h-11 rounded-lg border border-border-strong font-bold">Annuler</button>
                <button wire:click="reinitialiser" wire:loading.attr="disabled" wire:target="reinitialiser" class="flex-1 h-11 rounded-lg bg-danger-fg text-white font-bold disabled:opacity-60 inline-flex items-center justify-center gap-2"><svg wire:loading wire:target="reinitialiser" class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".3" stroke-width="3"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg><span wire:loading.remove wire:target="reinitialiser">Réinitialiser</span><span wire:loading wire:target="reinitialiser">Effacement…</span></button>
            </div>
        </div>
    </div>
@endif
