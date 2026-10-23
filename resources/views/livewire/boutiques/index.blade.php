<div class="flex flex-col gap-5">
    {{-- Même habit que les réglages de l'application : carte héros, cartes blanches, pastilles. --}}
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="flex flex-col gap-1">
            <h1 class="font-display font-extrabold text-2xl md:text-3xl text-accent">Boutiques</h1>
            <span class="text-muted text-sm">
                Vous possédez {{ $possedees }} boutique{{ $possedees > 1 ? 's' : '' }}{{ $maxBoutiques ? " sur {$maxBoutiques} permise".($maxBoutiques > 1 ? 's' : '').' par votre plan' : '' }}.
            </span>
        </div>
        @if ($peutOuvrir)
            <button wire:click="nouvelle" class="inline-flex items-center gap-2 h-12 px-5 rounded-xl bg-accent text-white font-bold shadow-carte">
                <x-charte.icone nom="add_business" />Nouvelle boutique
            </button>
        @endif
    </div>

    @if (session('info'))
        <p class="rounded-2xl bg-succes-doux text-succes-fonce px-4 py-3 text-sm font-bold">{{ session('info') }}</p>
    @endif
    @if ($alerte)
        <p class="rounded-2xl bg-danger-bg text-danger-fg px-4 py-3 text-sm font-semibold">
            {{ $alerte }} Le plan Pro permet jusqu’à 5 boutiques : abonnez-vous depuis l’application Ngoni Caisse.
        </p>
    @elseif ($peutOuvrir && ! $peutCreer)
        <p class="rounded-2xl bg-warn-bg text-warn-fg px-4 py-3 text-sm">Votre plan ne permet pas d’autre boutique. Le plan Pro en permet jusqu’à 5.</p>
    @endif

    @if ($boutiqueActive)
        <x-charte.carte-heros icone="storefront" titre="Boutique active" :valeur="$boutiqueActive->nom">
            <span class="text-white/90">{{ \App\Enums\Country::tryFrom((string) $boutiqueActive->pays)?->label() }} · devise {{ $boutiqueActive->devise }}</span>
            <span>{{ match ($boutiqueActive->activite) { 'pharmacie' => 'Pharmacie', 'pressing' => 'Pressing', 'restaurant' => 'Restaurant', default => 'Commerce' } }}@if ($boutiqueActive->fidelite_seuil) · fidélité : {{ $boutiqueActive->fidelite_remise_pct }} % après {{ $boutiqueActive->fidelite_seuil }} achats @endif</span>
            @if ($peutRegler)
                <x-slot:action>
                    <button wire:click="ouvrirReglages" class="inline-flex items-center gap-1.5 h-10 px-4 rounded-xl bg-jaune text-accent text-sm font-extrabold">
                        <x-charte.icone nom="tune" :taille="18" />Réglages
                    </button>
                </x-slot:action>
            @endif
        </x-charte.carte-heros>
    @endif

    @if ($boutiqueActive && $peutRegler)
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            {{-- Activité : la caisse s'adapte au métier. --}}
            <x-charte.carte class="p-5 flex flex-col gap-3">
                <x-charte.en-tete-section icone="work" titre="Activité" sous-titre="La caisse s’adapte à votre métier." />
                @foreach (['commerce' => ['storefront', 'Commerce', 'Boutique, épicerie, quincaillerie… La caisse telle quelle.'], 'pharmacie' => ['local_pharmacy', 'Pharmacie', 'Vente à la boîte, à la plaquette ou au comprimé, lots et péremption, ordonnance, recherche par molécule (DCI).'], 'pressing' => ['local_laundry_service', 'Pressing', 'Repassage, lavage, nettoyage à sec… La caisse parle de vêtements et de prestations, sans stock à compter.'], 'restaurant' => ['restaurant', 'Restaurant', 'Commandes à table, à emporter, par téléphone ou en livraison ; cuisine, addition, réservations, ingrédients.']] as $code => [$icone, $titre, $texte])
                    @php($choisie = ($boutiqueActive->activite ?: 'commerce') === $code)
                    <button wire:click="choisirActivite('{{ $code }}')" class="flex items-start gap-3 text-left rounded-2xl p-3 border-2 {{ $choisie ? 'bg-jaune-doux border-jaune' : 'bg-white border-border hover:border-border-strong' }}">
                        <x-charte.pastille :icone="$icone" :actif="$choisie" :taille="40" />
                        <span class="flex-1 flex flex-col">
                            <span class="font-extrabold text-accent">{{ $titre }}</span>
                            <span class="text-sm text-muted">{{ $texte }}</span>
                        </span>
                        @if ($choisie)<x-charte.icone nom="check_circle" plein class="text-accent" />@endif
                    </button>
                @endforeach
            </x-charte.carte>

            {{-- Vos ventes : détail, détail et gros, gros uniquement. --}}
            <x-charte.carte class="p-5 flex flex-col gap-3">
                <x-charte.en-tete-section icone="local_shipping" titre="Vos ventes" sous-titre="Au détail, en gros, ou les deux." />
                @foreach (['detail' => ['person', 'Au détail', 'Un prix par article. La caisse telle quelle.'], 'detail_gros' => ['local_shipping', 'Au détail et en gros', 'Un prix de gros en plus : dès une quantité, pour vos revendeurs, ou d’un toucher à la caisse.'], 'gros' => ['warehouse', 'En gros uniquement', 'Un seul prix par article : votre prix de gros.']] as $code => [$icone, $titre, $texte])
                    @php($choisie = ($boutiqueActive->mode_vente ?: 'detail') === $code)
                    <button wire:click="choisirModeVente('{{ $code }}')" class="flex items-start gap-3 text-left rounded-2xl p-3 border-2 {{ $choisie ? 'bg-jaune-doux border-jaune' : 'bg-white border-border hover:border-border-strong' }}">
                        <x-charte.pastille :icone="$icone" :actif="$choisie" :taille="40" />
                        <span class="flex-1 flex flex-col">
                            <span class="font-extrabold text-accent">{{ $titre }}@if ($code === 'detail_gros' && ! app(\App\Services\AbonnementService::class)->permet($boutiqueActive, \App\Models\Plan::VENTE_GROS)) <span class="text-xs font-bold">🔒 Pro</span>@endif</span>
                            <span class="text-sm text-muted">{{ $texte }}</span>
                        </span>
                        @if ($choisie)<x-charte.icone nom="check_circle" plein class="text-accent" />@endif
                    </button>
                @endforeach
                @if ($boutiqueActive->venteEnGros())
                    <label class="flex items-center justify-between gap-3 rounded-2xl bg-paper px-4 py-3 cursor-pointer">
                        <span class="font-bold text-accent">Chaque vente commence en gros</span>
                        <input type="checkbox" wire:click="basculerCommenceEnGros" @checked($boutiqueActive->vente_commence_en_gros) class="w-6 h-6 accent-[#0f2a5c]">
                    </label>
                @endif
            </x-charte.carte>

            {{-- Fidélité : enregistrée d'elle-même, comme dans l'application. --}}
            <x-charte.carte class="p-5 flex flex-col gap-3">
                <x-charte.en-tete-section icone="loyalty" titre="Fidélité des clients" sous-titre="Une remise automatique pour vos clients réguliers." />
                @if ($fideliteIncluse)
                    <label class="flex items-center justify-between gap-3 rounded-2xl bg-paper px-4 py-3 cursor-pointer">
                        <span class="font-bold text-accent">Programme {{ $fideliteActive ? 'en cours' : 'arrêté' }}</span>
                        <input type="checkbox" wire:model.live="fideliteActive" class="w-6 h-6 accent-[#0f2a5c]">
                    </label>
                    @if ($fideliteActive)
                        <div class="grid grid-cols-2 gap-3">
                            <label class="text-sm font-semibold">Au bout de (achats)
                                <input wire:model.live.debounce.700ms="fideliteSeuil" type="text" inputmode="numeric" class="mt-1 w-full h-11 px-3 rounded-xl bg-white border border-border-strong font-normal">
                                @error('fideliteSeuil') <span class="block text-danger-fg text-xs">{{ $message }}</span> @enderror
                            </label>
                            <label class="text-sm font-semibold">Remise (%)
                                <input wire:model.live.debounce.700ms="fidelitePct" type="text" inputmode="numeric" class="mt-1 w-full h-11 px-3 rounded-xl bg-white border border-border-strong font-normal">
                                @error('fidelitePct') <span class="block text-danger-fg text-xs">{{ $message }}</span> @enderror
                            </label>
                        </div>
                        <p class="rounded-xl bg-jaune-doux px-3 py-2 text-sm text-ink">Après {{ $fideliteSeuil }} achats, le suivant a {{ $fidelitePct }} % de remise. Le compte repart ensuite à zéro.</p>
                    @endif
                    <span wire:loading.remove wire:target="fideliteActive,fideliteSeuil,fidelitePct" class="text-sm font-bold text-succes-fonce {{ $fideliteEnregistree ? '' : 'invisible' }}">✓ Enregistré : la caisse l’applique</span>
                    <span wire:loading wire:target="fideliteActive,fideliteSeuil,fidelitePct" class="text-sm text-muted">Enregistrement…</span>
                @else
                    <p class="rounded-xl bg-accent-soft px-3 py-2 text-sm text-accent">Fonction de l’offre Pro : récompensez vos clients fidèles par une remise automatique.</p>
                @endif
            </x-charte.carte>

            {{-- Logo du ticket, et repartir de zéro. --}}
            <x-charte.carte class="p-5 flex flex-col gap-3">
                <x-charte.en-tete-section icone="receipt_long" titre="Ticket et logo" sous-titre="Le logo s’imprime en tête des tickets et des factures." />
                <div class="flex items-center gap-3 flex-wrap">
                    @if ($boutiqueActive->logo_url)
                        <img src="{{ $boutiqueActive->logo_vignette_url }}" alt="Logo" class="w-14 h-14 rounded-xl object-contain bg-white shadow-carte">
                    @endif
                    <label class="inline-flex items-center gap-1.5 h-10 px-4 rounded-xl bg-accent-soft text-accent text-sm font-bold cursor-pointer">
                        <x-charte.icone nom="add_photo_alternate" :taille="18" />{{ $boutiqueActive->logo_url ? 'Changer le logo' : 'Ajouter un logo' }}
                        <input type="file" wire:model="logo" accept="image/png,image/jpeg,image/webp" class="hidden">
                    </label>
                    @if ($logo)
                        <button wire:click="envoyerLogo" class="h-10 px-4 rounded-xl bg-accent text-white text-sm font-bold">Enregistrer le logo</button>
                    @elseif ($boutiqueActive->logo_url)
                        <button wire:click="supprimerLogo" wire:confirm="Retirer le logo ?" class="h-10 px-3 text-sm text-danger-fg font-bold">Retirer</button>
                    @endif
                </div>
                @error('logo') <span class="text-xs text-danger-fg">{{ $message }}</span> @enderror
                <button wire:click="ouvrirReglages" class="self-start inline-flex items-center gap-1.5 h-10 px-4 rounded-xl bg-white shadow-carte text-accent text-sm font-bold">
                    <x-charte.icone nom="edit" :taille="18" />Nom, pays, devise, NIF, message du ticket…
                </button>
            </x-charte.carte>

            @if (\App\Services\ReinitialisationBoutique::autorise(auth()->user(), $boutiqueActive))
                <x-charte.carte class="p-5 flex flex-col gap-3">
                    <x-charte.en-tete-section icone="restart_alt" titre="Repartir de zéro" sous-titre="Vos ventes jusqu’ici n’étaient que des essais ?" />
                    <button wire:click="preparerReinitialisation('{{ $boutiqueActive->id }}')"
                            class="self-start h-10 px-4 rounded-xl bg-danger-bg text-danger-fg text-sm font-bold">Réinitialiser la boutique</button>
                </x-charte.carte>
            @endif
        </div>
    @endif

    @if ($boutiques->count() > 1)
        <x-charte.en-tete-section icone="store" titre="Vos boutiques" sous-titre="Choisissez celle où vous travaillez." />
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach ($boutiques as $b)
                <x-charte.carte class="p-4 flex items-start gap-3 {{ $b->id === $active ? 'ring-2 ring-jaune' : '' }}" wire:key="boutique-{{ $b->id }}">
                    <x-charte.pastille icone="storefront" :actif="$b->id === $active" :taille="40" />
                    <div class="flex-1 min-w-0 flex flex-col gap-1">
                        <span class="font-display font-extrabold text-accent truncate">{{ $b->nom }}</span>
                        <span class="text-xs text-muted">{{ \App\Enums\Country::tryFrom((string) $b->pays)?->label() }} · {{ $b->devise }} · {{ $b->proprietaire_id === auth()->id() ? 'propriétaire' : 'équipe' }}</span>
                        @if ($b->id === $active)
                            <x-charte.puce ton="jaune" icone="check" class="self-start">Active</x-charte.puce>
                        @elseif (in_array($b->id, auth()->user()->boutiquesBackOffice(), true))
                            <form method="POST" action="{{ route('boutique-active') }}">
                                @csrf
                                <input type="hidden" name="boutique" value="{{ $b->id }}">
                                <button class="mt-1 h-9 px-3 rounded-xl bg-accent-soft text-accent text-xs font-bold">Travailler dans cette boutique</button>
                            </form>
                        @endif
                    </div>
                </x-charte.carte>
            @endforeach
        </div>
    @endif

    @if ($modaleOuverte)
        <div class="fixed inset-0 bg-black/40 flex items-end md:items-center justify-center z-50 md:p-4" wire:click.self="$set('modaleOuverte', false)">
            <div class="bg-white rounded-t-2xl md:rounded-2xl p-6 w-full md:max-w-md max-h-[90vh] overflow-y-auto flex flex-col gap-4">
                <h2 class="font-display font-extrabold text-xl">Nouvelle boutique</h2>
                <form wire:submit="creer" class="flex flex-col gap-3">
                    <div>
                        <label class="block text-sm font-semibold mb-1">Nom de la boutique</label>
                        <input wire:model="nom" type="text" class="w-full h-11 px-3 rounded-lg border border-[--color-border-strong]">
                        @error('nom') <p class="text-sm text-danger-fg mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-semibold mb-1">Pays <span class="font-normal text-[--color-muted]">(fixe la devise)</span></label>
                        <x-choix-pays :liste-pays="$listePays" />
                    </div>
                    <div>
                        <label class="block text-sm font-semibold mb-1">Téléphone de la boutique <span class="font-normal text-[--color-muted]">(facultatif)</span></label>
                        <input wire:model="telephone" type="tel" class="w-full h-11 px-3 rounded-lg border border-[--color-border-strong]">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold mb-1">Adresse <span class="font-normal text-[--color-muted]">(facultatif)</span></label>
                        <input wire:model="adresse" type="text" class="w-full h-11 px-3 rounded-lg border border-[--color-border-strong]">
                    </div>
                    <div class="flex gap-3 mt-2">
                        <button type="button" wire:click="$set('modaleOuverte', false)" class="flex-1 h-11 rounded-lg border border-[--color-border-strong] font-bold">Annuler</button>
                        <button type="submit" class="flex-1 h-11 rounded-lg bg-accent text-white font-bold">Ouvrir la boutique</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    @if ($reglagesOuverts)
        <div class="fixed inset-0 bg-black/40 flex items-end md:items-center justify-center z-50 md:p-4" wire:click.self="$set('reglagesOuverts', false)">
            <div class="bg-white rounded-t-2xl md:rounded-2xl p-6 w-full md:max-w-md max-h-[90vh] overflow-y-auto flex flex-col gap-4">
                <h2 class="font-display font-extrabold text-xl">Réglages de la boutique</h2>
                <form wire:submit="enregistrerReglages" class="flex flex-col gap-3">
                    <div>
                        <label class="block text-sm font-semibold mb-1">Nom</label>
                        <input wire:model="reglages.nom" type="text" class="w-full h-11 px-3 rounded-lg border border-[--color-border-strong]">
                        @error('reglages.nom') <p class="text-sm text-danger-fg mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-semibold mb-1">Pays de la boutique</label>
                        <x-choix-pays model="reglages.pays" :liste-pays="$listePays" />
                    </div>
                    <div>
                        <label class="block text-sm font-semibold mb-1">Devise</label>
                        <select wire:model="reglages.devise" class="w-full h-11 px-3 rounded-lg border border-[--color-border-strong] bg-white">
                            @foreach ($devises as $code)
                                <option value="{{ $code }}">{{ $code }}</option>
                            @endforeach
                        </select>
                        @error('reglages.devise') <p class="text-sm text-danger-fg mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div class="rounded-xl bg-fond-tableau p-3 text-sm flex flex-col gap-2">
                        <span class="font-semibold">Si la devise change (actuellement {{ $deviseInitiale }})</span>
                        <label class="flex items-start gap-2">
                            <input type="checkbox" wire:model="reglages.convertir" class="mt-1">
                            <span>Convertir les prix et les ventes. Franc CFA ↔ euro : au taux fixe 1 € = 655,957 F, automatiquement.</span>
                        </label>
                        <label class="block">
                            <span class="text-[--color-muted]">Autre devise : combien de {{ $deviseInitiale }} vaut 1 unité de la nouvelle devise ?</span>
                            <input wire:model="reglages.taux" type="text" inputmode="decimal" placeholder="ex. 655,957" class="mt-1 w-full h-10 px-3 rounded-lg border border-[--color-border-strong]">
                        </label>
                        @error('reglages.taux') <p class="text-sm text-danger-fg">{{ $message }}</p> @enderror
                        <span class="text-xs text-[--color-muted]">Décoché : les montants gardent les mêmes chiffres (300 F → 300,00 €).</span>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold mb-1">Téléphone (imprimé sur le ticket)</label>
                        <input wire:model="reglages.telephone" type="tel" class="w-full h-11 px-3 rounded-lg border border-[--color-border-strong]">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold mb-1">Adresse (imprimée sur le ticket)</label>
                        <input wire:model="reglages.adresse" type="text" class="w-full h-11 px-3 rounded-lg border border-[--color-border-strong]">
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-semibold mb-1">NIF <span class="font-normal text-[--color-muted]">(sur le ticket)</span></label>
                            <input wire:model="reglages.identifiant_fiscal" type="text" maxlength="60" class="w-full h-11 px-3 rounded-lg border border-[--color-border-strong]">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-1">RCCM <span class="font-normal text-[--color-muted]">(sur le ticket)</span></label>
                            <input wire:model="reglages.rccm" type="text" maxlength="60" class="w-full h-11 px-3 rounded-lg border border-[--color-border-strong]">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold mb-1">Message en bas du ticket</label>
                        <input wire:model="reglages.message_ticket" type="text" maxlength="160" placeholder="Merci de votre visite ! Les articles vendus ne sont ni repris ni échangés." class="w-full h-11 px-3 rounded-lg border border-[--color-border-strong]">
                    </div>
                    {{-- La facture, comme l'écran Ma boutique de l'application. --}}
                    <fieldset class="rounded-xl border border-[--color-border-strong] p-3 flex flex-col gap-3">
                        <legend class="px-1 text-sm font-extrabold">Modèle de facture</legend>
                        <div class="grid grid-cols-2 gap-2">
                            @foreach (['standard' => 'Standard', 'distributeur' => 'Distributeur'] as $code => $lib)
                                <label class="h-10 rounded-lg border flex items-center justify-center font-bold cursor-pointer {{ ($reglages['modele_facture'] ?? 'standard') === $code ? 'bg-accent text-white border-accent' : 'border-[--color-border-strong]' }}">
                                    <input type="radio" wire:model.live="reglages.modele_facture" value="{{ $code }}" class="sr-only">{{ $lib }}
                                </label>
                            @endforeach
                        </div>
                        {{-- « Ma facture » : la facture déjà utilisée, lue pour préremplir. --}}
                        @if (($quotaFacture['par_mois'] ?? 0) > 0)
                            <div class="rounded-lg bg-[--color-accent-soft] p-3 flex flex-col gap-2">
                                <span class="text-sm font-semibold">D’après ma facture</span>
                                <span class="text-xs text-[--color-muted]">Envoyez une photo ou un PDF de la facture que vous utilisez déjà. Ngoni la lit, remplit vos mentions (adresse, boîte postale, identifiant fiscal, RCCM, taux…) et choisit le modèle le plus proche. Vous vérifiez avant d’enregistrer.</span>
                                <label class="inline-flex items-center gap-2 self-start h-10 px-4 rounded-lg bg-white border border-[--color-border-strong] font-bold text-sm cursor-pointer">
                                    <x-charte.icone nom="upload_file" :taille="18" /><span wire:loading.remove wire:target="facture">Importer ma facture</span><span wire:loading wire:target="facture">Lecture de la facture…</span>
                                    <input type="file" wire:model="facture" accept="image/*,application/pdf" class="sr-only">
                                </label>
                                <span class="text-xs text-[--color-muted]">{{ ($quotaFacture['restantes'] ?? 0) > 0 ? ($quotaFacture['restantes'].' import'.($quotaFacture['restantes'] > 1 ? 's' : '').' restant'.($quotaFacture['restantes'] > 1 ? 's' : '').' ce mois-ci.') : 'Plus d’import ce mois-ci.' }}</span>
                                @error('facture') <p class="text-sm text-danger-fg">{{ $message }}</p> @enderror
                                @if ($factureLue)
                                    <div class="rounded-lg bg-white p-3 text-sm">
                                        <p class="font-extrabold">Lu sur votre facture : modèle {{ ($factureLue['modele'] ?? '') === 'distributeur' ? 'Distributeur' : 'Standard' }}</p>
                                        @if (! empty($factureLue['mentions']))<p class="text-[--color-muted]">{{ implode(' · ', $factureLue['mentions']) }}</p>@endif
                                        @if (($factureLue['tva_taux'] ?? null) !== null)<p class="text-[--color-muted]">TVA {{ str_replace('.', ',', (string) (0 + (float) $factureLue['tva_taux'])) }} % : elle se règle sur chaque article.</p>@endif
                                        <p class="text-xs text-[--color-muted] mt-1">Vérifiez les champs ci-dessous, puis Enregistrer.</p>
                                    </div>
                                @endif
                            </div>
                        @endif
                        @if (($reglages['modele_facture'] ?? 'standard') === 'distributeur')
                            <p class="text-xs text-[--color-muted]">Pour les grossistes et distributeurs : code des articles et des clients, identifiant fiscal du client, PSA et frais par article.</p>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <label class="block text-sm font-semibold">Boîte postale <span class="font-normal text-[--color-muted]">(facultatif)</span>
                                    <input wire:model="reglages.boite_postale" type="text" maxlength="60" placeholder="BP 1234 Douala" class="mt-1 w-full h-10 px-3 rounded-lg border border-[--color-border-strong] font-normal">
                                </label>
                                <label class="block text-sm font-semibold">PSA / précompte en % <span class="font-normal text-[--color-muted]">(facultatif)</span>
                                    <input wire:model="reglages.psa_taux" type="text" inputmode="decimal" placeholder="Ex. 2,5" class="mt-1 w-full h-10 px-3 rounded-lg border border-[--color-border-strong] font-normal">
                                </label>
                            </div>
                            @error('reglages.psa_taux') <p class="text-sm text-danger-fg">{{ $message }}</p> @enderror
                            <p class="text-xs text-[--color-muted] -mt-2">Le PSA est compris dans vos prix, comme la TVA. Vide : pas de précompte.</p>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <label class="block text-sm font-semibold">Titre du bloc des articles
                                    <input wire:model="reglages.libelle_marchandises" type="text" maxlength="40" placeholder="Marchandises" class="mt-1 w-full h-10 px-3 rounded-lg border border-[--color-border-strong] font-normal">
                                </label>
                                <label class="block text-sm font-semibold">Nom des frais par article
                                    <input wire:model="reglages.libelle_frais" type="text" maxlength="40" placeholder="Frais d’enlèvement" class="mt-1 w-full h-10 px-3 rounded-lg border border-[--color-border-strong] font-normal">
                                </label>
                            </div>
                        @endif
                    </fieldset>
                    <fieldset class="rounded-xl border border-[--color-border-strong] p-3 flex flex-col gap-3">
                        <legend class="px-1 text-sm font-extrabold">Numéro des factures</legend>
                        @if ($prochaineFacture !== '')
                            <p class="text-sm">Prochaine facture : <span class="font-extrabold tabular-nums">{{ $prochaineFacture }}</span></p>
                        @endif
                        <div class="grid grid-cols-2 gap-3">
                            <label class="block text-sm font-semibold">Préfixe
                                <input wire:model="reglages.facture_prefixe" type="text" maxlength="10" placeholder="auto (initiales)" class="mt-1 w-full h-10 px-3 rounded-lg border border-[--color-border-strong] font-normal uppercase">
                            </label>
                            <label class="block text-sm font-semibold">Suffixe
                                <input wire:model="reglages.facture_suffixe" type="text" maxlength="10" placeholder="aucun" class="mt-1 w-full h-10 px-3 rounded-lg border border-[--color-border-strong] font-normal uppercase">
                            </label>
                        </div>
                        @error('reglages.facture_prefixe') <p class="text-sm text-danger-fg">{{ $message }}</p> @enderror
                        @error('reglages.facture_suffixe') <p class="text-sm text-danger-fg">{{ $message }}</p> @enderror
                        <label class="flex items-start gap-2 text-sm">
                            <input type="checkbox" wire:model.live="reglages.facture_annee" class="mt-1">
                            <span><span class="font-semibold">Mettre l’année dans le numéro</span><br>
                                <span class="text-[--color-muted]">{{ ($reglages['facture_annee'] ?? true) ? 'Le numéro repart à 1 chaque 1er janvier.' : 'Le numéro continue d’année en année.' }}</span></span>
                        </label>
                        <label class="block text-sm font-semibold">Prochain numéro <span class="font-normal text-[--color-muted]">(facultatif)</span>
                            <input wire:model="reglages.facture_prochain_numero" type="text" inputmode="numeric" placeholder="ex. 457, pour suivre votre carnet" class="mt-1 w-full h-10 px-3 rounded-lg border border-[--color-border-strong] font-normal">
                        </label>
                        @error('reglages.facture_prochain_numero') <p class="text-sm text-danger-fg">{{ $message }}</p> @enderror
                        <p class="text-xs text-[--color-muted]">Un numéro déjà donné à un client ne resert jamais. Pour repartir à 1, changez de préfixe : une nouvelle série commence.</p>
                    </fieldset>
                    <div>
                        <label class="block text-sm font-semibold mb-1">E-mail</label>
                        <input wire:model="reglages.email" type="email" class="w-full h-11 px-3 rounded-lg border border-[--color-border-strong]">
                        @error('reglages.email') <p class="text-sm text-danger-fg mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex gap-3 mt-2">
                        <button type="button" wire:click="$set('reglagesOuverts', false)" class="flex-1 h-11 rounded-lg border border-[--color-border-strong] font-bold">Annuler</button>
                        <button type="submit" class="flex-1 h-11 rounded-lg bg-accent text-white font-bold">Enregistrer</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
    @include('livewire.partials.reinitialisation')
</div>
