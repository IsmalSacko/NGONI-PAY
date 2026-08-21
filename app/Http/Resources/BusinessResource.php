<?php

namespace App\Http\Resources;

use App\Enums\BusinessType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BusinessResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            // Libellé du secteur : l'application n'a pas à traduire les valeurs
            // techniques, et un secteur ajouté n'attend pas sa mise à jour.
            'type_label' => BusinessType::labelFor($this->type),
            'address' => $this->address,
            'phone' => $this->phone,
            'currency' => $this->currency ?: 'XOF',
            'is_active' => $this->is_active,

            'owner' => [
                'id' => $this->owner->id,
                'name' => $this->owner->name,
                'phone' => $this->owner->phone,
                // Le pays du propriétaire donne l'indicatif des numéros saisis
                // dans ce business : c'est là qu'il encaisse, donc là que ses
                // clients ont leur numéro.
                'country' => $this->owner->country ?: 'ML',
            ],

            'created_at' => $this->created_at,
        ];
    }
}
