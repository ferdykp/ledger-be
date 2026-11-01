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
        'opening_balance',
        'target_date',
        'icon',
    ];

    protected $casts = [
        'target_date' => 'date',
        'target_amount' => 'decimal:2',
        'current_amount' => 'decimal:2',
        'opening_balance' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(GoalContribution::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(GoalItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function cashAllocations(): HasMany
    {
        return $this->hasMany(GoalCashAllocation::class);
    }

    public function assetAllocations(): HasMany
    {
        return $this->hasMany(GoalAssetAllocation::class);
    }

    public function getProgressPercentAttribute(): float
    {
        if (bccomp((string) $this->target_amount, '0', 2) <= 0) {
            return 0.0;
        }

        return min(100, (float) bcdiv(bcmul((string) $this->current_amount, '1000', 0), (string) $this->target_amount, 0) / 10);
    }
}
