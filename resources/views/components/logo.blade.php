{{-- Logo Ngoni Caisse : le ticket de caisse jaune, et le nom
     sur deux lignes si demandé (« NGONI » dans la couleur donnée, « CAISSE » en jaune). --}}
@props(['taille' => 'w-10 h-10', 'nom' => false, 'couleurNom' => 'text-white'])
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2.5']) }}>
    {{-- Ticket de caisse jaune marqué « N » (même dessin que l'icône de l'application). --}}
    <span class="{{ $taille }} flex items-center justify-center shrink-0" aria-hidden="true">
        <svg viewBox="292 190 440 622" class="h-full w-auto">
            <path d="M292 190 H732 V812 L678 772 L622 812 L566 772 L512 812 L458 772 L402 812 L346 772 L292 812 Z" fill="#FFCC1F"/>
            <text x="512" y="470" text-anchor="middle" font-family="Poppins, sans-serif" font-weight="800" font-size="300" fill="#0F2A5C">N</text>
            <rect x="362" y="560" width="300" height="34" rx="17" fill="#0F2A5C" opacity="0.35"/>
            <rect x="362" y="632" width="210" height="34" rx="17" fill="#0F2A5C" opacity="0.35"/>
        </svg>
    </span>
    @if ($nom)
        <span class="font-display font-extrabold leading-none tracking-wide">
            <span class="block {{ $couleurNom }}">NGONI</span>
            <span class="block text-jaune mt-0.5">CAISSE</span>
        </span>
    @endif
</span>
