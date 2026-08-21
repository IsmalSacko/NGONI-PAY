<div class="space-y-6">
    @if (session('status'))
        <div class="rounded-lg bg-emerald-50 px-4 py-2.5 text-sm text-emerald-700">{{ session('status') }}</div>
    @endif

    {{-- Ce que l'annonce atteint, avant de l'écrire : le courriel ne touche
         qu'une partie de la base, la notification touche tout le monde. --}}
    <div class="grid gap-3 sm:grid-cols-2">
        <div class="rounded-xl border border-slate-200 bg-white px-4 py-3">
            <div class="text-2xl font-semibold text-slate-900">{{ $totalActifs }}</div>
            <div class="text-xs text-slate-500">comptes actifs — tous notifiés dans l'application</div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white px-4 py-3">
            <div class="text-2xl font-semibold text-indigo-600">{{ $avecEmail }}</div>
            <div class="text-xs text-slate-500">avec une adresse email — recevront le courriel</div>
        </div>
    </div>

    @if ($programmees->isNotEmpty())
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4">
            <div class="mb-2 text-sm font-medium text-amber-900">Annonces programmées</div>
            <div class="space-y-2">
                @foreach ($programmees as $annonce)
                    <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-white px-3 py-2 text-sm">
                        <span class="text-slate-700">
                            {{ $annonce->subject }}
                            <span class="text-slate-400">·
                                {{ $annonce->scheduled_at->translatedFormat('d F Y à H:i') }}
                                · {{ $annonce->audience === 'all' ? 'tous' : $annonce->targets()->count() . ' destinataire(s)' }}
                            </span>
                        </span>
                        <button type="button" wire:click="cancelScheduled({{ $annonce->id }})"
                                class="text-xs text-red-600 hover:underline">Annuler</button>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <form wire:submit="send" class="space-y-5">
        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <div class="mb-4 text-sm font-medium text-slate-900">Le message</div>

            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <label class="block text-xs font-medium text-slate-600">Version annoncée</label>
                    <input type="text" wire:model="version" placeholder="1.0.7"
                           class="mt-1 w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500/20">
                    @error('version') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-xs font-medium text-slate-600">Objet du courriel</label>
                    <input type="text" wire:model="subject" maxlength="200"
                           class="mt-1 w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500/20">
                    @error('subject') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="mt-4">
                <label class="block text-xs font-medium text-slate-600">Message</label>
                <textarea wire:model="message" rows="5" maxlength="2000"
                          class="mt-1 w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500/20"></textarea>
                <p class="mt-1 text-xs text-slate-400">
                    Repris tel quel dans le courriel et dans la notification de l'application.
                </p>
                @error('message') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="mt-4">
                <label class="block text-xs font-medium text-slate-600">Lien de téléchargement</label>
                <input type="url" wire:model="storeUrl"
                       class="mt-1 w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500/20">
                <p class="mt-1 text-xs text-slate-400">
                    La page <code>/telecharger</code> porte les balises Open Graph : partagée sur
                    WhatsApp, elle affiche le nom, la description et le visuel de l'application.
                    <a href="{{ url('/telecharger') }}" target="_blank" rel="noopener"
                       class="text-indigo-600 hover:underline">La voir</a>
                </p>
                @error('storeUrl') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <div class="mb-3 text-sm font-medium text-slate-900">Les destinataires</div>

            <div class="flex flex-wrap gap-4">
                <label class="flex items-center gap-2 text-sm text-slate-700">
                    <input type="radio" wire:model.live="audience" value="all"
                           class="border-slate-300 text-indigo-600 focus:ring-indigo-500/20">
                    Tous les comptes actifs ({{ $totalActifs }})
                </label>
                <label class="flex items-center gap-2 text-sm text-slate-700">
                    <input type="radio" wire:model.live="audience" value="selected"
                           class="border-slate-300 text-indigo-600 focus:ring-indigo-500/20">
                    Une sélection
                </label>
            </div>

            @if ($audience === 'selected')
                <div class="mt-4 space-y-3">
                    <div class="flex flex-wrap items-center gap-3">
                        <input type="search" wire:model.live.debounce.400ms="search"
                               placeholder="Nom, téléphone ou email…"
                               class="min-w-56 flex-1 rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500/20">
                        <button type="button" wire:click="toggleAll"
                                class="rounded-lg border border-slate-300 px-3 py-2 text-xs text-slate-600 hover:border-indigo-300 hover:text-indigo-600">
                            Tout cocher / décocher
                        </button>
                        <span class="text-xs text-slate-500">{{ count($selected) }} coché(s)</span>
                    </div>

                    @error('selected') <p class="text-xs text-red-600">{{ $message }}</p> @enderror

                    <div class="overflow-hidden rounded-lg border border-slate-200">
                        <table class="min-w-full divide-y divide-slate-100 text-sm">
                            <tbody class="divide-y divide-slate-50">
                                @foreach ($destinataires as $destinataire)
                                    <tr>
                                        <td class="w-10 px-3 py-2">
                                            <input type="checkbox" value="{{ $destinataire->id }}"
                                                   wire:model="selected"
                                                   class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500/20">
                                        </td>
                                        <td class="px-3 py-2 text-slate-800">{{ $destinataire->name }}</td>
                                        <td class="px-3 py-2 text-slate-500">{{ $destinataire->phone }}</td>
                                        <td class="px-3 py-2 text-slate-500">
                                            {{ $destinataire->email ?: '— sans email' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div>{{ $destinataires->links() }}</div>
                </div>
            @endif
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <div class="mb-3 text-sm font-medium text-slate-900">Le moment</div>

            <div class="flex flex-wrap gap-4">
                <label class="flex items-center gap-2 text-sm text-slate-700">
                    <input type="radio" wire:model.live="timing" value="now"
                           class="border-slate-300 text-indigo-600 focus:ring-indigo-500/20">
                    Envoyer maintenant
                </label>
                <label class="flex items-center gap-2 text-sm text-slate-700">
                    <input type="radio" wire:model.live="timing" value="scheduled"
                           class="border-slate-300 text-indigo-600 focus:ring-indigo-500/20">
                    Programmer
                </label>
            </div>

            @if ($timing === 'scheduled')
                <div class="mt-4 max-w-xs">
                    <input type="datetime-local" wire:model="scheduledAt"
                           class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500/20">
                    <p class="mt-1 text-xs text-slate-400">Vérifié chaque minute : partira à l'heure dite.</p>
                    @error('scheduledAt') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            @endif
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" wire:loading.attr="disabled"
                    class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">
                {{ $timing === 'scheduled' ? "Programmer l'annonce" : 'Envoyer maintenant' }}
            </button>
            <span wire:loading class="text-sm text-slate-500">Envoi en cours…</span>
        </div>
    </form>

    @if ($historique->isNotEmpty())
        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <div class="mb-3 text-sm font-medium text-slate-900">Annonces envoyées</div>
            <div class="space-y-2">
                @foreach ($historique as $annonce)
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-50 pb-2 text-sm last:border-0">
                        <span class="text-slate-700">
                            {{ $annonce->subject }}
                            @if ($annonce->version)
                                <code class="ml-1 rounded bg-slate-100 px-1.5 text-xs text-slate-500">{{ $annonce->version }}</code>
                            @endif
                        </span>
                        <span class="text-xs text-slate-500">
                            {{ $annonce->last_run_at?->translatedFormat('d/m/Y H:i') }}
                            · {{ $annonce->envoyes }} courriel(s)
                            @if ($annonce->echecs > 0)
                                <span class="text-red-600">· {{ $annonce->echecs }} échec(s)</span>
                            @endif
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
