<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GoalObligationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'goal_item_id' => $this->goal_item_id,
            'name' => $this->name,
            'amount' => (string) $this->amount,
            'paid_amount' => $this->paid_amount,
            'remaining_amount' => $this->remaining_amount,
            'due_date' => $this->due_date?->format('Y-m-d'),
            'payee' => $this->payee,
            'status' => $this->status,
        ];
    }
}
