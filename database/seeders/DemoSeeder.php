<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Country;
use App\Models\CategorieProduit;
use App\Models\Client;
use App\Models\Produit;
use App\Models\User;
use App\Services\BoutiqueRegistrationService;
use App\Support\Phone\PhoneNumber;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Jeu de données de démonstration : une boutique, son équipe, un catalogue
 * repris de la maquette e-caisse et un client fidèle.
 *
 * Comptes de démonstration : `+223 76 00 00 00` (admin), `+223 77 00 00 00`
 * (gérant), `+223 78 00 00 00` (caissier) — mot de passe `password`.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $registration = app(BoutiqueRegistrationService::class);

        $result = $registration->register([
            'nom' => 'Épicerie Koné',
            'pays' => 'ML',
            'telephone' => '+223 76 00 00 00',
            'email' => null,
            'password' => 'password',
            'nom_utilisateur' => 'Aminata Koné',
        ]);

        $boutique = $result['boutique'];

        // Le reste du seed opère dans le contexte de cette boutique, comme le
        // ferait une requête authentifiée (voir SetTenantContext).
        app(TenantContext::class)->setBoutique($boutique->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($boutique->id);

        $gerant = User::create([
            'boutique_id' => $boutique->id,
            'name' => 'Moussa Diarra',
            'phone' => PhoneNumber::normalize('+223 77 00 00 00', Country::Mali),
            'password' => bcrypt('password'),
        ]);
        $gerant->assignRole('gerant');

        $caissier = User::create([
            'boutique_id' => $boutique->id,
            'name' => 'Fatoumata Traoré',
            'phone' => PhoneNumber::normalize('+223 78 00 00 00', Country::Mali),
            'password' => bcrypt('password'),
        ]);
        $caissier->assignRole('caissier');

        $categories = collect([
            ['nom' => 'Alimentation', 'couleur' => '#0B6E4F', 'ordre' => 1],
            ['nom' => 'Boissons', 'couleur' => '#1D4E89', 'ordre' => 2],
            ['nom' => 'Hygiène', 'couleur' => '#8A2C5E', 'ordre' => 3],
            ['nom' => 'Entretien', 'couleur' => '#7A3E06', 'ordre' => 4],
        ])->mapWithKeys(fn (array $c) => [$c['nom'] => CategorieProduit::create($c)]);

        // Taux de TVA UEMOA : A = normal, B = réduit, E = exonéré.
        $tva = ['A' => 18.00, 'B' => 5.00, 'E' => 0.00];

        $produits = [
            ['nom' => 'Riz parfumé', 'format' => 'Sac 5 kg', 'prix' => 4750, 'cat' => 'Alimentation', 'stock' => 86, 'code' => 'RZ', 'tva' => 'E'],
            ['nom' => "Huile d'arachide", 'format' => 'Bouteille 1 L', 'prix' => 1500, 'cat' => 'Alimentation', 'stock' => 42, 'code' => 'HA', 'tva' => 'B'],
            ['nom' => 'Sucre en morceaux', 'format' => 'Boîte 1 kg', 'prix' => 900, 'cat' => 'Alimentation', 'stock' => 120, 'code' => 'SU', 'tva' => 'A'],
            ['nom' => 'Lait en poudre', 'format' => 'Boîte 400 g', 'prix' => 2800, 'cat' => 'Alimentation', 'stock' => 8, 'code' => 'LP', 'tva' => 'A'],
            ['nom' => 'Spaghetti', 'format' => 'Paquet 500 g', 'prix' => 450, 'cat' => 'Alimentation', 'stock' => 150, 'code' => 'SP', 'tva' => 'A'],
            ['nom' => 'Concentré de tomate', 'format' => 'Boîte 400 g', 'prix' => 600, 'cat' => 'Alimentation', 'stock' => 64, 'code' => 'CT', 'tva' => 'A'],
            ['nom' => 'Farine de mil', 'format' => 'Sachet 1 kg', 'prix' => 700, 'cat' => 'Alimentation', 'stock' => 55, 'code' => 'FM', 'tva' => 'E'],
            ['nom' => 'Eau minérale', 'format' => 'Bouteille 1,5 L', 'prix' => 400, 'cat' => 'Boissons', 'stock' => 240, 'code' => 'EM', 'tva' => 'A'],
            ['nom' => 'Jus de bissap', 'format' => 'Bouteille 50 cl', 'prix' => 500, 'cat' => 'Boissons', 'stock' => 36, 'code' => 'JB', 'tva' => 'A'],
            ['nom' => 'Thé vert', 'format' => 'Paquet 200 g', 'prix' => 650, 'cat' => 'Boissons', 'stock' => 90, 'code' => 'TV', 'tva' => 'A'],
            ['nom' => 'Café soluble', 'format' => 'Pot 100 g', 'prix' => 2250, 'cat' => 'Boissons', 'stock' => 18, 'code' => 'CS', 'tva' => 'A'],
            ['nom' => 'Savon de ménage', 'format' => 'Barre 250 g', 'prix' => 250, 'cat' => 'Hygiène', 'stock' => 300, 'code' => 'SM', 'tva' => 'A'],
            ['nom' => 'Dentifrice', 'format' => 'Tube 75 ml', 'prix' => 850, 'cat' => 'Hygiène', 'stock' => 5, 'code' => 'DT', 'tva' => 'A'],
            ['nom' => 'Couches bébé', 'format' => 'Paquet ×30', 'prix' => 4500, 'cat' => 'Hygiène', 'stock' => 22, 'code' => 'CB', 'tva' => 'A'],
            ['nom' => 'Lessive en poudre', 'format' => 'Sachet 1 kg', 'prix' => 1250, 'cat' => 'Entretien', 'stock' => 48, 'code' => 'LE', 'tva' => 'A'],
            ['nom' => 'Eau de javel', 'format' => 'Bouteille 1 L', 'prix' => 500, 'cat' => 'Entretien', 'stock' => 0, 'code' => 'JV', 'tva' => 'A'],
        ];

        foreach ($produits as $p) {
            Produit::create([
                'categorie_produit_id' => $categories[$p['cat']]->id,
                'nom' => $p['nom'],
                'format' => $p['format'],
                'code' => $p['code'],
                'prix_vente' => $p['prix'],
                'taux_tva' => $tva[$p['tva']],
                'stock' => $p['stock'],
                'seuil_alerte' => 10,
            ]);
        }

        Client::create([
            'nom' => 'Moussa Traoré',
            'telephone' => '+223 79 00 00 00',
            'points_fidelite' => 1240,
        ]);
    }
}
