<?php

namespace App\Support;

class WhatsApp
{
    /**
     * Lien wa.me pour un numéro, ou null si l'indicatif pays manque.
     * Beaucoup de numéros sont saisis au format local (« 0758071816 ») :
     * on ne devine pas le pays, un lien vers le mauvais numéro serait pire
     * que pas de lien.
     */
    public static function link(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        // « 76131140/70612265 » : on garde le premier numéro.
        $phone = trim(preg_split('#[/,;]#', $phone)[0]);
        $digits = preg_replace('/\D+/', '', $phone);

        if (str_starts_with($phone, '+')) {
            // déjà international
        } elseif (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '0') || strlen($digits) < 11) {
            return null; // format local, pays inconnu
        }

        return strlen($digits) >= 8 ? 'https://wa.me/' . $digits : null;
    }
}
