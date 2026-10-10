<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EvolutionProvider
{
    public function sendText(string $phone, string $text): bool
    {
        $base = rtrim((string) config('services.evolution.url'), '/');
        $instance = (string) config('services.evolution.instance');
        $key = (string) config('services.evolution.key');

        if (! $base || ! $instance || ! $key) {
            Log::error('Evolution API configuration incomplete', [
                'has_url' => $base !== '',
                'has_instance' => $instance !== '',
                'has_key' => $key !== '',
            ]);

            return false;
        }

        try {
            $response = Http::connectTimeout(3)
                ->timeout(10)
                ->withHeaders([
                    'apikey' => $key,
                ])
                ->post(
                    $base.'/message/sendText/'.rawurlencode($instance),
                    [
                        'number' => $phone,
                        'text' => $text,
                    ]
                );

            if (! $response->successful()) {
                Log::warning('Evolution API sendText failed', [
                    'instance' => $instance,
                    'phone_suffix' => substr($phone, -4),
                    'http_status' => $response->status(),
                    // Jangan log body respons mentah:
                    // bisa mengandung informasi sensitif.
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('Evolution API sendText exception', [
                'instance' => $instance,
                'exception_type' => get_class($e),
                'message' => 'Request pengiriman WhatsApp gagal.',
            ]);

            return false;
        }
    }
}
