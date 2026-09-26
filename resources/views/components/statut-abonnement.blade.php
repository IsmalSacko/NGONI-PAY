@props(['abonnement'])

{{-- « expiré le … » dès que la date est passée, « actif jusqu'au … » sinon. --}}
@php($fin = $abonnement?->fin?->format('d/m/Y'))

@if (! $abonnement)
    <span class="text-xs text-muted">aucun abonnement</span>
@elseif (! $abonnement->estEnCours())
    <span class="inline-flex rounded-full bg-danger-bg px-2.5 py-0.5 text-xs font-bold text-danger-fg">expiré{{ $fin ? " le $fin" : '' }}</span>
@elseif ($fin)
    <span class="inline-flex rounded-full bg-accent-soft px-2.5 py-0.5 text-xs font-bold text-accent-dark">actif jusqu'au {{ $fin }}</span>
@else
    <span class="inline-flex rounded-full bg-accent-soft px-2.5 py-0.5 text-xs font-bold text-accent-dark">actif sans échéance</span>
@endif
