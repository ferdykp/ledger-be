<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\EvolutionProvider;
use App\Services\WhatsApp\WhatsAppMessageProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class WhatsAppWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        WhatsAppMessageProcessor $processor,
        EvolutionProvider $provider
    ): JsonResponse {
        $requestStarted = microtime(true);

        $secret = (string) config('services.evolution.webhook_secret');

        abort_if(
            $secret === '',
            503,
            'WhatsApp webhook belum dikonfigurasi.'
        );

        abort_unless(
            hash_equals(
                $secret,
                (string) $request->header('X-Ledger-Webhook-Secret')
            ),
            401
        );

        $payload = $request->all();
        $event = data_get($payload, 'event');

        $remote = data_get($payload, 'data.key.remoteJid')
            ?? data_get($payload, 'data.sender')
            ?? data_get($payload, 'sender');

        if (
            data_get($payload, 'data.key.fromMe')
            || (
                $event
                && ! in_array(
                    $event,
                    ['messages.upsert', 'MESSAGES_UPSERT'],
                    true
                )
            )
        ) {
            return response()->json([
                'ok' => true,
                'ignored' => true,
            ]);
        }

        if (
            ! is_string($remote)
            || ! preg_match(
                '/^\+?(\d{9,15})(?:@s\.whatsapp\.net)?$/',
                $remote,
                $match
            )
        ) {
            return response()->json([
                'ok' => true,
                'ignored' => true,
            ]);
        }

        $text = data_get($payload, 'data.message.conversation')
            ?? data_get($payload, 'data.message.extendedTextMessage.text')
            ?? data_get($payload, 'data.text');

        if (! is_string($text) || trim($text) === '') {
            return response()->json([
                'ok' => true,
                'ignored' => true,
            ]);
        }

        $id = data_get($payload, 'data.key.id')
            ?? data_get($payload, 'data.id')
            ?? data_get($payload, 'id');

        abort_unless(
            is_string($id)
                && $id !== ''
                && strlen($id) <= 255,
            422,
            'ID pesan wajib tersedia.'
        );

        abort_if(
            mb_strlen($text) > 4000,
            422,
            'Pesan terlalu panjang.'
        );

        $message = WhatsAppMessage::firstOrCreate(
            ['provider_message_id' => $id],
            [
                'phone_number' => $match[1],
                'message_type' => 'text',
                'body' => $text,
                'payload' => $payload,
            ]
        );

        $processingStarted = microtime(true);

        DB::transaction(function () use ($message, $processor) {
            $message = WhatsAppMessage::whereKey($message->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($message->processed_at) {
                return;
            }

            $reply = $processor->handle(
                $message->phone_number,
                $message->body
            );

            $message->update([
                'processed_at' => now(),
                'reply_text' => $reply,
            ]);
        }, 3);

        $processingMs = round(
            (microtime(true) - $processingStarted) * 1000
        );

        $sendingStarted = microtime(true);

        $sent = Cache::lock(
            'whatsapp:reply:' . $message->id,
            30
        )->get(function () use ($message, $provider) {
            $message = $message->fresh();

            if ($message->replied_at || ! $message->reply_text) {
                return true;
            }

            if (! $provider->sendText(
                $message->phone_number,
                $message->reply_text
            )) {
                return false;
            }

            $message->update([
                'replied_at' => now(),
            ]);

            return true;
        });

        $sendingMs = round(
            (microtime(true) - $sendingStarted) * 1000
        );

        $totalMs = round(
            (microtime(true) - $requestStarted) * 1000
        );

        Log::info('WhatsApp performance', [
            'processing_ms' => $processingMs,
            'sending_ms' => $sendingMs,
            'total_ms' => $totalMs,
            'sent' => $sent,
        ]);

        return response()->json(
            [
                'ok' => $sent,
                'reply_pending' => ! $sent,
            ],
            $sent ? 200 : 502
        );
    }
}
