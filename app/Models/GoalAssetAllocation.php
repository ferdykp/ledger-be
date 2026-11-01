<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoalAssetAllocation extends Model
{
    protected $fillable = ['user_id', 'goal_id', 'asset_id', 'quantity', 'action', 'note', 'idempotency_key'];
    protected $casts = ['quantity' => 'decimal:8'];
    public function goal(): BelongsTo { return $this->belongsTo(Goal::class); }
    public function asset(): BelongsTo { return $this->belongsTo(Asset::class); }
}
