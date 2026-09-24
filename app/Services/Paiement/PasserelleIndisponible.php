<?php

declare(strict_types=1);

namespace App\Services\Paiement;

use RuntimeException;

/**
 * Le fournisseur n'a pas pu être joint ou a refusé la requête. Le message est
 * destiné au caissier (en français, sans détail technique ni clé) ; le détail
 * brut est écrit dans les logs par la passerelle.
 */
class PasserelleIndisponible extends RuntimeException {}
