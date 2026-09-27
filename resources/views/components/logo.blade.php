{{-- Logo Ngoni Caisse : le panier bleu nuit dans un carré jaune, et le nom
     sur deux lignes si demandé (« NGONI » dans la couleur donnée, « CAISSE » en jaune). --}}
@props(['taille' => 'w-10 h-10', 'nom' => false, 'couleurNom' => 'text-white'])
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2.5']) }}>
    <span class="{{ $taille }} rounded-[28%] bg-jaune flex items-center justify-center shrink-0" aria-hidden="true">
        <svg viewBox="200 262 624 560" class="w-[70%] h-[70%]">
            <path d="M 352 478 A 160 160 0 0 1 672 478" fill="none" stroke="#0F2A5C" stroke-width="58" stroke-linecap="round"/>
            <rect x="222" y="448" width="580" height="96" rx="48" fill="#0F2A5C"/>
            <path d="M 268 572 L 756 572 L 712 764 Q 704 800 668 800 L 356 800 Q 320 800 312 764 Z" fill="#0F2A5C"/>
            <rect x="396" y="620" width="40" height="132" rx="20" fill="#FFCC1F"/>
            <rect x="492" y="620" width="40" height="132" rx="20" fill="#FFCC1F"/>
            <rect x="588" y="620" width="40" height="132" rx="20" fill="#FFCC1F"/>
        </svg>
    </span>
    @if ($nom)
        <span class="font-display font-extrabold leading-none tracking-wide">
            <span class="block {{ $couleurNom }}">NGONI</span>
            <span class="block text-jaune mt-0.5">CAISSE</span>
        </span>
    @endif
</span>
