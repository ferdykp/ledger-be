<?php

namespace App\Http\Resources;

use App\Support\DecimalMath;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GoalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $savings = (string) $this->current_amount;
        $budget = '0.00';
        foreach ($this->resource->relationLoaded('items') ? $this->items : [] as $item) {
            $budget = DecimalMath::add($budget, $item->estimated_amount);
        }
        if (DecimalMath::compare($budget, '0.00') === 0) $budget = (string) $this->target_amount;
        $remaining = DecimalMath::subtract($budget, $savings);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'target_amount' => $budget,
            'budget_total' => $budget,
            'opening_balance' => (string) ($this->opening_balance ?? '0.00'),
            'current_amount' => $savings,
            'remaining_amount' => DecimalMath::compare($remaining, '0.00') < 0 ? '0.00' : $remaining,
            'progress_percent' => DecimalMath::compare($budget, '0.00') > 0
                ? min(100, (float) bcdiv(bcmul($savings, '1000', 0), $budget, 0) / 10)
                : 0,
            'target_date' => $this->target_date?->format('Y-m-d'),
            'icon' => $this->icon ?? 'plane',
            'contributions' => GoalContributionResource::collection($this->whenLoaded('contributions')),
            'items' => GoalItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
