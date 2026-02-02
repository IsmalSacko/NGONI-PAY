<?php

namespace App\Services;

class PhoneService
{
    public function normalize(string $phone): string
    {
        // Supprimer les espaces
        $phone = str_replace(' ', '', $phone);

        // +223XXXXXXXX
        if (str_starts_with($phone, '+223')) {
            return $phone;
        }

        // XXXXXXXX
        if (preg_match('/^\d{8}$/', $phone)) {
            return '+223' . $phone;
        }

        // 223XXXXXXXX
        if (preg_match('/^223\d{8}$/', $phone)) {
            return '+' . $phone;
        }

        return $phone;
    }
}
