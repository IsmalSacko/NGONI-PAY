<div>
    @if (session('status'))
        <div class="mb-4 rounded-lg bg-emerald-50 px-4 py-2.5 text-sm text-emerald-700">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="mb-4 rounded-lg bg-red-50 px-4 py-2.5 text-sm text-red-600">{{ session('error') }}</div>
    @endif

    <a href="{{ route('admin.businesses.index') }}" class="text-sm text-indigo-600 hover:text-indigo-700 font-medium">&larr; Retour aux entreprises</a>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mt-4">
        <div class="bg-white rounded-xl border border-slate-200 p-5 lg:col-span-1">
            <h2 class="font-semibold text-slate-900 mb-3">Informations</h2>
            <dl class="text-sm space-y-2">
                <div class="flex justify-between"><dt class="text-slate-500">Type</dt><dd class="text-slate-700">{{ $business->type }}</dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">Téléphone</dt><dd class="text-slate-700">{{ $business->phone ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">Propriétaire</dt><dd class="text-slate-700">{{ $business->owner?->name ?? '—' }}</dd></div>
                <div class="flex justify-between items-center"><dt class="text-slate-500">Statut</dt>
                    <dd>
                        @if ($business->is_active)
                            <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">Active</span>
                        @else
                            <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-500">Désactivée</span>
                        @endif
                    </dd>
                </div>
            </dl>
            <button wire:click="toggleActive" class="mt-4 block text-sm text-indigo-600 hover:text-indigo-700 font-medium">
                {{ $business->is_active ? 'Désactiver cette entreprise' : 'Activer cette entreprise' }}
            </button>
            <button wire:click="forceDelete"
                    wire:confirm="Supprimer DÉFINITIVEMENT « {{ $business->name }} » ? Ceci efface aussi tous ses paiements, clients, staff et abonnement. Action irréversible."
                    class="mt-2 block text-sm text-red-600 hover:text-red-700 font-medium">
                Supprimer définitivement
            </button>
        </div>

        <div class="bg-white rounded-xl border border-slate-200 p-5 lg:col-span-2">
            <h2 class="font-semibold text-slate-900 mb-3">Abonnement</h2>
            <p class="text-sm text-slate-500 mb-4">
                Actuel :
                <span class="inline-flex items-center rounded-full bg-indigo-50 px-2.5 py-0.5 text-xs font-medium text-indigo-700 align-middle">
                    {{ $business->subscription?->plan ?? 'free' }}
                </span>
                @if ($business->subscription?->ends_at)
                    <span class="text-slate-400">— jusqu'au {{ \Illuminate\Support\Carbon::parse($business->subscription->ends_at)->format('d/m/Y') }}</span>
                @elseif ($business->subscription?->is_manual)
                    <span class="text-slate-400">— à vie</span>
                @endif
            </p>

            <form wire:submit="grantSubscription" class="space-y-3">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1">Plan</label>
                        <select wire:model="plan" class="w-full rounded-lg border-slate-300 px-3 py-2 text-base sm:text-sm focus:border-indigo-500 focus:ring-indigo-500/20">
                            <option value="free">Free</option>
                            <option value="basic">Basic</option>
                            <option value="pro">Pro</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1">Date de fin</label>
                        <input type="date" wire:model="endsAt" @disabled($lifetime)
                               class="w-full rounded-lg border-slate-300 px-3 py-2 text-base sm:text-sm focus:border-indigo-500 focus:ring-indigo-500/20 disabled:bg-slate-50">
                    </div>
                </div>
                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input type="checkbox" wire:model="lifetime" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500/30">
                    À vie (aucune date de fin)
                </label>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1">Note admin</label>
                    <input type="text" wire:model="adminNote" class="w-full rounded-lg border-slate-300 px-3 py-2 text-base sm:text-sm focus:border-indigo-500 focus:ring-indigo-500/20">
                </div>
                <div class="flex flex-col sm:flex-row gap-3 pt-1">
                    <button type="submit" class="rounded-lg bg-gradient-to-r from-violet-600 to-indigo-600 px-4 py-2 text-sm font-medium text-white hover:from-violet-500 hover:to-indigo-500 transition">
                        Accorder / mettre à jour
                    </button>
                    <button type="button" wire:click="revokeSubscription" wire:confirm="Retirer l'override et repasser en free ?"
                            class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50 transition">
                        Révoquer l'override
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-5 mt-6">
        <h2 class="font-semibold text-slate-900 mb-3">Staff</h2>
        <form wire:submit="addStaff" class="flex flex-col sm:flex-row sm:items-end gap-3 mb-4">
            <div class="flex-1">
                <label class="block text-xs font-medium text-slate-500 mb-1">Téléphone ou email</label>
                <input type="text" wire:model="staffPhone" class="w-full rounded-lg border-slate-300 px-3 py-2 text-base sm:text-sm focus:border-indigo-500 focus:ring-indigo-500/20">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">Rôle</label>
                <select wire:model="staffRole" class="w-full sm:w-auto rounded-lg border-slate-300 px-3 py-2 text-base sm:text-sm focus:border-indigo-500 focus:ring-indigo-500/20">
                    <option value="seller">Seller</option>
                    <option value="manager">Manager</option>
                </select>
            </div>
            <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 transition">Ajouter</button>
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-100 text-sm">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="px-4 py-2.5 text-left font-medium text-slate-500">Nom</th>
                        <th class="px-4 py-2.5 text-left font-medium text-slate-500">Téléphone</th>
                        <th class="px-4 py-2.5 text-left font-medium text-slate-500">Rôle</th>
                        <th class="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($staff as $member)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-4 py-2.5 text-slate-900 font-medium">{{ $member->name }}</td>
                            <td class="px-4 py-2.5 text-slate-600">{{ $member->phone ?? '—' }}</td>
                            <td class="px-4 py-2.5">
                                <span class="inline-flex items-center rounded-full bg-indigo-50 px-2.5 py-0.5 text-xs font-medium text-indigo-700">{{ $member->pivot->role }}</span>
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                @if ($business->owner_id !== $member->id)
                                    <button wire:click="removeStaff({{ $member->id }})" class="text-red-500 hover:text-red-600 text-sm font-medium">Retirer</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-6 text-center text-slate-400">Aucun membre.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-5 mt-6">
        <h2 class="font-semibold text-slate-900 mb-3">Paiements récents</h2>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-100 text-sm">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="px-4 py-2.5 text-left font-medium text-slate-500">Client</th>
                        <th class="px-4 py-2.5 text-left font-medium text-slate-500">Montant</th>
                        <th class="px-4 py-2.5 text-left font-medium text-slate-500">Méthode</th>
                        <th class="px-4 py-2.5 text-left font-medium text-slate-500">Statut</th>
                        <th class="px-4 py-2.5 text-left font-medium text-slate-500">Date</th>
                        <th class="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($payments as $payment)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-4 py-2.5 text-slate-700">{{ $payment->client?->name ?? $payment->client?->phone ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-slate-900 font-medium">{{ number_format($payment->amount, 0, ',', ' ') }} {{ $payment->currency }}</td>
                            <td class="px-4 py-2.5 text-slate-600">{{ $payment->method }}</td>
                            <td class="px-4 py-2.5">
                                @php($colors = ['success' => 'bg-emerald-50 text-emerald-700', 'pending' => 'bg-amber-50 text-amber-700', 'failed' => 'bg-red-50 text-red-600', 'cancelled' => 'bg-slate-100 text-slate-500'])
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $colors[$payment->status] ?? 'bg-slate-100 text-slate-500' }}">
                                    {{ $payment->status }}
                                </span>
                            </td>
                            <td class="px-4 py-2.5 text-slate-500">{{ $payment->created_at->format('d/m/Y H:i') }}</td>
                            <td class="px-4 py-2.5 text-right">
                                @if (!in_array($payment->status, ['cancelled']) && $payment->purpose !== 'subscription')
                                    <button wire:click="cancelPayment({{ $payment->id }})" wire:confirm="Annuler ce paiement ?"
                                            class="text-red-500 hover:text-red-600 text-sm font-medium">Annuler</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-6 text-center text-slate-400">Aucun paiement.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $payments->links() }}</div>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-5 mt-6">
        <h2 class="font-semibold text-slate-900 mb-3">Clients</h2>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-100 text-sm">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="px-4 py-2.5 text-left font-medium text-slate-500">Nom</th>
                        <th class="px-4 py-2.5 text-left font-medium text-slate-500">Téléphone</th>
                        <th class="px-4 py-2.5 text-left font-medium text-slate-500">Email</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($clients as $client)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-4 py-2.5 text-slate-700">{{ $client->name ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-slate-700">{{ $client->phone }}</td>
                            <td class="px-4 py-2.5 text-slate-700">{{ $client->email ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-4 py-6 text-center text-slate-400">Aucun client.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $clients->links() }}</div>
    </div>
</div>
