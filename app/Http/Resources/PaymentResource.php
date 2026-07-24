<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'method' => $this->method,
            'provider' => $this->provider,
            'provider_reference' => $this->provider_reference,
            'provider_checkout_url' => $this->provider_checkout_url,
            'status' => $this->status,
            'purpose' => $this->purpose,
            'paid_at' => $this->paid_at,
            'cancelled_at' => $this->cancelled_at,
            'created_at' => $this->created_at,
            'transaction_ref' => $this->transaction_ref,
            'client' => [
                'id' => optional($this->client)->id,
                'name' => optional($this->client)->name,
                'phone' => optional($this->client)->phone,
                'email' => optional($this->client)->email,
            ],
        ];
    }
}
