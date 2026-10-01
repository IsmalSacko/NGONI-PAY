@php
    $champ = 'h-11 px-3.5 rounded-xl border border-border-strong bg-white text-sm font-normal focus:outline-none focus:border-accent/40 focus:ring-2 focus:ring-accent/30';
    $pictos = ['mise_a_jour' => ['actualiser', 'info'], 'message' => ['envoyer', 'succes'], 'campagne' => ['megaphone', 'jaune']];
    $bouton = 'h-11 px-4 rounded-xl bg-white shadow-carte font-bold inline-flex items-center gap-2 hover:bg-accent-soft hover:text-accent';
@endphp
<div class="flex flex-col gap-5">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-display font-extrabold text-2xl md:text-3xl">Annonces</h1>
            <p class="text-sm text-muted">Envoyées en notification push sur les téléphones et dans la cloche de l’application. L’e-mail seulement si vous le cochez.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <button wire:click="nouvelle('mise_a_jour')" class="h-11 px-4 rounded-xl bg-accent text-white font-bold inline-flex items-center gap-2 shadow-carte hover:bg-accent-dark"><x-icone nom="actualiser" class="w-4 h-4" />Mise à jour</button>
            <button wire:click="nouvelle('message')" class="{{ $bouton }}"><x-plateforme.picto nom="envoyer" class="w-4 h-4 text-muted" />Message libre</button>
            <button wire:click="nouvelle('campagne')" class="{{ $bouton }}"><x-plateforme.picto nom="megaphone" class="w-4 h-4 text-muted" />Campagne</button>
        </div>
    </div>

    @if ($info)<p class="rounded-xl bg-accent-soft text-accent-dark px-4 py-3 text-sm font-semibold">{{ $info }}</p>@endif

    @if ($formulaire)
        <form wire:submit="enregistrer" class="bg-white rounded-2xl shadow-carte flex flex-col">
            <div class="flex flex-wrap items-center gap-3 px-4 md:px-5 py-3.5 rounded-t-2xl bg-linear-to-r from-accent-soft to-white border-b border-separateur">
                <x-plateforme.icone-chip :nom="$pictos[$type][0] ?? 'envoyer'" :ton="$pictos[$type][1] ?? 'info'" />
                <h2 class="font-display font-extrabold text-xl">{{ \App\Models\Annonce::TYPES[$type] }}</h2>
                <select wire:model.live="type" class="h-9 px-2.5 pr-8 rounded-lg border border-border-strong bg-white text-sm ml-auto focus:outline-none focus:ring-2 focus:ring-accent/30">
                    @foreach (\App\Models\Annonce::TYPES as $code => $libelle)
                        <option value="{{ $code }}">{{ $libelle }}</option>
                    @endforeach
                </select>
            </div>

            <div class="p-4 md:p-5 flex flex-col gap-4">
            <label class="flex flex-col gap-1 text-sm font-semibold">Titre
                <input wire:model="titre" type="text" maxlength="120" class="{{ $champ }}">
                @error('titre') <span class="text-danger-fg font-normal">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1 text-sm font-semibold">Message
                <textarea wire:model="message" rows="4" maxlength="2000" class="px-3.5 py-2.5 rounded-xl border border-border-strong bg-white text-sm font-normal focus:outline-none focus:border-accent/40 focus:ring-2 focus:ring-accent/30"></textarea>
                @error('message') <span class="text-danger-fg font-normal">{{ $message }}</span> @enderror
            </label>
            <div class="grid grid-cols-1 sm:grid-cols-[1fr_2fr] gap-3">
                <label class="flex flex-col gap-1 text-sm font-semibold">Version <span class="font-normal text-muted">(mise à jour)</span>
                    <input wire:model="version" type="text" placeholder="3.0.0" class="{{ $champ }}">
                </label>
                <label class="flex flex-col gap-1 text-sm font-semibold">Lien <span class="font-normal text-muted">(Play Store, page, formulaire…)</span>
                    <input wire:model="lien" type="url" class="{{ $champ }}">
                    @error('lien') <span class="text-danger-fg font-normal">{{ $message }}</span> @enderror
                </label>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <label class="flex flex-col gap-1 text-sm font-semibold">Destinataires
                    <select wire:model.live="audience" class="{{ $champ }}">
                        @foreach (\App\Models\Annonce::AUDIENCES as $code => $libelle)
                            <option value="{{ $code }}">{{ $libelle }}</option>
                        @endforeach
                    </select>
                    <span class="font-normal text-muted">{{ $apercu }} compte(s) concerné(s).</span>
                </label>
                <label class="flex items-center gap-2.5 text-sm sm:mt-6 self-start rounded-xl ring-1 ring-border px-3 py-2.5 cursor-pointer has-checked:bg-accent-soft has-checked:ring-accent/25">
                    <input type="checkbox" wire:model="par_email" class="w-4 h-4 accent-accent shrink-0"> <span>Envoyer aussi par e-mail <span class="text-muted">(facultatif, compte dans le quota Mailjet)</span></span>
                </label>
            </div>

            @if ($audience === 'selection')
                <div class="rounded-xl bg-fond-tableau p-3 flex flex-col gap-2">
                    <input wire:model.live.debounce.300ms="recherche" type="text" placeholder="Nom ou téléphone (2 lettres min.)" class="{{ $champ }}">
                    @foreach ($comptes as $c)
                        <label class="flex items-center gap-2.5 text-sm rounded-lg px-2 py-1.5 cursor-pointer hover:bg-white has-checked:bg-white" wire:key="c-{{ $c->id }}">
                            <input type="checkbox" class="w-4 h-4 accent-accent" wire:click="basculerCible('{{ $c->id }}')" @checked(in_array($c->id, $cibles, true))>
                            <x-plateforme.avatar :nom="$c->name" taille="w-7 h-7 text-[11px]" />
                            <span class="font-semibold">{{ $c->name }}</span> <span class="text-muted">{{ $c->phone }}</span>
                        </label>
                    @endforeach
                    @if ($choisis->isNotEmpty())
                        <div class="flex flex-wrap gap-1 text-xs">
                            @foreach ($choisis as $c)
                                <span class="rounded-full bg-accent-soft text-accent-dark font-bold px-2.5 py-1">{{ $c->name }}</span>
                            @endforeach
                        </div>
                    @endif
                    @error('cibles') <span class="text-sm text-danger-fg">{{ $message }}</span> @enderror
                </div>
            @endif

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm">
                <label class="flex flex-col gap-1 font-semibold">Envoi
                    <select wire:model.live="quand" class="{{ $champ }}">
                        <option value="maintenant">Maintenant</option>
                        <option value="programmer">Programmer</option>
                    </select>
                </label>
                @if ($quand === 'programmer')
                    <label class="flex flex-col gap-1 font-semibold">Le
                        <input wire:model="programmee_le" type="datetime-local" class="{{ $champ }}">
                        @error('programmee_le') <span class="text-danger-fg font-normal">{{ $message }}</span> @enderror
                    </label>
                @endif
                <label class="flex flex-col gap-1 font-semibold">Répétition
                    <select wire:model="recurrence" class="{{ $champ }}">
                        @foreach (\App\Models\Annonce::RECURRENCES as $code => $libelle)
                            <option value="{{ $code }}">{{ $libelle }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            <div class="flex flex-wrap gap-3 pt-1">
                <button type="button" wire:click="$set('formulaire', false)" class="h-11 px-5 rounded-xl ring-1 ring-border-strong font-bold hover:bg-puce">Annuler</button>
                <button type="submit" class="h-11 px-5 rounded-xl bg-accent text-white font-bold inline-flex items-center gap-2 shadow-sm hover:bg-accent-dark"
                        wire:confirm="Confirmer l’envoi ?"><x-plateforme.picto nom="envoyer" class="w-4 h-4" />{{ $quand === 'maintenant' ? 'Envoyer maintenant' : 'Programmer' }}</button>
            </div>
            </div>
        </form>
    @endif

    <section class="bg-white rounded-2xl shadow-carte divide-y divide-separateur">
        @forelse ($annonces as $a)
            <div class="flex flex-wrap md:flex-nowrap items-center gap-x-4 gap-y-2 px-4 md:px-5 py-3.5 text-sm hover:bg-fond-tableau/60 first:rounded-t-2xl last:rounded-b-2xl" wire:key="a-{{ $a->id }}">
                <x-plateforme.icone-chip :nom="$pictos[$a->type][0] ?? 'envoyer'" :ton="$pictos[$a->type][1] ?? 'info'" />
                <div class="flex flex-col min-w-0 flex-1 basis-40">
                    <span class="font-bold truncate">{{ $a->titre }}</span>
                    <span class="text-muted text-xs">
                        {{ \App\Models\Annonce::TYPES[$a->type] ?? $a->type }} · {{ \App\Models\Annonce::AUDIENCES[$a->audience] ?? $a->audience }}
                        @if ($a->recurrence) · {{ \App\Models\Annonce::RECURRENCES[$a->recurrence] }} @endif
                        @if ($a->par_email) · e-mail @endif
                    </span>
                </div>
                <span class="text-xs">
                    @if ($a->statut === 'programmee' && $a->programmee_le)
                        <x-plateforme.pastille ton="attente">prochain envoi {{ $a->programmee_le->format('d/m/Y H:i') }}</x-plateforme.pastille>
                    @elseif ($a->statut === 'envoyee')
                        <x-plateforme.pastille ton="succes">envoyée {{ $a->derniere_diffusion?->format('d/m/Y H:i') }}</x-plateforme.pastille>
                    @else
                        <x-plateforme.pastille ton="neutre">arrêtée</x-plateforme.pastille>
                    @endif
                </span>
                <span class="text-xs text-muted tabular-nums whitespace-nowrap"><span class="font-bold text-ink">{{ $a->nb_notifies }}</span> notif. · <span class="font-bold text-ink">{{ $a->nb_emails }}</span> e-mails @if ($a->nb_echecs) · <span class="font-bold text-danger-fg">{{ $a->nb_echecs }} échecs</span> @endif</span>
                <div class="flex gap-1 ml-auto shrink-0">
                    <button wire:click="envoyerMaintenant({{ $a->id }})" wire:confirm="Renvoyer « {{ $a->titre }} » maintenant ?" title="Renvoyer maintenant"
                            class="h-9 px-2.5 rounded-lg text-xs font-bold text-accent inline-flex items-center gap-1.5 hover:bg-accent-soft focus:outline-none focus-visible:ring-2 focus-visible:ring-accent/30">
                        <x-plateforme.picto nom="envoyer" class="w-4 h-4" />Renvoyer
                    </button>
                    @if ($a->statut === 'programmee')
                        <button wire:click="arreter({{ $a->id }})" title="Arrêter les envois"
                                class="h-9 px-2.5 rounded-lg text-xs font-bold text-danger-fg inline-flex items-center gap-1.5 hover:bg-danger-bg focus:outline-none focus-visible:ring-2 focus-visible:ring-danger-fg/30">
                            <x-plateforme.picto nom="stop" class="w-4 h-4" />Arrêter
                        </button>
                    @endif
                </div>
            </div>
        @empty
            <div class="p-8 flex flex-col items-center gap-3 text-center">
                <x-plateforme.icone-chip nom="megaphone" taille="w-12 h-12" />
                <p class="text-sm text-muted">Aucune annonce pour le moment.</p>
            </div>
        @endforelse
    </section>
</div>
