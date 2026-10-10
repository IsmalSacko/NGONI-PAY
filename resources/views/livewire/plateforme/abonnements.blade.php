@php
    $champ = 'h-11 px-3.5 rounded-xl border border-border-strong bg-white text-sm focus:outline-none focus:border-accent/40 focus:ring-2 focus:ring-accent/30';
    $f = fn (int $n) => number_format($n, 0, ',', ' ').' F';
    $teintes = ['jeko' => 'bg-emerald-50 text-emerald-700', 'pawapay' => 'bg-violet-50 text-violet-700', 'fedapay' => 'bg-sky-50 text-sky-700', 'manuel' => 'bg-amber-50 text-amber-700'];
@endphp
<div class="flex flex-col gap-5">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-display font-extrabold text-2xl md:text-3xl">Abonnements</h1>
            <p class="text-sm text-muted">Les abonnements payés, par canal : en ligne (activés seuls) ou validés à la main.</p>
        </div>
        <div class="flex flex-wrap md:flex-nowrap gap-3 w-full md:w-auto">
            <select wire:model.live="periode" class="{{ $champ }} pr-8">
                @foreach (\App\Services\Console\AbonnementsConsole::PERIODES as $code => $libelle)<option value="{{ $code }}">{{ $libelle }}</option>@endforeach
            </select>
            <label class="relative flex-1 md:flex-none">
                <span class="sr-only">Rechercher</span>
                <x-plateforme.picto nom="recherche" class="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-muted pointer-events-none" />
                <input wire:model.live.debounce.300ms="recherche" type="text" placeholder="Nom, téléphone…" class="{{ $champ }} w-full md:w-72 pl-10">
            </label>
        </div>
    </div>

    {{-- Une carte par canal : toucher filtre la liste. --}}
    <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
        @foreach (\App\Services\Console\AbonnementsConsole::CANAUX as $code => $libelle)
            <button wire:click="$set('canal', '{{ $code }}')" wire:key="canal-{{ $code ?: 'tous' }}"
                    class="text-left rounded-2xl p-4 shadow-carte transition {{ $canal === $code ? 'bg-accent text-white' : 'bg-white hover:bg-accent-soft' }}">
                <p class="text-sm font-semibold {{ $canal === $code ? 'text-white/80' : 'text-muted' }}">{{ $code === '' ? 'Tous les canaux' : $libelle }}</p>
                <p class="font-display font-extrabold text-2xl mt-1">{{ $f($totaux[$code]['montant'] ?? 0) }}</p>
                <p class="text-xs mt-0.5 {{ $canal === $code ? 'text-white/80' : 'text-muted' }}">{{ $totaux[$code]['nombre'] ?? 0 }} abonnement(s)@if (($totaux[$code]['frais'] ?? 0) > 0) · {{ $f($totaux[$code]['frais']) }} de frais @endif</p>
            </button>
        @endforeach
    </div>
    <p class="text-xs text-muted -mt-2">Montants encaissés hors frais d’opérateur. En plus : {{ $accordes }} abonnement(s) accordé(s) depuis la console, sans paiement en ligne.</p>

    <section class="bg-white rounded-2xl shadow-carte divide-y divide-separateur">
        @forelse ($paiements as $d)
            @php($l = \App\Services\Console\AbonnementsConsole::ligne($d))
            <div class="flex flex-wrap md:flex-nowrap items-center gap-x-4 gap-y-1 px-4 md:px-5 py-3 text-sm" wire:key="paiement-{{ $d->id }}">
                <div class="flex flex-col min-w-0 flex-1 basis-48">
                    <span class="font-bold truncate">{{ $l['boutique'] ?? '—' }}</span>
                    <span class="text-muted text-xs">{{ $l['proprietaire'] }} · {{ $l['telephone'] }}</span>
                </div>
                <span class="text-xs text-muted whitespace-nowrap">{{ ucfirst($l['plan']) }} · {{ $l['cycle'] }}</span>
                <span class="rounded-full px-2.5 py-0.5 text-xs font-bold whitespace-nowrap {{ $teintes[$l['canal']] ?? '' }}">{{ $l['moyen'] }}</span>
                <span class="text-xs whitespace-nowrap {{ $l['automatique'] ? 'text-emerald-700' : 'text-muted' }}">{{ $l['automatique'] ? 'activé seul' : 'validé à la main' }}</span>
                <span class="font-bold tabular-nums whitespace-nowrap md:w-28 md:text-right">{{ $f($l['montant']) }}</span>
                <span class="text-xs text-muted whitespace-nowrap md:w-28 md:text-right">{{ $d->decide_le?->timezone('Africa/Bamako')->format('d/m/Y H:i') }}</span>
            </div>
        @empty
            <div class="p-8 text-center text-sm text-muted">Aucun abonnement payé sur cette période.</div>
        @endforelse
    </section>

    {{ $paiements->links() }}
</div>
