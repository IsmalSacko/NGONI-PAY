{{--
    Pagination Livewire à la façon de l'application : « ‹ Précédente »,
    « Page 2 sur 7 · 160 articles », « Suivante › ». Rien si tout tient sur
    une page.
--}}
@props(['pages', 'mot' => 'éléments'])
@if ($pages->hasPages())
    <nav class="flex flex-wrap items-center justify-between gap-3 px-4 py-3" aria-label="Pagination">
        <button type="button" wire:click="previousPage" @disabled($pages->onFirstPage())
                class="inline-flex items-center gap-1 h-10 px-4 rounded-xl bg-accent-soft text-accent font-bold disabled:opacity-40">
            <x-charte.icone nom="chevron_left" :taille="20" />Précédente
        </button>
        <span class="text-sm font-semibold text-muted">Page {{ $pages->currentPage() }} sur {{ $pages->lastPage() }} · {{ number_format($pages->total(), 0, ',', ' ') }} {{ $mot }}</span>
        <button type="button" wire:click="nextPage" @disabled(! $pages->hasMorePages())
                class="inline-flex items-center gap-1 h-10 px-4 rounded-xl bg-accent text-white font-bold disabled:opacity-40">
            Suivante<x-charte.icone nom="chevron_right" :taille="20" />
        </button>
    </nav>
@endif
