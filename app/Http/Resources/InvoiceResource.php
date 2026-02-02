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
}
