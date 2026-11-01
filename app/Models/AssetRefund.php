<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetRefund extends Model
{
    protected $fillable = ['user_id', 'asset_transaction_id', 'account_id', 'transaction_id', 'amount', 'received_date', 'idempotency_key'];
    protected $casts = ['amount' => 'decimal:2', 'received_date' => 'date:Y-m-d'];
    public function assetTransaction(): BelongsTo { return $this->belongsTo(AssetTransaction::class); }
    public function account(): BelongsTo { return $this->belongsTo(Account::class); }
    public function transaction(): BelongsTo { return $this->belongsTo(Transaction::class); }
}
