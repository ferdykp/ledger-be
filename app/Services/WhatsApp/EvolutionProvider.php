<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class EvolutionProvider
{
    public function sendText(string $phone, string $text): bool
    {
        $base = rtrim((string) config('services.evolution.url'), '/');
        $instance = config('services.evolution.instance');
        $key = config('services.evolution.key');
        if (! $base || ! $instance || ! $key) {
            return false;
        }
        try {
            return Http::connectTimeout(3)->timeout(10)->withHeaders(['apikey' => $key])
                ->post($base . '/message/sendText/' . rawurlencode($instance), ['number' => $phone, 'text' => $text])->successful();
        } catch (ConnectionException $exception) {
            report($exception);

            return false;
        }
    }
}
