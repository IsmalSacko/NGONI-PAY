<div class="flex flex-col gap-5" wire:poll.visible.30s>
    {{-- Même habit que l'écran Articles de l'application. --}}
    @php($pharmacie = $this->estPharmacie())
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="flex flex-col gap-1">
            <h1 class="font-display font-extrabold text-2xl md:text-3xl text-accent">{{ $pharmacie ? 'Produits' : 'Articles' }}</h1>
            <span class="text-muted text-sm">Catalogue, prix et TVA de la boutique.</span>
        </div>
        <div class="flex gap-3">
            @can('categories.view')
                <a href="{{ route('categories.index') }}" class="inline-flex items-center gap-2 h-12 px-4 rounded-xl bg-white shadow-carte text-accent font-bold no-underline">
                    <x-charte.icone nom="category" />{{ $pharmacie ? 'Familles' : 'Catégories' }}
                </a>
            @endcan
            @can('produits.create')
                <button wire:click="nouveauProduit" class="inline-flex items-center gap-2 h-12 px-5 rounded-xl bg-accent text-white font-bold shadow-carte">
                    <x-charte.icone nom="add" />{{ $pharmacie ? 'Nouveau produit' : 'Nouvel article' }}
                </button>
            @endcan
        </div>
    </div>

    <x-charte.carte-heros icone="inventory_2" :titre="$pharmacie ? 'Votre catalogue de produits' : 'Votre catalogue'" :valeur="$nArticles.' '.($pharmacie ? 'produit' : 'article').($nArticles > 1 ? 's' : '')">
        @if ($nSansPrixAchat > 0)<span>{{ $nSansPrixAchat }} sans prix d’achat : leur marge n’est pas calculée</span>@endif
        @if ($nSansPhoto > 0)<span class="text-white/80">{{ $nSansPhoto }} sans photo à la caisse</span>@endif
    </x-charte.carte-heros>

    <label class="flex items-center gap-2 h-12 px-4 rounded-2xl bg-white shadow-carte focus-within:ring-2 focus-within:ring-accent">
        <x-charte.icone nom="search" class="text-muted" />
        <input wire:model.live.debounce.300ms="recherche" type="text" placeholder="{{ $pharmacie ? 'Rechercher : nom, molécule, code-barres…' : 'Rechercher : nom, code-barres…' }}" class="flex-1 min-w-0 bg-transparent outline-none">
    </label>

    <x-charte.carte class="overflow-hidden">
        @forelse ($produits as $produit)
            <div class="flex items-center gap-3 px-4 py-3 border-b border-separateur last:border-b-0" wire:key="produit-{{ $produit->id }}">
                @if ($produit->photo_url)
                    <img src="{{ $produit->vignette_url }}" alt="" loading="lazy" class="w-12 h-12 rounded-xl object-contain bg-white shadow-carte shrink-0">
                @else
                    <span class="w-12 h-12 shrink-0 rounded-xl bg-accent-soft text-accent font-extrabold text-sm flex items-center justify-center">{{ mb_strtoupper($produit->code ?: mb_substr($produit->nom, 0, 2)) }}</span>
                @endif
                <div class="flex-1 min-w-0 flex flex-col">
                    <span class="font-bold text-ink truncate">{{ $produit->nom }}@if ($produit->format) <span class="font-normal text-muted">· {{ $produit->format }}</span>@endif</span>
                    <span class="text-xs text-muted truncate">
                        {{ $produit->categorie?->nom ?? ($pharmacie ? 'Sans famille' : 'Sans catégorie') }} · TVA {{ rtrim(rtrim((string) $produit->taux_tva, '0'), '.') ?: '0' }} %
                        @if ($produit->dci) · {{ $produit->dci }}@endif
                        @if ($produit->sur_ordonnance) · sur ordonnance @endif
                    </span>
                </div>
                <div class="flex flex-col items-end gap-1 shrink-0">
                    <span class="font-extrabold text-accent tabular-nums">{{ \App\Support\Money\Montant::format($produit->prix_vente) }}@if ($produit->unite)<span class="font-normal text-muted text-sm"> / {{ \App\Support\Quantite::symbole($produit->unite) }}</span>@endif</span>
                    @php($stock = \App\Support\Quantite::formaterStockCourt($produit->stock, $produit->unite, $produit->paliers))
                    <x-charte.puce :ton="$produit->estEnRupture() ? 'danger' : ($produit->stockFaible() ? 'warn' : 'succes')">{{ $produit->estEnRupture() ? 'Rupture' : $stock }}</x-charte.puce>
                </div>
                <div class="flex gap-1 shrink-0">
                    @can('produits.update')
                        <button wire:click="modifier('{{ $produit->id }}')" title="Modifier" class="w-10 h-10 rounded-xl bg-accent-soft text-accent inline-flex items-center justify-center"><x-charte.icone nom="edit" :taille="20" /></button>
                    @endcan
                    @can('produits.delete')
                        <button wire:click="supprimer('{{ $produit->id }}')" wire:confirm="Supprimer cet article ?" title="Supprimer" class="w-10 h-10 rounded-xl bg-danger-bg text-danger-fg inline-flex items-center justify-center"><x-charte.icone nom="delete" :taille="20" /></button>
                    @endcan
                </div>
            </div>
        @empty
            <x-charte.etat-vide icone="inventory_2" :titre="trim($recherche) === '' ? 'Aucun article pour le moment.' : 'Aucun article ne correspond.'" />
        @endforelse
        <div class="border-t border-separateur">
            <x-charte.pagination :pages="$produits" :mot="$pharmacie ? 'produits' : 'articles'" />
        </div>
    </x-charte.carte>

    @if ($modaleOuverte)
        <div class="fixed inset-0 bg-black/40 flex items-end md:items-center justify-center z-50 md:p-4" wire:click.self="$set('modaleOuverte', false)">
            {{-- Refus du formulaire : on amène le premier message rouge sous les yeux. --}}
            <div class="bg-paper rounded-t-[24px] md:rounded-[24px] p-6 shadow-carte-haute w-full md:max-w-lg max-h-[90vh] overflow-y-auto flex flex-col gap-4"
                 x-data x-on:formulaire-refuse.window="$nextTick(() => { const e = $el.querySelector('[data-erreur]'); e ? e.scrollIntoView({ block: 'center', behavior: 'smooth' }) : $el.scrollTo({ top: 0, behavior: 'smooth' }) })">
                <h2 class="font-display font-extrabold text-xl text-accent">{{ $produitId ? 'Modifier l’article' : 'Nouvel article' }}</h2>

                <form wire:submit="enregistrer" class="flex flex-col gap-3">
                    <div>
                        <label class="block text-sm font-semibold mb-1">Nom</label>
                        <input wire:model="nom" type="text" class="w-full h-11 px-3 rounded-xl bg-white border border-border-strong">
                        @error('nom') <p data-erreur class="text-sm text-danger-fg mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-semibold mb-1">Format</label>
                            <input wire:model="format" type="text" placeholder="Sac 5 kg" class="w-full h-11 px-3 rounded-xl bg-white border border-border-strong">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-1">Catégorie</label>
                            <select wire:model="categorie_produit_id" class="w-full h-11 px-3 rounded-xl bg-white border border-border-strong">
                                <option value="">—</option>
                                @foreach ($categories as $categorie)
                                    <option value="{{ $categorie->id }}">{{ $categorie->nom }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    {{-- Photo : montrée sur la tuile de la caisse. Sur téléphone, le
                         sélecteur propose aussi l'appareil photo. --}}
                    @php($photoActuelle = $produitId && ! $retirerPhoto ? \App\Models\Produit::find($produitId)?->photo_url : null)
                    <div class="rounded-xl border-2 border-dashed border-border-strong p-3 flex items-center gap-4">
                        <label class="relative w-24 h-24 shrink-0 rounded-xl bg-fond-tableau border border-border overflow-hidden flex items-center justify-center cursor-pointer">
                            @if ($photo && str_starts_with((string) $photo->getMimeType(), 'image/'))
                                <img src="{{ $photo->temporaryUrl() }}" alt="Nouvelle photo" class="w-full h-full object-contain bg-white">
                            @elseif ($photoActuelle)
                                <img src="{{ $photoActuelle }}" alt="Photo actuelle" class="w-full h-full object-contain bg-white">
                            @else
                                <x-icone nom="photo" class="w-8 h-8 text-muted" />
                            @endif
                            <span wire:loading.flex wire:target="photo" class="absolute inset-0 bg-white/85 items-center justify-center text-xs font-bold text-accent">Envoi…</span>
                            <input type="file" wire:model="photo" accept="image/*" class="sr-only" aria-label="Photo de l’article">
                        </label>
                        <div class="flex flex-col gap-2 min-w-0">
                            <span class="font-bold text-sm">Photo de l’article</span>
                            <span class="text-xs text-muted">Facultative, affichée dans la caisse. JPG, PNG ou WebP, 3 Mo maximum.</span>
                            <div class="flex flex-wrap gap-2">
                                <label class="inline-flex items-center gap-1.5 h-9 px-3 rounded-xl bg-accent text-white text-xs font-bold cursor-pointer">
                                    <x-icone nom="photo" class="w-4 h-4" />
                                    {{ $photo || $photoActuelle ? 'Changer la photo' : 'Ajouter une photo' }}
                                    <input type="file" wire:model="photo" accept="image/*" class="sr-only">
                                </label>
                                @if ($photo || $photoActuelle)
                                    <button type="button" wire:click="retirerLaPhoto" class="h-9 px-3 rounded-lg border border-border-strong text-xs font-bold text-danger-fg">Retirer</button>
                                @endif
                            </div>
                        </div>
                    </div>
                    @error('photo') <p class="text-sm text-danger-fg">{{ $message }}</p> @enderror
                    @php($devise = \App\Support\Money\Montant::deviseActive())
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-semibold mb-1">Prix d’achat <span class="font-normal text-muted">({{ $devise }}, facultatif)</span></label>
                            <input wire:model.live.debounce.400ms="prix_achat" type="text" inputmode="decimal" class="w-full h-11 px-3 rounded-xl bg-white border border-border-strong">
                            @error('prix_achat') <p data-erreur class="text-sm text-danger-fg mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-1">Prix de vente <span class="font-normal text-muted">({{ $devise }})</span></label>
                            <input wire:model.live.debounce.400ms="prix_vente" type="text" inputmode="decimal" class="w-full h-11 px-3 rounded-xl bg-white border border-border-strong">
                            @error('prix_vente') <p data-erreur class="text-sm text-danger-fg mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    @php($boutiqueGros = \App\Models\Boutique::find($this->boutiqueActiveId()))
                    @if ($boutiqueGros?->venteEnGros())
                        {{-- Vente en gros : un second prix, et dès quelle quantité il s'applique. --}}
                        @if (app(\App\Services\AbonnementService::class)->permet($boutiqueGros, \App\Models\Plan::VENTE_GROS))
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-sm font-semibold mb-1">{{ $boutiqueGros->estPharmacie() ? 'Prix institution' : 'Prix de gros' }} <span class="font-normal text-muted">({{ $devise }}, facultatif)</span></label>
                                    <input wire:model="prix_gros" type="text" inputmode="decimal" class="w-full h-11 px-3 rounded-xl bg-white border border-border-strong">
                                    @error('prix_gros') <p data-erreur class="text-sm text-danger-fg mt-1">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold mb-1">À partir de <span class="font-normal text-muted">(quantité)</span></label>
                                    <input wire:model="seuil_gros" type="text" inputmode="decimal" placeholder="12" class="w-full h-11 px-3 rounded-xl bg-white border border-border-strong">
                                    @error('seuil_gros') <p data-erreur class="text-sm text-danger-fg mt-1">{{ $message }}</p> @enderror
                                </div>
                            </div>
                        @else
                            <p class="rounded-xl bg-accent-soft px-3 py-2 text-sm text-accent">🔒 Prix de gros : fonction de l’offre Pro.</p>
                        @endif
                    @endif
                    @if ($marge)
                        <p class="text-sm font-semibold {{ $marge['perte'] ? 'text-danger-fg' : 'text-accent-dark' }}">{{ $marge['texte'] }}</p>
                    @endif

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-semibold mb-1">TVA %</label>
                            <input wire:model="taux_tva" type="number" min="0" max="100" class="w-full h-11 px-3 rounded-xl bg-white border border-border-strong">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-1">Code</label>
                            <input wire:model="code" type="text" maxlength="4" class="w-full h-11 px-3 rounded-xl bg-white border border-border-strong uppercase">
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold mb-1">Vendu à</label>
                        <select wire:model="unite" class="w-full h-11 px-3 rounded-xl bg-white border border-border-strong bg-white">
                            <option value="">La pièce</option>
                            <optgroup label="Au poids ou à la mesure (viande, riz en vrac, huile, tissu…)">
                                @foreach (\App\Support\Quantite::MESURES as $code => $_)
                                    <option value="{{ $code }}">{{ \App\Support\Quantite::LIBELLES[$code] }}</option>
                                @endforeach
                            </optgroup>
                            <optgroup label="Au conditionnement (riz en sac, eau en carton, médicaments en boîte…)">
                                @foreach (\App\Support\Quantite::CONDITIONNEMENTS as $code => $_)
                                    <option value="{{ $code }}">{{ \App\Support\Quantite::LIBELLES[$code] }}</option>
                                @endforeach
                            </optgroup>
                            @if ($unitesPerso->isNotEmpty())
                                <optgroup label="Vos unités">
                                    @foreach ($unitesPerso as $u)
                                        <option value="{{ $u }}">{{ ucfirst($u) }}</option>
                                    @endforeach
                                </optgroup>
                            @endif
                        </select>
                        {{-- La liste ne suffit pas : on ajoute son unité (« tas », « boule »…). --}}
                        <div class="flex gap-2 mt-2 items-start">
                            <input wire:model="nouvelleUnite" type="text" maxlength="20" placeholder="Autre unité (ex. boule, mesure…)" class="flex-1 min-w-0 h-10 px-3 rounded-xl bg-white border border-border-strong">
                            <button type="button" wire:click="ajouterUnite" class="shrink-0 h-10 px-3 rounded-xl bg-white border border-border-strong font-bold">+ Ajouter</button>
                        </div>
                        @error('nouvelleUnite') <p data-erreur class="text-sm text-danger-fg mt-1">{{ $message }}</p> @enderror
                        <p class="text-xs text-muted mt-1">Le prix, le stock et le seuil se comptent dans cette unité. Le demi (0,5) se vend dans toutes les unités.</p>
                        @error('unite') <p data-erreur class="text-sm text-danger-fg mt-1">{{ $message }}</p> @enderror
                    </div>

                    @php($pharmacie = $this->estPharmacie())
                    @if ($pharmacie)
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-sm font-semibold mb-1">Molécule (DCI)</label>
                                <input wire:model="dci" type="text" placeholder="Paracétamol" class="w-full h-11 px-3 rounded-xl bg-white border border-border-strong">
                            </div>
                            <label class="flex items-center gap-2 mt-6 font-semibold">
                                <input wire:model="sur_ordonnance" type="checkbox" class="w-5 h-5 accent-[--color-nuit]">
                                Sur ordonnance <span class="font-normal text-muted text-sm">(la caisse la demande)</span>
                            </label>
                        </div>
                    @endif

                    {{-- Vente par lot (boutique) ou au détail (pharmacie) : cachée tant qu'on ne l'ouvre pas. --}}
                    <details class="rounded-lg border border-[--color-border] bg-[--color-bg] p-3" @if (count($paliers) > 0 || $pharmacie) open @endif>
                        {{-- Un vrai bouton : libellé court, aide en gris, « + » à droite. --}}
                        <summary class="list-none cursor-pointer flex items-center gap-3 h-12 px-3 rounded-xl bg-white border border-border-strong hover:border-accent">
                            <x-charte.icone nom="inventory_2" class="text-muted" />
                            <span class="flex-1 flex flex-col leading-tight">
                                <span class="font-bold text-accent">{{ $pharmacie ? 'Vente au détail' : 'Vente par lot' }} <span class="font-normal text-muted text-xs">(facultatif)</span></span>
                                <span class="text-xs text-muted">{{ $pharmacie ? 'Boîte, plaquette… avec son propre prix' : 'Carton, paquet… avec son propre prix' }}</span>
                            </span>
                            <x-charte.icone nom="add_circle" class="text-accent" />
                        </summary>
                        <p class="text-xs text-muted mt-1">L'article est l'unité de base ({{ $unite ? \App\Support\Quantite::LIBELLES[$unite] ?? $unite : 'la pièce' }}) : son prix et son stock se comptent ainsi. Chaque conditionnement dit combien il en contient, et son prix.</p>
                        @foreach ($paliers as $i => $p)
                            {{-- Une rangée par conditionnement : contenant | contient | prix | retirer. --}}
                            <div class="flex gap-2 mt-2 items-start" wire:key="palier-{{ $i }}">
                                <select wire:model="paliers.{{ $i }}.unite" class="flex-1 min-w-0 h-10 px-2 rounded-xl bg-white border border-border-strong bg-white">
                                    @foreach (\App\Support\Quantite::CONDITIONNEMENTS as $code => $_)
                                        <option value="{{ $code }}">{{ \App\Support\Quantite::LIBELLES[$code] }}</option>
                                    @endforeach
                                    @foreach ($unitesPerso as $u)
                                        <option value="{{ $u }}">{{ ucfirst($u) }}</option>
                                    @endforeach
                                </select>
                                <input wire:model.live.debounce.500ms="paliers.{{ $i }}.contenance" type="text" inputmode="numeric" placeholder="Contient…" title="Combien d’unités il contient" class="flex-1 min-w-0 h-10 px-2 rounded-xl bg-white border border-border-strong">
                                <input wire:model.live.debounce.500ms="paliers.{{ $i }}.prix" type="text" inputmode="decimal" placeholder="Prix ({{ $devise }})" class="flex-1 min-w-0 h-10 px-2 rounded-xl bg-white border border-border-strong">
                                <button type="button" wire:click="retirerPalier({{ $i }})" class="shrink-0 w-10 h-10 text-muted" title="Retirer">✕</button>
                            </div>
                            {{-- Un lot bien moins cher à l'unité que le prix de base : sans doute une faute de frappe. --}}
                            @php($c = (int) ($p['contenance'] ?? 0))
                            @php($px = \App\Support\Money\Montant::parse((string) ($p['prix'] ?? '')))
                            @php($base = \App\Support\Money\Montant::parse($prix_vente))
                            @if ($c >= 2 && $px !== null && $base && $px * 2 < $base * $c)
                                <p class="text-sm font-bold text-warn-fg mt-1">Attention : 1 {{ \App\Support\Quantite::symbole($p['unite']) ?? $p['unite'] }} à {{ \App\Support\Money\Montant::format($px) }} revient à {{ \App\Support\Money\Montant::format((int) round($px / $c)) }} par {{ $unite ? \App\Support\Quantite::symbole($unite) : 'pièce' }}, contre {{ \App\Support\Money\Montant::format($base) }} à l’unité.</p>
                            @endif
                            @foreach (['unite', 'contenance', 'prix'] as $champ)
                                @error("paliers.$i.$champ") <p data-erreur class="text-sm text-danger-fg mt-1">{{ $message }}</p> @enderror
                            @endforeach
                        @endforeach
                        @if (count($paliers) < 3)
                            <button type="button" wire:click="ajouterPalier" class="mt-2 text-sm font-bold text-[--color-nuit]">+ Ajouter un conditionnement</button>
                        @endif
                    </details>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div>
                            <label class="block text-sm font-semibold mb-1">Code-barres</label>
                            <input wire:model="code_barre" type="text" class="w-full h-11 px-3 rounded-xl bg-white border border-border-strong">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-1">Stock</label>
                            <input wire:model="stock" type="text" inputmode="decimal" class="w-full h-11 px-3 rounded-xl bg-white border border-border-strong">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-1">Seuil d'alerte</label>
                            <input wire:model="seuil_alerte" type="text" inputmode="decimal" class="w-full h-11 px-3 rounded-xl bg-white border border-border-strong">
                        </div>
                    </div>

                    @if ($pharmacie && ! $produitId)
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-sm font-semibold mb-1">N° de lot <span class="font-normal text-muted">(facultatif)</span></label>
                                <input wire:model="numero_lot" type="text" class="w-full h-11 px-3 rounded-xl bg-white border border-border-strong">
                            </div>
                            <div>
                                <label class="block text-sm font-semibold mb-1">Date de péremption</label>
                                <input wire:model="peremption" type="date" class="w-full h-11 px-3 rounded-xl bg-white border border-border-strong">
                                @error('peremption') <p data-erreur class="text-sm text-danger-fg mt-1">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    @endif

                    @if ($errors->any())
                        {{-- Près du bouton : on voit tout de suite pourquoi rien n'est enregistré. --}}
                        <div class="rounded-lg bg-danger-bg text-danger-fg text-sm font-semibold px-3 py-2">
                            Rien n’est enregistré : {{ $errors->first() }}@if ($errors->count() > 1) (et {{ $errors->count() - 1 }} autre{{ $errors->count() > 2 ? 's' : '' }} point{{ $errors->count() > 2 ? 's' : '' }} à corriger)@endif
                        </div>
                    @endif

                    <div class="flex gap-3 mt-2">
                        <button type="button" wire:click="$set('modaleOuverte', false)" class="flex-1 h-11 rounded-xl bg-white border border-border-strong font-bold">Annuler</button>
                        <button type="submit" wire:loading.attr="disabled" wire:target="photo,enregistrer" class="flex-1 h-11 rounded-xl bg-accent text-white font-bold disabled:opacity-60">Enregistrer</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
