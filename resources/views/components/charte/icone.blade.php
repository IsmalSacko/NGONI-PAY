{{-- Pictogramme Material, le même que dans l'application (Icons.inventory_2…). --}}
@props(['nom', 'taille' => 22, 'plein' => false])
<span aria-hidden="true" {{ $attributes->merge(['class' => 'ms'.($plein ? ' ms-plein' : '')]) }} style="font-size: {{ $taille }}px">{{ $nom }}</span>
