<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Bill extends Model
{
    protected $fillable = ['user_id', 'name', 'amount', 'due_date', 'frequency', 'status', 'category', 'note', 'reminder_enabled', 'auto_pay_enabled', 'auto_pay_account_id'];

    protected $casts = ['amount' => 'decimal:2', 'due_date' => 'date', 'reminder_enabled' => 'boolean', 'auto_pay_enabled' => 'boolean'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function autoPayAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'auto_pay_account_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(BillPayment::class);
    }

    public function latestAutoPayment(): HasOne
    {
        return $this->hasMany(BillPayment::class)->one()->latestOfMany('scheduled_for');
    }
}
