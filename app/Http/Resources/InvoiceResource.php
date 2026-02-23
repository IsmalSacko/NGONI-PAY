<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'total_amount' => $this->total_amount,
            'pdf_path' => $this->pdf_path,
            'sent_via' => $this->sent_via,
            'created_at' => $this->created_at,
            // Include related payment details
            'payment' =>  [
                'id' => $this->payment->id,
                'amount' => $this->payment->amount,
                'currency' => $this->payment->currency,
                'method' => $this->payment->method,
                'status' => $this->payment->status,
                'paid_at' => $this->payment->paid_at,
                'purpose' => $this->payment->purpose,
                'subscription_plan' => $this->payment->purpose === 'subscription'
                    ? $this->planFromAmount($this->payment->amount)
                    : null,
                'business' => $this->payment->business
                    ? [
                        'id' => $this->payment->business->id,
                        'name' => $this->payment->business->name,
                    ]
                    : null,

                // Include related client details
                'client' => $this->payment->client ?
                    [
                        'id' => $this->payment->client->id,
                        'name' => $this->payment->client->name,
                        'email' => $this->payment->client->email,
                        'phone' => $this->payment->client->phone,
                        'address' => $this->payment->client->address,
                    ] : null,
            ],
        ];
    }

    private function planFromAmount($amount): string
    {
        $value = (int) round((float) $amount);

        return match ($value) {
            5000 => 'basic',
            15000 => 'pro',
            default => 'free',
        };
    }
}
