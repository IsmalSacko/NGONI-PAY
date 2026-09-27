<div class="flex flex-col gap-5">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-display font-extrabold text-2xl md:text-3xl">Annonces</h1>
            <p class="text-sm text-muted">Envoyées en notification push sur les téléphones et dans la cloche de l’application. L’e-mail seulement si vous le cochez.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <button wire:click="nouvelle('mise_a_jour')" class="h-11 px-4 rounded-xl bg-accent text-white font-bold">Mise à jour</button>
            <button wire:click="nouvelle('message')" class="h-11 px-4 rounded-xl border border-border-strong bg-white font-bold">Message libre</button>
            <button wire:click="nouvelle('campagne')" class="h-11 px-4 rounded-xl border border-border-strong bg-white font-bold">Campagne</button>
        </div>
    </div>

    @if ($info)<p class="rounded-xl bg-accent-soft text-accent-dark px-4 py-3 text-sm font-semibold">{{ $info }}</p>@endif

    @if ($formulaire)
        <form wire:submit="enregistrer" class="bg-white border border-border rounded-2xl p-5 flex flex-col gap-4">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="font-display font-extrabold text-xl">{{ \App\Models\Annonce::TYPES[$type] }}</h2>
                <select wire:model.live="type" class="h-9 px-2 rounded-lg border border-border-strong bg-white text-sm ml-auto">
                    @foreach (\App\Models\Annonce::TYPES as $code => $libelle)
                        <option value="{{ $code }}">{{ $libelle }}</option>
                    @endforeach
                </select>
            </div>

            <label class="flex flex-col gap-1 text-sm font-semibold">Titre
                <input wire:model="titre" type="text" maxlength="120" class="h-11 px-3 rounded-lg border border-border-strong font-normal">
                @error('titre') <span class="text-danger-fg font-normal">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1 text-sm font-semibold">Message
                <textarea wire:model="message" rows="4" maxlength="2000" class="px-3 py-2 rounded-lg border border-border-strong font-normal"></textarea>
                @error('message') <span class="text-danger-fg font-normal">{{ $message }}</span> @enderror
            </label>
            <div class="grid grid-cols-1 sm:grid-cols-[1fr_2fr] gap-3">
                <label class="flex flex-col gap-1 text-sm font-semibold">Version <span class="font-normal text-muted">(mise à jour)</span>
                    <input wire:model="version" type="text" placeholder="3.0.0" class="h-11 px-3 rounded-lg border border-border-strong font-normal">
                </label>
                <label class="flex flex-col gap-1 text-sm font-semibold">Lien <span class="font-normal text-muted">(Play Store, page, formulaire…)</span>
                    <input wire:model="lien" type="url" class="h-11 px-3 rounded-lg border border-border-strong font-normal">
                    @error('lien') <span class="text-danger-fg font-normal">{{ $message }}</span> @enderror
                </label>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <label class="flex flex-col gap-1 text-sm font-semibold">Destinataires
                    <select wire:model.live="audience" class="h-11 px-3 rounded-lg border border-border-strong bg-white font-normal">
                        @foreach (\App\Models\Annonce::AUDIENCES as $code => $libelle)
                            <option value="{{ $code }}">{{ $libelle }}</option>
                        @endforeach
                    </select>
                    <span class="font-normal text-muted">{{ $apercu }} compte(s) concerné(s).</span>
                </label>
                <label class="flex items-center gap-2 text-sm sm:mt-7">
                    <input type="checkbox" wire:model="par_email"> Envoyer aussi par e-mail <span class="text-muted">(facultatif, compte dans le quota Mailjet)</span>
                </label>
            </div>

            @if ($audience === 'selection')
                <div class="rounded-xl bg-fond-tableau p-3 flex flex-col gap-2">
                    <input wire:model.live.debounce.300ms="recherche" type="text" placeholder="Nom ou téléphone (2 lettres min.)" class="h-10 px-3 rounded-lg border border-border-strong">
                    @foreach ($comptes as $c)
                        <label class="flex items-center gap-2 text-sm" wire:key="c-{{ $c->id }}">
                            <input type="checkbox" wire:click="basculerCible('{{ $c->id }}')" @checked(in_array($c->id, $cibles, true))>
                            {{ $c->name }} <span class="text-muted">{{ $c->phone }}</span>
                        </label>
                    @endforeach
                    @if ($choisis->isNotEmpty())
                        <div class="flex flex-wrap gap-1 text-xs">
                            @foreach ($choisis as $c)
                                <span class="rounded-full bg-accent-soft text-accent-dark px-2 py-1">{{ $c->name }}</span>
                            @endforeach
                        </div>
                    @endif
                    @error('cibles') <span class="text-sm text-danger-fg">{{ $message }}</span> @enderror
                </div>
            @endif

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm">
                <label class="flex flex-col gap-1 font-semibold">Envoi
                    <select wire:model.live="quand" class="h-11 px-3 rounded-lg border border-border-strong bg-white font-normal">
                        <option value="maintenant">Maintenant</option>
                        <option value="programmer">Programmer</option>
                    </select>
                </label>
                @if ($quand === 'programmer')
                    <label class="flex flex-col gap-1 font-semibold">Le
                        <input wire:model="programmee_le" type="datetime-local" class="h-11 px-3 rounded-lg border border-border-strong font-normal">
                        @error('programmee_le') <span class="text-danger-fg font-normal">{{ $message }}</span> @enderror
                    </label>
                @endif
                <label class="flex flex-col gap-1 font-semibold">Répétition
                    <select wire:model="recurrence" class="h-11 px-3 rounded-lg border border-border-strong bg-white font-normal">
                        @foreach (\App\Models\Annonce::RECURRENCES as $code => $libelle)
                            <option value="{{ $code }}">{{ $libelle }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            <div class="flex flex-wrap gap-3">
                <button type="button" wire:click="$set('formulaire', false)" class="h-11 px-5 rounded-xl border border-border-strong font-bold">Annuler</button>
                <button type="submit" class="h-11 px-5 rounded-xl bg-accent text-white font-bold"
                        wire:confirm="Confirmer l’envoi ?">{{ $quand === 'maintenant' ? 'Envoyer maintenant' : 'Programmer' }}</button>
            </div>
        </form>
    @endif

    <section class="bg-white border border-border rounded-2xl overflow-hidden">
        @forelse ($annonces as $a)
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 border-b border-separateur text-sm" wire:key="a-{{ $a->id }}">
                <div class="flex flex-col min-w-0 flex-1">
                    <span class="font-bold truncate">{{ $a->titre }}</span>
                    <span class="text-muted text-xs">
                        {{ \App\Models\Annonce::TYPES[$a->type] ?? $a->type }} · {{ \App\Models\Annonce::AUDIENCES[$a->audience] ?? $a->audience }}
                        @if ($a->recurrence) · {{ \App\Models\Annonce::RECURRENCES[$a->recurrence] }} @endif
                        @if ($a->par_email) · e-mail @endif
                    </span>
                </div>
                <span class="text-xs">
                    @if ($a->statut === 'programmee' && $a->programmee_le)
                        <span class="rounded px-2 py-1 bg-warn-bg text-warn-fg font-bold">prochain envoi {{ $a->programmee_le->format('d/m/Y H:i') }}</span>
                    @elseif ($a->statut === 'envoyee')
                        <span class="rounded px-2 py-1 bg-accent-soft text-accent-dark font-bold">envoyée {{ $a->derniere_diffusion?->format('d/m/Y H:i') }}</span>
                    @else
                        <span class="rounded px-2 py-1 bg-puce text-muted font-bold">arrêtée</span>
                    @endif
                </span>
                <span class="text-xs text-muted">{{ $a->nb_notifies }} notif. · {{ $a->nb_emails }} e-mails @if ($a->nb_echecs) · {{ $a->nb_echecs }} échecs @endif</span>
                <div class="flex gap-2">
                    <button wire:click="envoyerMaintenant({{ $a->id }})" wire:confirm="Renvoyer « {{ $a->titre }} » maintenant ?" class="h-9 px-3 rounded-lg border border-border-strong text-xs font-bold">Renvoyer</button>
                    @if ($a->statut === 'programmee')
                        <button wire:click="arreter({{ $a->id }})" class="h-9 px-3 rounded-lg border border-border-strong text-xs font-bold text-danger-fg">Arrêter</button>
                    @endif
                </div>
            </div>
        @empty
            <p class="p-5 text-sm text-muted">Aucune annonce pour le moment.</p>
        @endforelse
    </section>
</div>
