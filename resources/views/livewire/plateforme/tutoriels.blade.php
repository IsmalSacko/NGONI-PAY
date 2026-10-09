@php
    $champ = 'h-11 px-3.5 rounded-xl border border-border-strong bg-white text-sm font-normal focus:outline-none focus:border-accent/40 focus:ring-2 focus:ring-accent/30';
@endphp
<div class="flex flex-col gap-5">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-display font-extrabold text-2xl md:text-3xl">Tutoriels</h1>
            <p class="text-sm text-muted">Les vidéos YouTube que les commerçants voient dans l’application : Plus → Aide et tutoriels. Ajoutées ici, elles y apparaissent tout de suite.</p>
        </div>
        <button wire:click="nouveau" class="h-11 px-4 rounded-xl bg-accent text-white font-bold inline-flex items-center gap-2 shadow-carte hover:bg-accent-dark">
            <x-plateforme.picto nom="carte" class="w-4 h-4" />Ajouter une vidéo
        </button>
    </div>

    @if ($info)<p class="rounded-xl bg-accent-soft text-accent-dark px-4 py-3 text-sm font-semibold">{{ $info }}</p>@endif

    @if ($formulaire)
        <form wire:submit="enregistrer" class="bg-white rounded-2xl shadow-carte flex flex-col">
            <div class="px-4 md:px-5 py-3.5 rounded-t-2xl bg-linear-to-r from-accent-soft to-white border-b border-separateur">
                <h2 class="font-display font-extrabold text-xl">{{ $edite ? 'Modifier la vidéo' : 'Nouvelle vidéo' }}</h2>
            </div>
            <div class="p-4 md:p-5 flex flex-col gap-4">
                <label class="flex flex-col gap-1 text-sm font-semibold">Lien YouTube
                    <input wire:model="url" type="url" placeholder="https://youtu.be/…" class="{{ $champ }}">
                    @error('url') <span class="text-danger-fg font-normal">{{ $message }}</span> @enderror
                </label>
                <label class="flex flex-col gap-1 text-sm font-semibold">Titre
                    <input wire:model="titre" type="text" maxlength="120" placeholder="Comment enregistrer une vente ?" class="{{ $champ }}">
                    @error('titre') <span class="text-danger-fg font-normal">{{ $message }}</span> @enderror
                </label>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <label class="flex flex-col gap-1 text-sm font-semibold">Sous-titre <span class="font-normal text-muted">(facultatif)</span>
                        <input wire:model="sous_titre" type="text" maxlength="160" placeholder="Guide des ventes" class="{{ $champ }}">
                    </label>
                    <label class="flex flex-col gap-1 text-sm font-semibold">Catégorie
                        <select wire:model="categorie" class="{{ $champ }}">
                            @foreach (\App\Models\Tutoriel::CATEGORIES as $code => $libelle)
                                <option value="{{ $code }}">{{ $libelle }}</option>
                            @endforeach
                        </select>
                        @error('categorie') <span class="text-danger-fg font-normal">{{ $message }}</span> @enderror
                    </label>
                </div>
            </div>
            @unless ($edite)
                <label class="flex items-center gap-2.5 text-sm mx-4 md:mx-5 mb-4 rounded-xl ring-1 ring-border px-3 py-2.5 cursor-pointer has-checked:bg-accent-soft has-checked:ring-accent/25">
                    <input type="checkbox" wire:model="prevenir" class="w-4 h-4 accent-accent shrink-0">
                    <span>Prévenir les commerçants <span class="text-muted">(notification qui ouvre la vidéo ; restaurant, pressing, pharmacie : leurs boutiques seulement)</span></span>
                </label>
            @endunless
            <div class="flex justify-end gap-2 px-4 md:px-5 pb-4 md:pb-5">
                <button type="button" wire:click="$set('formulaire', false)" class="h-11 px-5 rounded-xl ring-1 ring-border-strong font-bold hover:bg-puce">Annuler</button>
                <button type="submit" class="h-11 px-5 rounded-xl bg-accent text-white font-bold shadow-sm hover:bg-accent-dark">Enregistrer</button>
            </div>
        </form>
    @endif

    <section class="bg-white rounded-2xl shadow-carte divide-y divide-separateur">
        @forelse ($tutoriels as $t)
            @php($miniature = $t->versApplication()['miniature'])
            <div class="flex flex-wrap md:flex-nowrap items-center gap-x-4 gap-y-2 px-4 md:px-5 py-3 text-sm first:rounded-t-2xl last:rounded-b-2xl" wire:key="t-{{ $t->id }}">
                <a href="{{ $t->url }}" target="_blank" rel="noopener" class="shrink-0 w-28 h-16 rounded-lg overflow-hidden bg-accent block">
                    @if ($miniature)<img src="{{ $miniature }}" alt="" class="w-full h-full object-cover" loading="lazy">@endif
                </a>
                <div class="flex flex-col min-w-0 flex-1 basis-40">
                    <span class="font-bold truncate">{{ $t->titre }}</span>
                    <span class="text-muted text-xs">{{ \App\Models\Tutoriel::CATEGORIES[$t->categorie] ?? $t->categorie }}@if ($t->sous_titre) · {{ $t->sous_titre }}@endif</span>
                </div>
                <x-plateforme.pastille :ton="$t->actif ? 'succes' : 'neutre'">{{ $t->actif ? 'visible' : 'masquée' }}</x-plateforme.pastille>
                <div class="flex gap-1 ml-auto shrink-0">
                    <button wire:click="editer({{ $t->id }})" class="h-9 px-2.5 rounded-lg text-xs font-bold text-accent hover:bg-accent-soft">Modifier</button>
                    <button wire:click="basculer({{ $t->id }})" class="h-9 px-2.5 rounded-lg text-xs font-bold text-accent hover:bg-accent-soft">{{ $t->actif ? 'Masquer' : 'Montrer' }}</button>
                    <button wire:click="supprimer({{ $t->id }})" wire:confirm="Supprimer « {{ $t->titre }} » ?" class="h-9 px-2.5 rounded-lg text-xs font-bold text-danger-fg hover:bg-danger-bg">Supprimer</button>
                </div>
            </div>
        @empty
            <div class="p-8 text-center text-sm text-muted">Aucune vidéo pour le moment. Ajoutez la première avec son lien YouTube.</div>
        @endforelse
    </section>
</div>
