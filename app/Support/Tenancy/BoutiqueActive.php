<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\User;
use Illuminate\Support\Facades\App;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Choisit la boutique active d'une requête et pose le contexte tenant.
 *
 * Un compte peut appartenir à plusieurs boutiques. La boutique demandée (en-tête
 * `X-Boutique` pour l'API, session pour le back-office) n'est retenue que si le
 * compte y a un rôle ; à défaut, sa boutique par défaut (`users.boutique_id`).
 * Deux tablettes d'un même propriétaire peuvent ainsi travailler chacune dans
 * une boutique différente.
 */
class BoutiqueActive
{
    public const EN_TETE = 'X-Boutique';

    public const CLE_SESSION = 'boutique_active';

    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @param  bool  $strict  refuser (403) une boutique demandée explicitement à
     *                        laquelle le compte n'appartient pas, plutôt que de
     *                        retomber silencieusement sur une autre.
     */
    public function poser(User $user, ?string $demandee, bool $strict = false): ?string
    {
        $boutiqueId = null;

        if ($demandee !== null && $demandee !== '') {
            if ($user->appartientA($demandee)) {
                $boutiqueId = $demandee;
            } elseif ($strict) {
                throw new HttpException(403, 'Vous n’avez pas accès à cette boutique.');
            }
        }

        $boutiqueId ??= $user->boutique_id;

        if ($boutiqueId === null) {
            return null;
        }

        $this->tenant->setBoutique($boutiqueId);

        if (App::bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($boutiqueId);
            // Les rôles déjà chargés l'ont été pour une autre équipe.
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }

        return $boutiqueId;
    }
}
