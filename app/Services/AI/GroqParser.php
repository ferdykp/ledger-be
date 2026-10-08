<?php

namespace App\Services\AI;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class GroqParser
{
    public function parse(User $user, string $text): ?array
    {
        $key = config('services.groq.key');
        if (! $key) {
            return null;
        }
        $context = [
            'accounts' => $user->accounts()->where('is_archived', false)->pluck('name')->all(),
            'categories' => $user->categories()->get(['name', 'type'])->toArray(),
        ];
        try {
            $response = Http::connectTimeout(3)->timeout(12)->withToken($key)
                ->post('https://api.groq.com/openai/v1/chat/completions', [
                    'model' => config('services.groq.model'), 'temperature' => 0,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => 'Parse Indonesian finance messages. Never invent an account or category. Return a JSON object with type, amount, account_name, category_name, note, date. Use null for unknown fields. Available values: '.json_encode($context)],
                        ['role' => 'user', 'content' => $text],
                    ],
                ]);
        } catch (ConnectionException $exception) {
            report($exception);

            return null;
        }
        if (! $response->successful()) {
            return null;
        }
        $content = data_get($response->json(), 'choices.0.message.content');
        if (! is_string($content)) {
            return null;
        }
        $data = json_decode($content, true);
        if (! is_array($data) || array_is_list($data)) {
            return null;
        }
        $validator = Validator::make($data, [
            'type' => ['nullable', 'in:income,expense,transfer'],
            'amount' => ['nullable', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999999.99'],
            'account_name' => ['nullable', 'string', 'max:255'],
            'category_name' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:500'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return $validator->fails() ? null : $validator->validated();
    }
}
