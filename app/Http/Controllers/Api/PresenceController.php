<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Plateforme\Presences;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Le signal de présence de l'application : « je suis ouverte » toutes les
 * 15 s, « je pars » quand elle passe en arrière-plan ou se déconnecte. La
 * console de l'exploitant dit alors « en ligne » à la seconde près.
 */
class PresenceController extends Controller
{
    public function signal(Request $request): Response
    {
        $user = $request->user();
        $maintenant = now();
        DB::table('users')->where('id', $user->id)->update([
            'vu_le' => $maintenant,
            'presence_jusqua' => $maintenant->copy()->addSeconds(Presences::PRESENCE_SECONDES),
        ]);
        // NoterPresence relit vu_le après la réponse : déjà à jour, il ne réécrit pas.
        $user->vu_le = $maintenant;

        return response()->noContent();
    }

    public function depart(Request $request): Response
    {
        $user = $request->user();
        $maintenant = now();
        // Le départ et la dernière vue au même instant : une activité notée
        // après (le back-office ouvert, l'application rouverte) le fera revenir.
        DB::table('users')->where('id', $user->id)->update(['vu_le' => $maintenant, 'presence_jusqua' => $maintenant]);
        $user->vu_le = $maintenant;

        return response()->noContent();
    }
}
