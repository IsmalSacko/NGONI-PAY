{{-- Une entrée du menu « ⋯ » : pictogramme + libellé ; « danger » pour ce qui efface. --}}
@props(['icone' => null, 'danger' => false])
<button type="button" role="menuitem" {{ $attributes->merge(['class' => 'w-full min-h-10 px-3 py-2 rounded-lg inline-flex items-center gap-2.5 text-sm font-semibold text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-accent/30 '.($danger ? 'text-danger-fg hover:bg-danger-bg' : 'text-ink hover:bg-puce')]) }}>
    @if ($icone)<x-plateforme.picto :nom="$icone" class="w-4 h-4 shrink-0 {{ $danger ? '' : 'text-muted' }}" />@endif
    <span>{{ $slot }}</span>
</button>
