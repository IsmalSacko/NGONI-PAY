@props(['abonnement'])

{{-- « expiré le … » dès que la date est passée, « actif jusqu'au … » sinon. --}}
@php($fin = $abonnement?->fin?->format('d/m/Y'))

@if (! $abonnement)
    <span class="text-xs text-muted">aucun abonnement</span>
@elseif (! $abonnement->estEnCours())
    <x-plateforme.pastille ton="danger">expiré{{ $fin ? " le $fin" : '' }}</x-plateforme.pastille>
@elseif ($fin)
    <x-plateforme.pastille ton="succes">actif jusqu'au {{ $fin }}</x-plateforme.pastille>
@else
    <x-plateforme.pastille ton="succes">actif sans échéance</x-plateforme.pastille>
@endif
