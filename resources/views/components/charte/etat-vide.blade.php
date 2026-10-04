{{-- Rien à montrer : la pastille sur son halo, une phrase, une aide. Comme EtatVide de l'application. --}}
@props(['icone', 'titre', 'texte' => null])
<div {{ $attributes->merge(['class' => 'flex flex-col items-center text-center gap-3 py-10 px-6']) }}>
    <span class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-accent-soft/70">
        <x-charte.pastille :icone="$icone" :taille="52" />
    </span>
    <p class="font-display font-extrabold text-accent">{{ $titre }}</p>
    @if ($texte)<p class="text-sm text-muted max-w-md">{{ $texte }}</p>@endif
    {{ $slot }}
</div>
