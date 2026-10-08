<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\WhatsAppMessageProcessor;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class WhatsAppWebhookController extends Controller
{
    public function __invoke(Request $r, WhatsAppMessageProcessor $processor): JsonResponse
    {
        $secret = (string)config('services.evolution.webhook_secret');
        if ($secret && !hash_equals($secret, (string)$r->header('X-Ledger-Webhook-Secret'))) abort(401);
        $p = $r->all();
        $remote = (string)(data_get($p, 'data.key.remoteJid') ?? data_get($p, 'data.sender') ?? data_get($p, 'sender') ?? '');
        $phone = preg_replace('/\D+/', '', explode('@', $remote)[0] ?? '');
        $text = (string)(data_get($p, 'data.message.conversation') ?? data_get($p, 'data.message.extendedTextMessage.text') ?? data_get($p, 'data.text') ?? data_get($p, 'message') ?? '');
        $id = (string)(data_get($p, 'data.key.id') ?? data_get($p, 'data.id') ?? data_get($p, 'id') ?? Str::uuid());
        if (!$phone || !$text) return response()->json(['ok' => true, 'ignored' => true]);
        $msg = WhatsAppMessage::firstOrCreate(['provider_message_id' => $id], ['phone_number' => $phone, 'message_type' => 'text', 'body' => $text, 'payload' => $p]);
        if (!$msg->wasRecentlyCreated) return response()->json(['ok' => true, 'duplicate' => true]);
        $processor->handle($phone, $text);
        $msg->update(['processed_at' => now()]);
        return response()->json(['ok' => true]);
    }
}
