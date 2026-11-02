<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillPayment extends Model
{
    protected $fillable = ['bill_id', 'user_id', 'account_id', 'transaction_id', 'scheduled_for', 'amount', 'status', 'message', 'attempted_at'];

    protected $casts = [
        'scheduled_for' => 'date:Y-m-d',
        'amount' => 'decimal:2',
        'attempted_at' => 'datetime',
    ];

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
