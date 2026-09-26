@props(['phone'])

{{-- Numéro de téléphone + bouton WhatsApp (si l'indicatif pays est connu). --}}
@php($wa = \App\Support\WhatsApp::link($phone))

@if (blank($phone))
    <span class="text-slate-400">—</span>
@else
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 whitespace-nowrap']) }}>
        <a href="tel:{{ preg_replace('/[^\d+]/', '', $phone) }}" class="hover:text-indigo-600">{{ $phone }}</a>
        @if ($wa)
            <a href="{{ $wa }}" target="_blank" rel="noopener" title="Écrire sur WhatsApp"
               class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 hover:bg-emerald-100">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.47 14.38c-.3-.15-1.76-.87-2.03-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.17-.17.2-.35.22-.64.07-.3-.15-1.26-.46-2.4-1.48-.89-.79-1.49-1.77-1.66-2.07-.17-.3-.02-.46.13-.61.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.08-.15-.67-1.62-.92-2.22-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.79.37-.27.3-1.04 1.02-1.04 2.48s1.07 2.88 1.21 3.08c.15.2 2.1 3.2 5.08 4.49.71.31 1.26.49 1.69.63.71.23 1.36.2 1.87.12.57-.09 1.76-.72 2.01-1.41.25-.7.25-1.29.17-1.41-.07-.12-.27-.2-.57-.35zM12.05 21.5h-.01a9.4 9.4 0 0 1-4.8-1.31l-.34-.2-3.57.93.95-3.48-.22-.36a9.4 9.4 0 0 1-1.44-5.02c0-5.2 4.23-9.43 9.44-9.43 2.52 0 4.89.98 6.67 2.77a9.37 9.37 0 0 1 2.76 6.67c0 5.2-4.23 9.43-9.44 9.43zm8.03-17.46A11.3 11.3 0 0 0 12.05.72C5.8.72.7 5.81.7 12.07c0 2 .52 3.95 1.52 5.67L.6 23.62l6.02-1.58a11.3 11.3 0 0 0 5.42 1.38h.01c6.26 0 11.35-5.09 11.35-11.35 0-3.03-1.18-5.88-3.32-8.03z"/></svg>
                WhatsApp
            </a>
        @else
            <span class="text-[11px] text-slate-400" title="Numéro sans indicatif pays : impossible d'ouvrir WhatsApp">sans indicatif</span>
        @endif
    </span>
@endif
