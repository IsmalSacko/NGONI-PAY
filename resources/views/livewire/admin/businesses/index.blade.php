<div>
    <div class="mb-4 relative max-w-sm">
        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
        </span>
        <input type="text" wire:model.live.debounce.300ms="search" placeholder="Rechercher (nom, téléphone, propriétaire)"
               class="w-full rounded-lg border border-slate-300 pl-9 pr-3 py-2 text-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none">
    </div>

    <div class="bg-white rounded-xl border border-slate-200 overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-100 text-sm">
            <thead class="bg-slate-50">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Nom</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Type</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Propriétaire</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Abonnement</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Statut</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($businesses as $business)
                    <tr class="hover:bg-slate-50/60">
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.businesses.show', $business) }}" class="font-medium text-slate-900 hover:text-indigo-600">
                                {{ $business->name }}
                            </a>
                        </td>
                        <td class="px-4 py-3 text-slate-600">{{ $business->type }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $business->owner?->name ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center rounded-full bg-indigo-50 px-2.5 py-0.5 text-xs font-medium text-indigo-700">
                                {{ $business->subscription?->plan ?? 'free' }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            @if ($business->is_active)
                                <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">Active</span>
                            @else
                                <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-500">Désactivée</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right space-x-3 whitespace-nowrap">
                            <a href="{{ route('admin.businesses.show', $business) }}" class="text-slate-500 hover:text-indigo-600 text-sm font-medium">Détail</a>
                            <button wire:click="toggleActive({{ $business->id }})" class="text-slate-500 hover:text-indigo-600 text-sm font-medium">
                                {{ $business->is_active ? 'Désactiver' : 'Activer' }}
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-slate-400">Aucune entreprise trouvée.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $businesses->links() }}
    </div>
</div>
