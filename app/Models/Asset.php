<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Asset extends Model
{
    protected $fillable = ['user_id', 'type', 'valuation_mode', 'manual_current_value', 'name', 'provider', 'identifier', 'storage_type', 'currency'];
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function transactions(): HasMany { return $this->hasMany(AssetTransaction::class); }
    public function prices(): HasMany { return $this->hasMany(AssetPrice::class); }
    public function allocations(): HasMany { return $this->hasMany(GoalAssetAllocation::class); }
}
