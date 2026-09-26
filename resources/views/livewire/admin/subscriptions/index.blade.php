<div>
    <div class="grid grid-cols-2 gap-4 mb-4">
        <div class="bg-white rounded-xl border border-emerald-200 p-4">
            <p class="text-xs text-slate-500">Actifs (essai ou plan en cours)</p>
            <p class="text-xl font-semibold text-emerald-600 mt-1">{{ $summary['active'] }}</p>
        </div>
        <div class="bg-white rounded-xl border border-red-200 p-4">
            <p class="text-xs text-slate-500">Expirés (encaissement bloqué)</p>
            <p class="text-xl font-semibold text-red-600 mt-1">{{ $summary['expired'] }}</p>
        </div>
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-5 gap-4 mb-6">
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <p class="text-xs text-slate-500">Entreprises</p>
            <p class="text-xl font-semibold text-slate-900 mt-1">{{ $summary['total_businesses'] }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <p class="text-xs text-slate-500">Abonnements</p>
            <p class="text-xl font-semibold text-slate-900 mt-1">{{ $summary['total_subscriptions'] }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <p class="text-xs text-slate-500">Essai</p>
            <p class="text-xl font-semibold text-slate-500 mt-1">{{ $summary['trial'] }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <p class="text-xs text-slate-500">Basic</p>
            <p class="text-xl font-semibold text-indigo-500 mt-1">{{ $summary['basic'] }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <p class="text-xs text-slate-500">Pro</p>
            <p class="text-xl font-semibold text-indigo-600 mt-1">{{ $summary['pro'] }}</p>
        </div>
    </div>

    <div class="mb-4 relative max-w-md">
        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
        </span>
        <input type="text" wire:model.live.debounce.300ms="search" placeholder="Rechercher (entreprise, propriétaire, téléphone, email)"
               class="w-full rounded-lg border border-slate-300 pl-9 pr-3 py-2 text-base sm:text-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none">
    </div>

    <div class="space-y-3">
        @forelse ($businesses as $business)
            @livewire('admin.subscriptions.row', ['business' => $business], key('sub-row-' . $business->id))
        @empty
            <div class="bg-white rounded-xl border border-slate-200 p-10 text-center text-slate-400">Aucune entreprise trouvée.</div>
        @endforelse
    </div>

    <div class="mt-4">
        {{ $businesses->links() }}
    </div>
</div>
