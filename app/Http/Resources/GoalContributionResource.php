<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GoalContributionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'goal_id' => $this->goal_id,
            'amount' => (float) $this->amount,
            'date' => $this->date?->format('Y-m-d'),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
