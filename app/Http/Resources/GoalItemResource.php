<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GoalItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'quantity' => (string) $this->quantity,
            'unit_price' => (string) $this->unit_price,
            'estimated_amount' => $this->estimated_amount,
            'paid_amount' => $this->paid_amount,
            'remaining_amount' => $this->remaining_amount,
            'notes' => $this->notes,
            'sort_order' => $this->sort_order,
            'obligations' => GoalObligationResource::collection($this->whenLoaded('obligations')),
        ];
    }
}
