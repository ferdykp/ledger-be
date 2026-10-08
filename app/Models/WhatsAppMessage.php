<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppMessage extends Model
{
    protected $table = 'whatsapp_messages';

    protected $fillable = [
        'user_id',
        'provider_message_id',
        'phone_number',
        'direction',
        'message_type',
        'body',
        'payload',
        'processed_at',
        'reply_text',
        'replied_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
        'replied_at' => 'datetime',
    ];
}
