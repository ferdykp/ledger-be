<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BudgetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount_limit' => (float) $this->amount_limit,
            'spent_amount' => $this->spent_amount,
            'remaining_amount' => max(0, $this->amount_limit - $this->spent_amount),
            'percentage' => $this->percentage,
            'status_color' => $this->status_color,
            'period' => $this->period,
            'start_date' => $this->start_date?->format('Y-m-d'),
            'category' => new CategoryResource($this->whenLoaded('category')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
