<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetTransaction extends Model
{
    protected $fillable = ['user_id', 'asset_id', 'cash_account_id', 'cash_transaction_id', 'settlement_transaction_id', 'type', 'status', 'cash_debited', 'amount', 'quantity', 'unit_price', 'price_unit_grams', 'fees', 'cost_basis', 'transaction_date', 'confirmed_at', 'settled_at', 'idempotency_key', 'notes'];
    protected $casts = ['cash_debited' => 'boolean', 'amount' => 'decimal:2', 'quantity' => 'decimal:8', 'unit_price' => 'decimal:8', 'price_unit_grams' => 'decimal:8', 'fees' => 'decimal:2', 'cost_basis' => 'decimal:2', 'transaction_date' => 'date:Y-m-d', 'confirmed_at' => 'datetime', 'settled_at' => 'datetime'];
    public function asset(): BelongsTo { return $this->belongsTo(Asset::class); }
    public function cashAccount(): BelongsTo { return $this->belongsTo(Account::class, 'cash_account_id'); }
    public function cashTransaction(): BelongsTo { return $this->belongsTo(Transaction::class); }
    public function settlementTransaction(): BelongsTo { return $this->belongsTo(Transaction::class, 'settlement_transaction_id'); }
    public function refunds(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(AssetRefund::class); }
}
