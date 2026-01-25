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
        ];
    }
}
