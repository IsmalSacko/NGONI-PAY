<div>
    @if (session('status'))
        <div class="mb-4 rounded-lg bg-emerald-50 px-4 py-2.5 text-sm text-emerald-700">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="mb-4 rounded-lg bg-red-50 px-4 py-2.5 text-sm text-red-600">{{ session('error') }}</div>
    @endif

    <div class="mb-4 flex flex-wrap gap-3">
        <select wire:model.live="status" class="rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500/20">
            <option value="">Tous les statuts</option>
            <option value="pending">En attente</option>
            <option value="success">Réussi</option>
            <option value="failed">Échoué</option>
            <option value="cancelled">Annulé</option>
        </select>
        <select wire:model.live="method" class="rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500/20">
            <option value="">Toutes les méthodes</option>
            <option value="cash">Cash</option>
            <option value="orange_money">Orange Money</option>
            <option value="moov_money">Moov Money</option>
            <option value="wave">Wave</option>
        </select>
        <input type="date" wire:model.live="date" class="rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500/20">
    </div>

    <div class="bg-white rounded-xl border border-slate-200 overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-100 text-sm">
            <thead class="bg-slate-50">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Entreprise</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Client</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Montant</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Méthode</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Objet</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Statut</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Date</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($payments as $payment)
                    <tr class="hover:bg-slate-50/60">
                        <td class="px-4 py-3">
                            @if ($payment->business)
                                <a href="{{ route('admin.businesses.show', $payment->business) }}" class="text-slate-900 font-medium hover:text-indigo-600">
                                    {{ $payment->business->name }}
                                </a>
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-4 py-3 text-slate-600">{{ $payment->client?->name ?? $payment->client?->phone ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-900 font-medium">{{ number_format($payment->amount, 0, ',', ' ') }} {{ $payment->currency }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $payment->method }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ $payment->purpose }}</td>
                        <td class="px-4 py-3">
                            @php($colors = ['success' => 'bg-emerald-50 text-emerald-700', 'pending' => 'bg-amber-50 text-amber-700', 'failed' => 'bg-red-50 text-red-600', 'cancelled' => 'bg-slate-100 text-slate-500'])
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $colors[$payment->status] ?? 'bg-slate-100 text-slate-500' }}">
                                {{ $payment->status }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-slate-500">{{ $payment->created_at->format('d/m/Y H:i') }}</td>
                        <td class="px-4 py-3 text-right">
                            @if (!in_array($payment->status, ['cancelled']) && $payment->purpose !== 'subscription')
                                <button wire:click="cancelPayment({{ $payment->id }})" wire:confirm="Annuler ce paiement ?"
                                        class="text-red-500 hover:text-red-600 text-sm font-medium">Annuler</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-10 text-center text-slate-400">Aucun paiement trouvé.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $payments->links() }}
    </div>
</div>
