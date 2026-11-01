<?php

namespace App\Models;

use App\Support\DecimalMath;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoalObligation extends Model
{
    protected $fillable = ['goal_item_id', 'name', 'amount', 'due_date', 'payee', 'status'];

    protected $casts = ['amount' => 'decimal:2', 'due_date' => 'date:Y-m-d'];

    protected $appends = ['paid_amount', 'remaining_amount'];

    public function item(): BelongsTo { return $this->belongsTo(GoalItem::class, 'goal_item_id'); }

    public function payments(): HasMany { return $this->hasMany(Transaction::class, 'goal_obligation_id'); }

    public function getPaidAmountAttribute(): string
    {
        $sum = '0.00';
        foreach ($this->payments as $payment) {
            if ($payment->financial_purpose === 'goal_refund') {
                $sum = DecimalMath::subtract($sum, (string) $payment->amount);
            } else {
                $sum = DecimalMath::add($sum, (string) $payment->amount);
            }
        }
        return DecimalMath::compare($sum, '0.00') < 0 ? '0.00' : $sum;
    }

    public function getRemainingAmountAttribute(): string
    {
        $remaining = DecimalMath::subtract((string) $this->amount, $this->paid_amount);
        return DecimalMath::compare($remaining, '0.00') < 0 ? '0.00' : $remaining;
    }
}
