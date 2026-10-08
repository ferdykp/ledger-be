<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\EvolutionProvider;
use App\Services\WhatsApp\WhatsAppMessageProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WhatsAppWebhookController extends Controller
{
    public function __invoke(Request $request, WhatsAppMessageProcessor $processor, EvolutionProvider $provider): JsonResponse
    {
        $secret = (string) config('services.evolution.webhook_secret');
        abort_if($secret === '', 503, 'WhatsApp webhook belum dikonfigurasi.');
        abort_unless(hash_equals($secret, (string) $request->header('X-Ledger-Webhook-Secret')), 401);
        $payload = $request->all();
        $event = data_get($payload, 'event');
        $remote = data_get($payload, 'data.key.remoteJid') ?? data_get($payload, 'data.sender') ?? data_get($payload, 'sender');
        if (data_get($payload, 'data.key.fromMe') || ($event && ! in_array($event, ['messages.upsert', 'MESSAGES_UPSERT'], true))) {
            return response()->json(['ok' => true, 'ignored' => true]);
        }
        // Groups, status broadcasts and opaque @lid identities are not verified phone numbers.
        if (! is_string($remote) || ! preg_match('/^\+?(\d{9,15})(?:@s\.whatsapp\.net)?$/', $remote, $match)) {
            return response()->json(['ok' => true, 'ignored' => true]);
        }
        $text = data_get($payload, 'data.message.conversation') ?? data_get($payload, 'data.message.extendedTextMessage.text') ?? data_get($payload, 'data.text');
        if (! is_string($text) || trim($text) === '') {
            return response()->json(['ok' => true, 'ignored' => true]);
        }
        $id = data_get($payload, 'data.key.id') ?? data_get($payload, 'data.id') ?? data_get($payload, 'id');
        // A random fallback ID would make provider retries create new financial operations.
        abort_unless(is_string($id) && $id !== '' && strlen($id) <= 255, 422, 'ID pesan wajib tersedia.');
        abort_if(mb_strlen($text) > 4000, 422, 'Pesan terlalu panjang.');
        $message = WhatsAppMessage::firstOrCreate(['provider_message_id' => $id], [
            'phone_number' => $match[1], 'message_type' => 'text', 'body' => $text, 'payload' => $payload,
        ]);
        DB::transaction(function () use ($message, $processor) {
            $message = WhatsAppMessage::whereKey($message->id)->lockForUpdate()->firstOrFail();
            if ($message->processed_at) {
                return;
            }
            $reply = $processor->handle($message->phone_number, $message->body);
            // Commit the financial change, session state and reply together.
            $message->update(['processed_at' => now(), 'reply_text' => $reply]);
        }, 3);
        $sent = DB::transaction(function () use ($message, $provider) {
            $message = WhatsAppMessage::whereKey($message->id)->lockForUpdate()->firstOrFail();
            if ($message->replied_at || ! $message->reply_text) {
                return true;
            }
            if (! $provider->sendText($message->phone_number, $message->reply_text)) {
                return false;
            }
            $message->update(['replied_at' => now()]);

            return true;
        });

        return response()->json(['ok' => $sent, 'reply_pending' => ! $sent], $sent ? 200 : 502);
    }
}
