<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'account_id' => $this->account_id,
            'related_account_id' => $this->related_account_id,
            'category_id' => $this->category_id,
            'amount' => (float) $this->amount,
            'date' => $this->date?->format('Y-m-d'),
            'note' => $this->note,
            'account' => new AccountResource($this->whenLoaded('account')),
            'related_account' => new AccountResource($this->whenLoaded('relatedAccount')),
            'category' => $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ] : null,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
