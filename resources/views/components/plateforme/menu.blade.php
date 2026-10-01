{{--
    Menu « ⋯ » des actions secondaires d'une ligne. Les entrées restent dans la
    page (masquées tant qu'il est fermé) ; un clic sur l'une d'elles le referme.
--}}
@props(['libelle' => 'Autres actions'])
<div x-data="{ ouvert: false }" class="relative shrink-0" x-on:keydown.escape.window="ouvert = false" x-on:click.outside="ouvert = false">
    <button type="button" x-on:click="ouvert = ! ouvert" x-bind:aria-expanded="ouvert" aria-haspopup="menu"
            title="{{ $libelle }}" aria-label="{{ $libelle }}"
            class="h-9 w-9 rounded-lg inline-flex items-center justify-center text-muted hover:bg-puce hover:text-accent focus:outline-none focus-visible:ring-2 focus-visible:ring-accent/30"
            x-bind:class="ouvert && 'bg-puce text-accent'">
        <x-plateforme.picto nom="points" class="w-5 h-5" />
    </button>
    <div x-cloak x-show="ouvert" role="menu" x-on:click="ouvert = false"
         x-transition:enter="motion-safe:transition ease-out duration-150" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
         x-transition:leave="motion-safe:transition ease-in duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
         class="absolute right-0 top-full mt-1.5 z-30 w-60 max-w-[calc(100vw-2rem)] origin-top-right rounded-xl bg-white p-1.5 shadow-carte-haute ring-1 ring-black/5 flex flex-col">
        {{ $slot }}
    </div>
</div>
