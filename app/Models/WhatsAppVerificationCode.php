<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppVerificationCode extends Model
{
    protected $table = 'whatsapp_verification_codes';

    protected $fillable = [
        'user_id',
        'phone_number',
        'code_hash',
        'attempts',
        'expires_at',
        'used_at',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    protected $hidden = [
        'code_hash',
    ];
}
