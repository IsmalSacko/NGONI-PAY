<div>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <div class="bg-white rounded-xl border border-slate-200 p-5">
            <div class="flex items-center justify-between">
                <p class="text-sm text-slate-500">Utilisateurs</p>
                <span class="h-8 w-8 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0ZM12 14c-5 0-8 2.5-8 5v1h16v-1c0-2.5-3-5-8-5Z"/></svg>
                </span>
            </div>
            <p class="text-2xl font-semibold text-slate-900 mt-2">{{ $stats['total_users'] }}</p>
            <p class="text-xs text-emerald-600 mt-1 font-medium">+{{ $stats['new_users_today'] }} aujourd'hui</p>
        </div>

        <div class="bg-white rounded-xl border border-slate-200 p-5">
            <div class="flex items-center justify-between">
                <p class="text-sm text-slate-500">Entreprises actives</p>
                <span class="h-8 w-8 rounded-lg bg-violet-50 text-violet-600 flex items-center justify-center">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 21V7l8-4 8 4v14M9 21v-6h6v6M4 21h16"/></svg>
                </span>
            </div>
            <p class="text-2xl font-semibold text-slate-900 mt-2">{{ $stats['active_businesses'] }}</p>
            <p class="text-xs text-slate-400 mt-1">{{ $stats['total_businesses'] }} au total</p>
        </div>

        <div class="bg-white rounded-xl border border-slate-200 p-5">
            <div class="flex items-center justify-between">
                <p class="text-sm text-slate-500">Paiements réussis</p>
                <span class="h-8 w-8 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 8h20M2 8v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8M2 8l2-4h16l2 4"/></svg>
                </span>
            </div>
            <p class="text-2xl font-semibold text-slate-900 mt-2">{{ $stats['payments_today_count'] }}</p>
            <p class="text-xs text-slate-400 mt-1">{{ number_format($stats['payments_today_amount'], 0, ',', ' ') }} XOF aujourd'hui</p>
        </div>

        <div class="bg-white rounded-xl border border-slate-200 p-5">
            <p class="text-sm text-slate-500 mb-2">Abonnements</p>
            <div class="space-y-1.5">
                <div class="flex items-center justify-between text-sm">
                    <span class="text-slate-500">Free</span>
                    <span class="font-medium text-slate-700">{{ $plans['free'] ?? 0 }}</span>
                </div>
                <div class="flex items-center justify-between text-sm">
                    <span class="text-slate-500">Basic</span>
                    <span class="font-medium text-slate-700">{{ $plans['basic'] ?? 0 }}</span>
                </div>
                <div class="flex items-center justify-between text-sm">
                    <span class="text-slate-500">Pro</span>
                    <span class="font-medium text-indigo-600">{{ $plans['pro'] ?? 0 }}</span>
                </div>
            </div>
        </div>
    </div>

    <div class="flex gap-4">
        <a href="{{ route('admin.users.index') }}" class="text-sm text-indigo-600 hover:text-indigo-700 font-medium">Voir les utilisateurs →</a>
        <a href="{{ route('admin.businesses.index') }}" class="text-sm text-indigo-600 hover:text-indigo-700 font-medium">Voir les entreprises →</a>
        <a href="{{ route('admin.payments.index') }}" class="text-sm text-indigo-600 hover:text-indigo-700 font-medium">Voir les paiements →</a>
    </div>
</div>
