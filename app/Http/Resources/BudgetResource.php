<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BudgetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $spent = $this->spent_amount;
        $percentage = $this->amount_limit > 0 ? round($spent / $this->amount_limit * 100, 1) : 0;

        return [
            'id' => $this->id,
            'amount_limit' => (float) $this->amount_limit,
            'category_id' => $this->category_id,
            'spent_amount' => $spent,
            'remaining_amount' => max(0, $this->amount_limit - $spent),
            'percentage' => $percentage,
            'status_color' => $percentage >= 100 ? 'danger' : ($percentage >= 80 ? 'warning' : 'safe'),
            'period' => $this->period,
            'start_date' => $this->start_date?->format('Y-m-d'),
            'category' => new CategoryResource($this->whenLoaded('category')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
