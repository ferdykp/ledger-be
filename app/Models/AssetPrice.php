<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetPrice extends Model
{
    protected $fillable = ['asset_id', 'price', 'unit', 'source', 'captured_at'];
    protected $casts = ['price' => 'decimal:8', 'captured_at' => 'datetime'];
    public function asset(): BelongsTo { return $this->belongsTo(Asset::class); }
}
