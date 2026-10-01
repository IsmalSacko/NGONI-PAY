<section wire:poll.60s.visible class="bg-white rounded-2xl shadow-carte p-5 flex flex-col">
    <div class="flex items-center justify-between gap-3">
        <h2 class="font-display font-extrabold text-lg">Utilisateurs en ligne</h2>
        <span class="inline-flex items-center gap-1.5 rounded-full bg-succes-doux text-succes px-2.5 py-1 text-xs font-bold">
            <span class="relative inline-flex w-2 h-2"><span class="absolute inset-0 rounded-full bg-succes opacity-60 motion-safe:animate-ping"></span><span class="relative w-2 h-2 rounded-full bg-succes"></span></span>{{ $enLigne }} en ligne
        </span>
    </div>

    <ul class="mt-3 divide-y divide-separateur">
        @forelse ($recents as $r)
            <li class="py-3 flex items-start gap-3">
                <span class="relative shrink-0" role="img" aria-label="{{ $r->en_ligne ? 'En ligne' : 'Hors ligne' }}">
                    <x-plateforme.avatar :nom="$r->nom" taille="w-9 h-9 text-xs" />
                    <span class="absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full ring-2 ring-white {{ $r->en_ligne ? 'bg-succes' : 'bg-rail' }}"></span>
                </span>
                <div class="min-w-0 grow">
                    <p class="font-bold text-sm truncate">{{ $r->boutique ?? 'Sans boutique' }}</p>
                    <p class="text-xs text-muted truncate">{{ $r->nom }}</p>
                    <p class="mt-1 text-xs {{ $r->en_ligne ? 'text-succes font-bold' : 'text-muted' }}">{{ \App\Services\Plateforme\Presences::depuis($r->vu_le) }}</p>
                </div>
                <div class="text-right text-xs text-muted shrink-0 max-w-[45%]">
                    <p class="font-bold text-ink">{{ \App\Support\Presence\Appareil::PLATEFORMES[$r->plateforme] ?? 'Appareil inconnu' }}</p>
                    @if ($r->modele)<p class="truncate" title="{{ $r->modele }}">{{ $r->modele }}</p>@endif
                    @if ($r->pays)<p class="mt-0.5">{{ \App\Services\Plateforme\Encaissements::libellePays($r->pays) }}</p>@endif
                </div>
            </li>
        @empty
            <li class="py-6 text-sm text-muted text-center">Personne n’a encore été vu.</li>
        @endforelse
    </ul>

    <a href="{{ route('plateforme.utilisateurs') }}" class="mt-auto pt-3 text-sm font-bold text-accent hover:underline">Voir tous les utilisateurs →</a>
</section>
