<?php

declare(strict_types=1);

namespace App\Enums;

use LogicException;

/**
 * Secteur d'activité d'un business.
 *
 * Cinq secteurs étaient proposés — boutique, école, pharmacie, garage, service.
 * Un menuisier, un bijoutier, un coiffeur ou un restaurateur n'avait donc que
 * « service » pour se décrire, ce qui ne décrit rien : ni sur sa facture, ni dans
 * les statistiques de l'éditeur.
 *
 * La liste est ouverte à dessein, et se termine par `Other` : mieux vaut un
 * secteur générique assumé qu'un commerçant qui se range dans une case fausse.
 *
 * Les cinq valeurs d'origine sont conservées telles quelles — elles sont en base
 * sur des business existants.
 */
enum BusinessType: string
{
    // Commerce.
    case Shop = 'shop';
    case Grocery = 'grocery';
    case Clothing = 'clothing';
    case Jewelry = 'jewelry';
    case Cosmetics = 'cosmetics';
    case Electronics = 'electronics';
    case Hardware = 'hardware';
    case BuildingMaterials = 'building_materials';
    case Agrifood = 'agrifood';
    case Bookstore = 'bookstore';
    case Pharmacy = 'pharmacy';

    // Artisanat et bâtiment.
    case Carpentry = 'carpentry';
    case Metalwork = 'metalwork';
    case Tailoring = 'tailoring';
    case Shoemaking = 'shoemaking';
    case Masonry = 'masonry';
    case Painting = 'painting';
    case Electrician = 'electrician';
    case Plumbing = 'plumbing';
    case Garage = 'garage';
    case MotorcycleRepair = 'motorcycle_repair';
    case Solar = 'solar';

    // Restauration et alimentation.
    case Restaurant = 'restaurant';
    case Bakery = 'bakery';
    case Catering = 'catering';

    // Beauté et soins.
    case Hairdressing = 'hairdressing';
    case Laundry = 'laundry';

    // Services et professions.
    case Photography = 'photography';
    case Printing = 'printing';
    case Cybercafe = 'cybercafe';
    case MoneyTransfer = 'money_transfer';
    case RealEstate = 'real_estate';
    case Rental = 'rental';
    case Consulting = 'consulting';
    case It = 'it';
    case Security = 'security';
    case Cleaning = 'cleaning';

    // Transport et logistique.
    case Transport = 'transport';
    case Travel = 'travel';

    // Éducation, santé, hébergement.
    case School = 'school';
    case Health = 'health';
    case Hotel = 'hotel';
    case Agriculture = 'agriculture';

    // Génériques.
    case Service = 'service';
    case Other = 'other';

    /**
     * Libellé et famille, par valeur.
     *
     * La famille sert à regrouper la liste présentée : quarante secteurs à plat
     * ne se parcourent pas.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const CATALOGUE = [
        'shop' => ['Boutique / commerce général', 'Commerce'],
        'grocery' => ['Alimentation / épicerie', 'Commerce'],
        'clothing' => ['Prêt-à-porter / friperie', 'Commerce'],
        'jewelry' => ['Bijouterie', 'Commerce'],
        'cosmetics' => ['Cosmétique / parfumerie', 'Commerce'],
        'electronics' => ['Électronique / téléphonie', 'Commerce'],
        'hardware' => ['Quincaillerie', 'Commerce'],
        'building_materials' => ['Matériaux de construction', 'Commerce'],
        'agrifood' => ['Produits vivriers / agroalimentaire', 'Commerce'],
        'bookstore' => ['Librairie / papeterie', 'Commerce'],
        'pharmacy' => ['Pharmacie', 'Commerce'],

        'carpentry' => ['Menuiserie', 'Artisanat et bâtiment'],
        'metalwork' => ['Soudure / ferronnerie', 'Artisanat et bâtiment'],
        'tailoring' => ['Couture / broderie', 'Artisanat et bâtiment'],
        'shoemaking' => ['Cordonnerie', 'Artisanat et bâtiment'],
        'masonry' => ['Maçonnerie', 'Artisanat et bâtiment'],
        'painting' => ['Peinture / décoration', 'Artisanat et bâtiment'],
        'electrician' => ['Électricité bâtiment', 'Artisanat et bâtiment'],
        'plumbing' => ['Plomberie', 'Artisanat et bâtiment'],
        'garage' => ['Garage / mécanique auto', 'Artisanat et bâtiment'],
        'motorcycle_repair' => ['Mécanique moto', 'Artisanat et bâtiment'],
        'solar' => ['Solaire / énergie', 'Artisanat et bâtiment'],

        'restaurant' => ['Restaurant / maquis', 'Restauration'],
        'bakery' => ['Boulangerie / pâtisserie', 'Restauration'],
        'catering' => ['Traiteur / événementiel', 'Restauration'],

        'hairdressing' => ['Coiffure / salon de beauté', 'Beauté et soins'],
        'laundry' => ['Blanchisserie / pressing', 'Beauté et soins'],

        'photography' => ['Photo / vidéo', 'Services'],
        'printing' => ['Imprimerie / sérigraphie', 'Services'],
        'cybercafe' => ['Cybercafé / multiservices', 'Services'],
        'money_transfer' => ["Transfert d'argent", 'Services'],
        'real_estate' => ['Immobilier', 'Services'],
        'rental' => ['Location de matériel', 'Services'],
        'consulting' => ["Conseil / bureau d'études", 'Services'],
        'it' => ['Informatique / développement', 'Services'],
        'security' => ['Gardiennage / sécurité', 'Services'],
        'cleaning' => ['Nettoyage / entretien', 'Services'],

        'transport' => ['Transport / livraison', 'Transport'],
        'travel' => ['Agence de voyage', 'Transport'],

        'school' => ['École / formation', 'Éducation et santé'],
        'health' => ['Clinique / cabinet de santé', 'Éducation et santé'],
        'hotel' => ['Hôtel / auberge', 'Éducation et santé'],
        'agriculture' => ['Agriculture / élevage', 'Éducation et santé'],

        'service' => ['Prestation de service', 'Autre'],
        'other' => ['Autre activité', 'Autre'],
    ];

    /**
     * @return array{0: string, 1: string}
     */
    private function meta(): array
    {
        $meta = self::CATALOGUE[$this->value] ?? null;

        if ($meta === null) {
            throw new LogicException("Secteur {$this->value} absent du catalogue.");
        }

        return $meta;
    }

    public function label(): string
    {
        return $this->meta()[0];
    }

    public function family(): string
    {
        return $this->meta()[1];
    }

    /**
     * Secteur de repli, pour une valeur héritée que le catalogue ne connaît pas.
     *
     * Rendu tel quel plutôt que corrigé : une facture qui annonce « Autre
     * activité » est moins fausse qu'une qui annonce « Boutique ».
     */
    public static function fromValue(?string $value): self
    {
        return self::tryFrom(strtolower(trim((string) $value))) ?? self::Other;
    }

    /**
     * Libellé lisible d'une valeur brute, y compris hors catalogue.
     */
    public static function labelFor(?string $value): string
    {
        return self::fromValue($value)->label();
    }
}
