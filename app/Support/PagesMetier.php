<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pages de la vitrine par métier et par pays (« logiciel de caisse pour
 * pharmacie au Sénégal ») : ce que les commerçants tapent dans Google. Chaque
 * page dit ce que l'application fait vraiment pour ce métier ; le pays y ajoute
 * sa monnaie, ses moyens de paiement et sa grande ville. Une entrée ajoutée
 * ici devient une page, et entre seule dans le plan du site.
 */
final class PagesMetier
{
    /**
     * @var array<string, array{nom: string, pour: string, titre: string, accroche: string, points: list<array{0: string, 1: string}>, questions: list<array{0: string, 1: string}>}>
     */
    public const METIERS = [
        'boutique' => [
            'nom' => 'Boutique',
            'pour' => 'une boutique ou une alimentation',
            'titre' => 'Logiciel de caisse pour boutique et alimentation',
            'accroche' => 'Encaissez en touchant vos articles, suivez votre stock et vos crédits clients, imprimez vos tickets : votre boutique tient dans votre téléphone.',
            'points' => [
                ['Caisse tactile et code-barres', 'Touchez l’article ou scannez son code-barres avec l’appareil photo : le total se calcule tout seul, la monnaie à rendre aussi.'],
                ['Stock à jour à chaque vente', 'Le stock baisse à chaque vente, une alerte prévient avant la rupture, l’inventaire se fait depuis le téléphone.'],
                ['Crédit client et fidélité', 'Ce que chaque client doit, ce qu’il a payé, un plafond de crédit, et une remise automatique pour vos fidèles.'],
                ['Au détail et en gros', 'Un prix de détail, un prix de gros, et la vente au carton, au sac ou au casier comme à la pièce.'],
                ['Tickets et reçus', 'Ticket sur imprimante Bluetooth de 58 ou 80 mm, ou reçu PDF envoyé au client par WhatsApp.'],
                ['Même sans réseau', 'Une coupure d’internet n’arrête pas la caisse : les ventes partent dès le retour de la connexion.'],
            ],
            'questions' => [
                ['Faut-il un ordinateur ?', 'Non : un téléphone Android suffit. Le back-office web et les versions Windows et macOS sont là pour ceux qui préfèrent un grand écran.'],
                ['Puis-je donner la caisse à un employé ?', 'Oui : chaque membre de l’équipe a son compte. Le caissier encaisse, le gérant gère le stock, et vous voyez tout.'],
            ],
        ],
        'pharmacie' => [
            'nom' => 'Pharmacie',
            'pour' => 'une pharmacie ou un dépôt pharmaceutique',
            'titre' => 'Logiciel de gestion pour pharmacie',
            'accroche' => 'Recherche par molécule, vente à la boîte ou à la plaquette, lots et dates de péremption, ordonnances : une caisse pensée pour l’officine.',
            'points' => [
                ['Recherche par molécule (DCI)', 'Tapez « paracétamol » : tous les produits qui en contiennent s’affichent, pour proposer un équivalent au patient.'],
                ['Boîte, plaquette ou comprimé', 'Chaque produit se vend à l’unité de votre choix, au bon prix, et le stock se compte au comprimé près.'],
                ['Lots et péremption', 'Chaque lot garde sa date : les produits périmés ou bientôt périmés vous sont signalés avant qu’il soit trop tard.'],
                ['Ordonnances', 'Le prescripteur, le numéro et le patient sont relevés avec la vente et figurent sur le ticket.'],
                ['Fournisseurs et achats', 'Commandes aux grossistes, réceptions, prix d’achat et marge par produit.'],
                ['Plusieurs officines', 'Une pharmacie et une annexe, chacune avec son stock et son équipe, depuis le même compte.'],
            ],
            'questions' => [
                ['Les cliniques et centres de santé ont-ils leur prix ?', 'Oui : un client peut être marqué « institution » et payer d’office le prix de gros.'],
                ['Le stock suit-il les ventes à la plaquette ?', 'Oui : une plaquette vendue retire ses comprimés du stock, et une boîte entamée reste comptée juste.'],
            ],
        ],
        'restaurant' => [
            'nom' => 'Restaurant',
            'pour' => 'un restaurant, un maquis ou un fast-food',
            'titre' => 'Logiciel de caisse pour restaurant et maquis',
            'accroche' => 'Plan de salle, commandes envoyées en cuisine, additions partagées, livraisons : servez vite et comptez chaque franc.',
            'points' => [
                ['Plan de salle', 'Vos tables d’un coup d’œil : libres, occupées, en attente de l’addition ou réservées.'],
                ['Commandes en cuisine', 'Chaque commande part à l’écran de la cuisine ou du bar, qui marque ce qui est prêt.'],
                ['Additions faciles', 'Addition partagée entre amis, pourboire, paiement en espèces et Mobile Money à la fois.'],
                ['Sur place, à emporter, livraison', 'Les commandes par téléphone et les livraisons ont leur suivi, jusqu’au paiement.'],
                ['Réservations', 'Les réservations du soir réservent leur table sur le plan de salle.'],
                ['Recettes et ingrédients', 'Avec le plan Pro, chaque plat vendu retire ses ingrédients du stock.'],
            ],
            'questions' => [
                ['Les serveurs ont-ils chacun leur accès ?', 'Oui : chaque serveur prend ses commandes depuis son téléphone, la caisse encaisse les additions.'],
                ['Peut-on encaisser une partie de l’addition ?', 'Oui : choisissez les plats payés maintenant, le reste attend sur la table.'],
            ],
        ],
        'pressing' => [
            'nom' => 'Pressing',
            'pour' => 'un pressing ou une blanchisserie',
            'titre' => 'Logiciel de gestion pour pressing',
            'accroche' => 'Dépôt des vêtements, service et prix par habit, express, acompte et retrait : votre pressing organisé, sans cahier.',
            'points' => [
                ['Dépôt en quelques gestes', 'Chaque habit avec son service : lavage et repassage, repassage seul, nettoyage à sec…'],
                ['Express et acompte', 'Le prix express s’applique d’un geste, l’acompte versé au dépôt est déduit au retrait.'],
                ['Bon de dépôt', 'Le client repart avec son bon, imprimé ou envoyé par WhatsApp, et le numéro de sa commande.'],
                ['Suivi jusqu’au retrait', 'En cours, prêt, retiré : chaque commande a son étape et son casier de rangement.'],
                ['Collecte et livraison', 'Les commandes ramassées ou livrées à domicile ont leur suivi.'],
                ['Vos prix à vous', 'Chaque habit a son prix par service, que vous changez quand vous voulez.'],
            ],
            'questions' => [
                ['Le client est-il prévenu quand c’est prêt ?', 'Vous lui envoyez un message WhatsApp depuis sa commande, en un geste.'],
                ['Et sans imprimante ?', 'Le bon de dépôt et le reçu partent en PDF par WhatsApp : le client les garde dans son téléphone.'],
            ],
        ],
        'distributeur' => [
            'nom' => 'Distributeur',
            'pour' => 'un grossiste ou un distributeur de boissons',
            'titre' => 'Logiciel de facturation pour grossiste et distributeur',
            'accroche' => 'Vente au casier et à la bouteille, prix de gros, facture distributeur avec codes articles, PSA et frais d’enlèvement : la caisse des dépôts.',
            'points' => [
                ['Facture distributeur', 'Code de chaque article, identifiant fiscal du client, PSA, frais d’enlèvement : la facture que vos clients attendent.'],
                ['Casier et bouteille', 'Chaque article se vend au casier, au pack ou à la bouteille, avec son prix.'],
                ['Frais d’enlèvement par casier', 'Fixés article par article et ajoutés seuls à la facture ; la caisse peut les retirer.'],
                ['Prix de gros et revendeurs', 'Vos revendeurs paient d’office le prix de gros, avec un plafond de crédit.'],
                ['Commandes des revendeurs', 'Vos clients boutiquiers commandent depuis leur propre Ngoni Caisse ; vous confirmez et livrez.'],
                ['Import de votre facture', 'Envoyez une photo de votre facture actuelle : Ngoni remplit vos mentions et choisit le bon modèle.'],
            ],
            'questions' => [
                ['La facture peut-elle porter mes propres codes ?', 'Oui : chaque article a son code, proposé à partir du nom et modifiable.'],
                ['Les frais d’enlèvement sont-ils obligatoires ?', 'Non : ils s’ajoutent seuls, mais la caisse peut les retirer d’une vente.'],
            ],
        ],
    ];

    /**
     * @var array<string, array{nom: string, code: string, dans: string, ville: string, devise: string, paiements: string}>
     */
    public const PAYS = [
        'mali' => ['nom' => 'Mali', 'code' => 'ML', 'dans' => 'au Mali', 'ville' => 'Bamako', 'devise' => 'XOF', 'paiements' => 'Orange Money, Moov Money et Wave'],
        'senegal' => ['nom' => 'Sénégal', 'code' => 'SN', 'dans' => 'au Sénégal', 'ville' => 'Dakar', 'devise' => 'XOF', 'paiements' => 'Wave, Orange Money et Free Money'],
        'cote-d-ivoire' => ['nom' => 'Côte d’Ivoire', 'code' => 'CI', 'dans' => 'en Côte d’Ivoire', 'ville' => 'Abidjan', 'devise' => 'XOF', 'paiements' => 'Orange Money, MTN MoMo, Moov Money et Wave'],
        'burkina-faso' => ['nom' => 'Burkina Faso', 'code' => 'BF', 'dans' => 'au Burkina Faso', 'ville' => 'Ouagadougou', 'devise' => 'XOF', 'paiements' => 'Orange Money et Moov Money'],
        'guinee' => ['nom' => 'Guinée', 'code' => 'GN', 'dans' => 'en Guinée', 'ville' => 'Conakry', 'devise' => 'GNF', 'paiements' => 'Orange Money et MTN MoMo'],
        'cameroun' => ['nom' => 'Cameroun', 'code' => 'CM', 'dans' => 'au Cameroun', 'ville' => 'Douala', 'devise' => 'XAF', 'paiements' => 'Orange Money et MTN MoMo'],
        'benin' => ['nom' => 'Bénin', 'code' => 'BJ', 'dans' => 'au Bénin', 'ville' => 'Cotonou', 'devise' => 'XOF', 'paiements' => 'MTN MoMo et Moov Money'],
        'togo' => ['nom' => 'Togo', 'code' => 'TG', 'dans' => 'au Togo', 'ville' => 'Lomé', 'devise' => 'XOF', 'paiements' => 'Flooz et T-Money'],
    ];

    /** Les adresses de toutes les pages, pour le plan du site. @return list<array{metier: string, pays: ?string}> */
    public static function toutes(): array
    {
        $pages = [];
        foreach (array_keys(self::METIERS) as $metier) {
            $pages[] = ['metier' => $metier, 'pays' => null];
            foreach (array_keys(self::PAYS) as $pays) {
                $pages[] = ['metier' => $metier, 'pays' => $pays];
            }
        }

        return $pages;
    }
}
