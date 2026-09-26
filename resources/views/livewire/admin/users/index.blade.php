<div>
    @if (session('status'))
        <div class="mb-4 rounded-lg bg-emerald-50 px-4 py-2.5 text-sm text-emerald-700">{{ session('status') }}</div>
    @endif
    @if ($temporaryPassword)
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <p class="font-medium">Mot de passe provisoire pour {{ $temporaryPasswordFor }}</p>
            <p class="mt-1 font-mono text-lg tracking-wider select-all">{{ $temporaryPassword }}</p>
            <p class="mt-1 text-xs">Affiché une seule fois. Ses sessions ont été fermées ; il le changera depuis son profil.</p>
            <div class="mt-2 flex gap-3">
                @if ($temporaryPasswordWhatsApp)
                    <a href="{{ $temporaryPasswordWhatsApp }}" target="_blank" rel="noopener" class="font-medium text-emerald-700 hover:underline">Envoyer sur WhatsApp</a>
                @endif
                <button wire:click="dismissTemporaryPassword" class="text-amber-800 hover:underline">Fermer</button>
            </div>
        </div>
    @endif
    @if (session('error'))
        <div class="mb-4 rounded-lg bg-red-50 px-4 py-2.5 text-sm text-red-600">{{ session('error') }}</div>
    @endif

    <div class="mb-4 relative max-w-sm">
        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
        </span>
        <input type="text" wire:model.live.debounce.300ms="search" placeholder="Rechercher (nom, téléphone, email)"
               class="w-full rounded-lg border border-slate-300 pl-9 pr-3 py-2 text-base sm:text-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none">
    </div>

    <div class="bg-white rounded-xl border border-slate-200 overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-100 text-sm">
            <thead class="bg-slate-50">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Nom</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Téléphone</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Email</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Rôle</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Statut</th>
                    <th class="px-4 py-3 text-left font-medium text-slate-500">Inscrit le</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($users as $user)
                    <tr class="hover:bg-slate-50/60">
                        <td class="px-4 py-3 font-medium text-slate-900">{{ $user->name }}</td>
                        <td class="px-4 py-3 text-slate-600"><x-admin.phone :phone="$user->phone" /></td>
                        <td class="px-4 py-3 text-slate-600">{{ $user->email ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center rounded-full bg-indigo-50 px-2.5 py-0.5 text-xs font-medium text-indigo-700">
                                {{ $user->role }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            @if ($user->is_active)
                                <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">Actif</span>
                            @else
                                <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-500">Désactivé</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-slate-500">{{ $user->created_at?->format('d/m/Y') ?? '—' }}</td>
                        <td class="px-4 py-3 text-right space-x-3 whitespace-nowrap">
                            <button wire:click="resetPassword({{ $user->id }})" wire:confirm="Générer un mot de passe provisoire pour {{ $user->name }} ? Son mot de passe actuel ne fonctionnera plus."
                                    class="text-slate-500 hover:text-indigo-600 text-sm font-medium">
                                Mot de passe
                            </button>
                            <button wire:click="toggleActive({{ $user->id }})" class="text-slate-500 hover:text-indigo-600 text-sm font-medium">
                                {{ $user->is_active ? 'Désactiver' : 'Activer' }}
                            </button>
                            <button wire:click="delete({{ $user->id }})"
                                    wire:confirm="Supprimer définitivement cet utilisateur ?"
                                    class="text-red-500 hover:text-red-600 text-sm font-medium">
                                Supprimer
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-10 text-center text-slate-400">Aucun utilisateur trouvé.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $users->links() }}
    </div>
</div>
