<div class="bg-white rounded-xl border border-slate-200 p-4">
    <div class="flex items-center justify-between gap-4 flex-wrap">
        <div>
            <a href="{{ route('admin.businesses.show', $business) }}" class="font-medium text-slate-900 hover:text-indigo-600">
                {{ $business->name }}
            </a>
            <p class="text-xs text-slate-500 mt-0.5">{{ $business->owner?->name ?? '—' }} · {{ $business->owner?->phone ?? '—' }}</p>
        </div>

        <div class="flex items-center gap-3">
            <span class="inline-flex items-center rounded-full bg-indigo-50 px-2.5 py-0.5 text-xs font-medium text-indigo-700">
                {{ $business->subscription?->plan ?? 'free' }}
            </span>
            @if ($business->subscription?->is_manual)
                <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-500">manuel</span>
            @endif
            @if ($business->subscription?->ends_at)
                <span class="text-xs text-slate-400">
                    jusqu'au {{ \Illuminate\Support\Carbon::parse($business->subscription->ends_at)->format('d/m/Y') }}
                </span>
            @elseif ($business->subscription?->is_manual)
                <span class="text-xs text-slate-400">à vie</span>
            @endif

            <button wire:click="toggleOpen" class="text-sm text-indigo-600 hover:text-indigo-700 font-medium">
                {{ $open ? 'Fermer' : 'Gérer' }}
            </button>

            @if ($business->subscription?->is_manual)
                <button wire:click="revoke" wire:confirm="Retirer l'override et repasser en free ?"
                        class="text-sm text-red-500 hover:text-red-600 font-medium">
                    Révoquer
                </button>
            @endif
        </div>
    </div>

    @if ($message)
        <p class="mt-3 text-sm text-emerald-700 bg-emerald-50 rounded-lg px-3 py-2">{{ $message }}</p>
    @endif

    @if ($open)
        <form wire:submit="grant" class="mt-4 pt-4 border-t border-slate-100 space-y-3">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1">Plan</label>
                    <select wire:model="plan" class="w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500/20">
                        <option value="free">Free</option>
                        <option value="basic">Basic</option>
                        <option value="pro">Pro</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1">Date de fin</label>
                    <input type="date" wire:model="endsAt" @disabled($lifetime)
                           class="w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500/20 disabled:bg-slate-50">
                </div>
            </div>
            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" wire:model="lifetime" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500/30">
                À vie (aucune date de fin)
            </label>
            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">Note admin</label>
                <input type="text" wire:model="adminNote" class="w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500/20">
            </div>
            <button type="submit" class="rounded-lg bg-gradient-to-r from-violet-600 to-indigo-600 px-4 py-2 text-sm font-medium text-white hover:from-violet-500 hover:to-indigo-500 transition">
                Accorder / mettre à jour
            </button>
        </form>
    @endif
</div>
