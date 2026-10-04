{{--
    La carte forte de la marque, en tête d'un écran : dégradé bleu nuit,
    pastille jaune en haut à côté du titre, le chiffre clé en grand. Comme
    CarteHeros de l'application. Lignes de précision dans le slot ; un bouton
    à droite dans le slot « action ».
--}}
@props(['icone', 'titre', 'valeur' => null])
<section {{ $attributes->merge(['class' => 'rounded-[20px] p-5 text-white bg-linear-to-br from-nuit-clair to-accent shadow-[0_10px_22px_rgba(15,42,92,.30)]']) }}>
    <div class="flex items-start gap-4">
        <x-charte.pastille :icone="$icone" actif :taille="44" />
        <div class="flex-1 min-w-0 flex flex-col">
            <span class="text-white/70 font-semibold text-[13.5px] truncate">{{ $titre }}</span>
            @if ($valeur !== null)
                <span class="font-display font-extrabold text-[28px] leading-tight tabular-nums truncate">{{ $valeur }}</span>
            @endif
            @if (trim((string) $slot) !== '')
                <div class="mt-1 flex flex-col gap-0.5 text-[13px] font-bold text-jaune">{{ $slot }}</div>
            @endif
        </div>
        @isset($action)
            <div class="shrink-0">{{ $action }}</div>
        @endisset
    </div>
    @isset($pied)
        <div class="mt-4">{{ $pied }}</div>
    @endisset
</section>
