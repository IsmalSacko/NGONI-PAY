<div>
    @if (session('status'))
        <div class="mb-4 rounded-lg bg-emerald-50 px-4 py-2.5 text-sm text-emerald-700">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="mb-4 rounded-lg bg-red-50 px-4 py-2.5 text-sm text-red-600">{{ session('error') }}</div>
    @endif

    <div class="bg-white rounded-xl border border-slate-200 p-5 mb-6">
        <div class="flex items-start justify-between gap-4 flex-wrap">
            <div>
                <h2 class="font-semibold text-slate-900">{{ $campaign->name }}</h2>
                <p class="text-sm text-slate-500 mt-0.5">
                    Dernier envoi :
                    {{ $campaign->last_run_at ? $campaign->last_run_at->format('d/m/Y H:i') : 'jamais' }}
                    · {{ $totalRecipients }} utilisateur(s) avec e-mail au total
                </p>
            </div>

            <button wire:click="sendToAll" wire:loading.attr="disabled" wire:target="sendToAll"
                    wire:confirm="Envoyer l'annonce à TOUS les utilisateurs avec e-mail ({{ $totalRecipients }}) maintenant ?"
                    class="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50">
                <span wire:loading.remove wire:target="sendToAll">Envoyer à tous ({{ $totalRecipients }})</span>
                <span wire:loading wire:target="sendToAll">Envoi en cours...</span>
            </button>
        </div>

        <div class="mt-5 pt-5 border-t border-slate-100">
            <p class="text-sm font-medium text-slate-700 mb-2">Envoi automatique</p>
            <p class="text-xs text-slate-500 mb-3">
                À chaque exécution, seuls les utilisateurs qui n'ont pas encore reçu cette annonce
                (nouvelles inscriptions) sont contactés.
            </p>
            <div class="flex items-center gap-3 flex-wrap">
                <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" wire:model="isRecurring" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                    Activer
                </label>

                <select wire:model="frequency" class="rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none">
                    <option value="weekly">Chaque semaine</option>
                    <option value="every_3_weeks">Toutes les 3 semaines</option>
                    <option value="monthly">Une fois par mois</option>
                </select>

                <button wire:click="saveSchedule" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">
                    Enregistrer
                </button>

                @if ($campaign->is_recurring && $campaign->next_run_at)
                    <span class="text-xs text-slate-500">Prochain envoi auto : {{ $campaign->next_run_at->format('d/m/Y H:i') }}</span>
                @endif
            </div>
        </div>
    </div>

    <div class="flex items-center justify-between gap-3 flex-wrap mb-4">
        <div class="relative max-w-sm w-full">
            <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
            </span>
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Rechercher (nom, email)"
                   class="w-full rounded-lg border border-slate-300 pl-9 pr-3 py-2 text-base sm:text-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none">
        </div>

        <button wire:click="sendToSelected" wire:loading.attr="disabled" wire:target="sendToSelected"
                wire:confirm="Envoyer l'annonce aux {{ count($selected) }} utilisateur(s) sélectionné(s) ?"
                @if (empty($selected)) disabled @endif
                class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed">
            <span wire:loading.remove wire:target="sendToSelected">Envoyer à la sélection ({{ count($selected) }})</span>
            <span wire:loading wire:target="sendToSelected">Envoi en cours...</span>
        </button>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-100 text-sm">
            <thead class="bg-slate-50">
                <tr>
                    <th class="px-4 py-3 w-10"></th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Nom</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Email</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Statut campagne</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($users as $user)
                    @php($lastSend = $user->campaignSends->first())
                    <tr wire:key="campaign-user-{{ $user->id }}" class="hover:bg-slate-50/60">
                        <td class="px-4 py-3">
                            <input type="checkbox" value="{{ $user->id }}" wire:model.live="selected" wire:key="checkbox-{{ $user->id }}" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                        </td>
                        <td class="px-4 py-3 font-medium text-slate-900">{{ $user->name }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $user->email }}</td>
                        <td class="px-4 py-3">
                            @if (! $lastSend)
                                <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-500">Jamais envoyé</span>
                            @elseif ($lastSend->status === 'sent')
                                <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">
                                    Envoyé le {{ $lastSend->sent_at?->format('d/m/Y') }}
                                </span>
                            @else
                                <span class="inline-flex items-center rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-medium text-red-600">Échec</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-10 text-center text-slate-400">Aucun utilisateur trouvé.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $users->links() }}
    </div>
</div>
