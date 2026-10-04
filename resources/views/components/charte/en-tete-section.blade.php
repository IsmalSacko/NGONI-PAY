{{-- Titre d'une section : la pastille, le titre, une ligne d'explication. Comme EnTeteSection de l'application. --}}
@props(['icone', 'titre', 'sousTitre' => null])
<div {{ $attributes->merge(['class' => 'flex items-start gap-3']) }}>
    <x-charte.pastille :icone="$icone" :taille="38" />
    <div class="flex flex-col min-w-0">
        <h2 class="font-display font-extrabold text-[17px] text-accent leading-tight">{{ $titre }}</h2>
        @if ($sousTitre)
            <span class="text-sm text-muted">{{ $sousTitre }}</span>
        @endif
    </div>
    @isset($action)
        <div class="ml-auto shrink-0">{{ $action }}</div>
    @endisset
</div>
