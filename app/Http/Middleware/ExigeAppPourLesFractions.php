<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Boutique;
use App\Models\Produit;
use App\Support\Presence\Appareil;
use App\Support\Quantite;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Vente au poids et au demi : une application d'avant 4.10.5 lirait 12,5 kg
 * comme « 12 ». Dans une boutique qui s'en sert, elle doit se mettre à jour —
 * le serveur répond 426 avec un message qu'elle affiche telle quelle.
 *
 * Une vente envoyée reste toujours acceptée (en quantités entières, elle est
 * juste) : une vente faite hors ligne ne se perd jamais.
 */
class ExigeAppPourLesFractions
{
    public const VERSION = '4.10.5';

    /** Vente en gros (deux prix) : connue à partir de cette version. */
    public const VERSION_GROS = '4.10.6';

    /** Chemins (sans préfixe) toujours ouverts. */
    private const OUVERTS = ['api/moi', 'api/deconnexion', 'api/appareils', 'api/ventes'];

    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $version = Appareil::depuisRequete($request)->version;
        $boutique = $this->tenant->boutiqueId();

        if ($version === null || $boutique === null || in_array(trim($request->path(), '/'), self::OUVERTS, true)) {
            return $next($request);
        }
        $v = explode('+', $version)[0];

        // Une ancienne application afficherait le prix de détail quand le
        // serveur applique le prix de gros : le ticket ne tomberait plus juste.
        if (version_compare($v, self::VERSION_GROS, '<') && self::venteEnGros($boutique)) {
            return response()->json([
                'message' => 'Votre boutique vend au détail et en gros : mettez à jour Ngoni Caisse pour continuer.',
                'code' => 'MISE_A_JOUR_REQUISE',
            ], 426);
        }

        if (version_compare($v, self::VERSION, '>=') || ! self::venteFractionnee($boutique)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Votre boutique vend au poids ou au demi : mettez à jour Ngoni Caisse pour continuer.',
            'code' => 'MISE_A_JOUR_REQUISE',
        ], 426);
    }

    public static function venteEnGros(string $boutiqueId): bool
    {
        return Cache::remember("vente-en-gros:$boutiqueId", 120, fn () => Boutique::whereKey($boutiqueId)->value('mode_vente') === 'detail_gros');
    }

    /** Un article vendu au kilo, au litre, au mètre, ou un stock en fraction. Un
     * sac ou un carton entier, l'ancienne application le compte juste. */
    public static function venteFractionnee(string $boutiqueId): bool
    {
        return Cache::remember("vente-fractionnee:$boutiqueId", 120, fn () => Produit::withoutGlobalScopes()
            ->where('boutique_id', $boutiqueId)
            ->where(fn ($q) => $q->whereIn('unite', array_keys(Quantite::MESURES))->orWhereRaw('stock <> ROUND(stock)'))
            ->exists());
    }
}
