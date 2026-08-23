<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GoalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'target_amount' => (float) $this->target_amount,
            'current_amount' => (float) $this->current_amount,
            'remaining_amount' => max(0, $this->target_amount - $this->current_amount),
            'progress_percent' => $this->progress_percent,
            'target_date' => $this->target_date?->format('Y-m-d'),
            'icon' => $this->icon ?? 'plane',
            'contributions' => GoalContributionResource::collection($this->whenLoaded('contributions')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
