<?php

namespace App\Models;

use App\Support\DecimalMath;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoalItem extends Model
{
    protected $fillable = ['goal_id', 'name', 'quantity', 'unit_price', 'notes', 'sort_order'];

    protected $casts = ['quantity' => 'decimal:4', 'unit_price' => 'decimal:2'];

    protected $appends = ['estimated_amount', 'paid_amount', 'remaining_amount'];

    public function goal(): BelongsTo { return $this->belongsTo(Goal::class); }

    public function obligations(): HasMany { return $this->hasMany(GoalObligation::class); }

    public function getEstimatedAmountAttribute(): string
    {
        return DecimalMath::quantityValue((string) $this->quantity, (string) $this->unit_price, 4);
    }

    public function getPaidAmountAttribute(): string
    {
        $sum = '0.00';
        foreach ($this->obligations as $obligation) {
            $sum = DecimalMath::add($sum, $obligation->paid_amount);
        }
        return $sum;
    }

    public function getRemainingAmountAttribute(): string
    {
        $remaining = DecimalMath::subtract($this->estimated_amount, $this->paid_amount);
        return DecimalMath::compare($remaining, '0.00') < 0 ? '0.00' : $remaining;
    }
}
