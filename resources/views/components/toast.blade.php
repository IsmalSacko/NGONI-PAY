{{--
    Notification passagère de la console : un composant Livewire l'appelle par
    $this->dispatch('toast', type: 'succes'|'erreur', message: '…').
    Elle glisse d'en haut, la coche (ou la croix) se dessine, la barre du bas
    se vide pendant cinq secondes, puis elle repart. Un clic la ferme.
--}}
<div x-data="{ toasts: [], n: 0,
        ajouter(d) { const id = ++this.n; this.toasts.push({ id, type: d.type ?? 'succes', message: d.message, visible: true });
                     setTimeout(() => this.fermer(id), 5000) },
        fermer(id) { const t = this.toasts.find(t => t.id === id); if (t) t.visible = false;
                     setTimeout(() => this.toasts = this.toasts.filter(t => t.id !== id), 300) } }"
     x-on:toast.window="ajouter($event.detail)"
     class="fixed top-4 inset-x-4 sm:inset-x-auto sm:right-6 z-[60] flex flex-col gap-3 sm:w-96 pointer-events-none"
     aria-live="polite">
    <template x-for="t in toasts" :key="t.id">
        <div x-show="t.visible" x-transition:enter="toast-entree" x-transition:leave="toast-sortie"
             x-on:click="fermer(t.id)" role="status"
             class="toast pointer-events-auto cursor-pointer overflow-hidden rounded-2xl bg-white shadow-xl ring-1 ring-black/5"
             :class="t.type === 'erreur' ? 'toast-erreur' : 'toast-succes'">
            <div class="flex items-start gap-3 p-4">
                <span class="toast-pastille shrink-0 w-9 h-9 rounded-full inline-flex items-center justify-center">
                    <svg viewBox="0 0 24 24" class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                        <path x-show="t.type !== 'erreur'" class="toast-trait" d="M5 12.5l4.5 4.5L19 7.5" />
                        <path x-show="t.type === 'erreur'" class="toast-trait" d="M7 7l10 10M17 7L7 17" />
                    </svg>
                </span>
                <div class="min-w-0 pt-1">
                    <p class="font-extrabold text-sm" x-text="t.type === 'erreur' ? 'Échec' : 'C’est fait'"></p>
                    <p class="text-sm text-muted mt-0.5" x-text="t.message"></p>
                </div>
            </div>
            <div class="toast-barre h-1"></div>
        </div>
    </template>
</div>

<style>
    .toast-succes { --toast: var(--color-succes); --toast-doux: var(--color-succes-doux); }
    .toast-erreur { --toast: var(--color-danger-fg); --toast-doux: var(--color-danger-bg); }
    .toast-pastille { background: var(--toast-doux); color: var(--toast); animation: toast-pop .45s cubic-bezier(.34, 1.56, .64, 1) both; }
    .toast-trait { stroke-dasharray: 30; stroke-dashoffset: 30; animation: toast-dessin .4s .25s ease-out forwards; }
    .toast-barre { background: var(--toast); transform-origin: left; animation: toast-temps 5s linear forwards; }
    .toast-entree { animation: toast-glisse .35s cubic-bezier(.21, 1.02, .73, 1) both; }
    .toast-sortie { animation: toast-glisse .25s ease-in reverse both; }
    @keyframes toast-glisse { from { opacity: 0; transform: translateY(-16px) scale(.96); } to { opacity: 1; transform: none; } }
    @keyframes toast-pop { from { transform: scale(0); } to { transform: scale(1); } }
    @keyframes toast-dessin { to { stroke-dashoffset: 0; } }
    @keyframes toast-temps { from { transform: scaleX(1); } to { transform: scaleX(0); } }
    @media (prefers-reduced-motion: reduce) {
        .toast-pastille, .toast-trait, .toast-entree, .toast-sortie { animation: none; stroke-dashoffset: 0; }
    }
</style>
