@props(['numero'])

{{-- Numéro + bouton WhatsApp quand l'indicatif pays est connu. --}}
@php($wa = \App\Support\WhatsApp::link($numero))

@if (blank($numero))
    <span class="text-muted">—</span>
@else
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 whitespace-nowrap']) }}>
        <a href="tel:{{ preg_replace('/[^\d+]/', '', $numero) }}" class="hover:underline">{{ $numero }}</a>
        @if ($wa)
            <a href="{{ $wa }}" target="_blank" rel="noopener" title="Écrire sur WhatsApp"
               class="rounded-full bg-accent-soft px-2 py-0.5 text-xs font-bold text-accent-dark hover:bg-accent hover:text-white">WhatsApp</a>
        @else
            <span class="text-[11px] text-muted" title="Numéro sans indicatif pays">sans indicatif</span>
        @endif
    </span>
@endif
