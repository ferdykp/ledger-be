<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Support\DecimalMath;

class BudgetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $spent = $this->spent_amount;
        $percentage = DecimalMath::compare((string) $this->amount_limit, '0.00') > 0 ? (float) bcdiv(bcmul($spent, '1000', 0), (string) $this->amount_limit, 0) / 10 : 0;

        return [
            'id' => $this->id,
            'amount_limit' => (string) $this->amount_limit,
            'category_id' => $this->category_id,
            'spent_amount' => $spent,
            'remaining_amount' => DecimalMath::compare(DecimalMath::subtract((string) $this->amount_limit, $spent), '0.00') > 0 ? DecimalMath::subtract((string) $this->amount_limit, $spent) : '0.00',
            'percentage' => $percentage,
            'status_color' => $percentage >= 100 ? 'danger' : ($percentage >= 80 ? 'warning' : 'safe'),
            'period' => $this->period,
            'start_date' => $this->start_date?->format('Y-m-d'),
            'category' => new CategoryResource($this->whenLoaded('category')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
