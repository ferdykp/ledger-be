<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Goal extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'target_amount',
        'current_amount',
        'target_date',
        'icon',
    ];

    protected $casts = [
        'target_date' => 'date',
        'target_amount' => 'float',
        'current_amount' => 'float',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(GoalContribution::class);
    }

    public function getProgressPercentAttribute(): float
    {
        if ($this->target_amount <= 0) return 0.0;
        return min(100, round(($this->current_amount / $this->target_amount) * 100, 1));
    }
}
