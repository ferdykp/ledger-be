<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppPairingCode extends Model
{
    protected $table = 'whatsapp_pairing_codes';

    protected $fillable = [
        'user_id',
        'code_hash',
        'expires_at',
        'used_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
