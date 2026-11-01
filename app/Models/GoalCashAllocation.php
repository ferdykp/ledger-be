<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoalCashAllocation extends Model
{
    protected $fillable = ['user_id', 'goal_id', 'account_id', 'amount', 'action', 'note', 'idempotency_key'];
    protected $casts = ['amount' => 'decimal:2'];
    public function goal(): BelongsTo { return $this->belongsTo(Goal::class); }
    public function account(): BelongsTo { return $this->belongsTo(Account::class); }
}
