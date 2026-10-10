<?php

namespace App\Services\QuickAdd;

use App\Models\User;
use App\Services\WhatsApp\WhatsAppMessageFormat;
use Illuminate\Support\Str;

class QuickAddParser
{
    public function parse(User $user, string $text): array
    {
        $raw = trim($text);
        $lower = Str::lower($raw);
        $amount = $this->amount($lower);
        $accounts = $user->accounts()->where('is_archived', false)->get();
        $type = $this->type($lower);
        // Match positions in the message, never database insertion order.
        $matched = $accounts->map(function ($account) use ($lower) {
            $pattern = '/(?<![\pL\pN])'.preg_quote(Str::lower($account->name), '/').'(?![\pL\pN])/u';
            if (! preg_match($pattern, $lower, $match, PREG_OFFSET_CAPTURE)) {
                return null;
            }

            return ['account' => $account, 'offset' => $match[0][1], 'length' => strlen($match[0][0])];
        })->filter()->sort(fn ($a, $b) => ($a['offset'] <=> $b['offset']) ?: ($b['length'] <=> $a['length']));
        $end = -1;
        $matched = $matched->filter(function ($match) use (&$end) {
            if ($match['offset'] < $end) {
                return false;
            }
            $end = $match['offset'] + $match['length'];

            return true;
        })->values();
        $account = $matched->first()['account'] ?? null;
        $related = $type === 'transfer' ? ($matched->get(1)['account'] ?? null) : null;
        if ($type === 'transfer') {
            // Require a direction separator between source and destination.
            if ($matched->count() !== 2 || ! $related || ! preg_match('/\b(?:ke|to)\b/u', substr($lower, $matched[0]['offset'] + strlen(Str::lower($account->name)), $matched[1]['offset'] - $matched[0]['offset'] - strlen(Str::lower($account->name))))) {
                $account = $related = null;
            }
        }
        $ambiguousAccount = $type !== 'transfer' && $matched->count() > 1;
        if ($ambiguousAccount) {
            $account = null;
        }
        $category = $this->category($user, $lower, $type);
        $missing = [];
        if (! $amount) {
            $missing[] = 'amount';
        }
        if (! $account) {
            $missing[] = 'account';
        }
        if ($type === 'transfer' && ! $related) {
            $missing[] = 'to_account';
        }
        $confidence = 0.25 + ($amount ? 0.3 : 0) + ($account ? 0.25 : 0) + ($category ? 0.15 : 0) + ($type ? 0.05 : 0);

        return ['ambiguous_account' => $ambiguousAccount, 'type' => $type, 'amount' => $amount, 'account_id' => $account?->id, 'account_name' => $account?->name, 'related_account_id' => $related?->id, 'related_account_name' => $related?->name, 'category_id' => $category?->id, 'category_name' => $category?->name, 'note' => $this->note($raw), 'date' => WhatsAppMessageFormat::today()->toDateString(), 'missing' => $missing, 'confidence' => round(min(1, $confidence), 2), 'source' => 'rule'];
    }

    private function amount(string $text): ?float
    {
        preg_match_all('/(?<![\pL\pN.,])(?:rp\s*)?(-?\d+(?:[.,]\d+)*)\s*(juta|ribu|jt|rb|k)?(?![\pL\pN.,])/iu', $text, $matches, PREG_SET_ORDER);
        if (! $matches) {
            return null;
        }
        // Prefer a currency marker/unit over counts in descriptions ("makan 2 orang 50rb").
        $explicit = array_values(array_filter($matches, fn ($m) => ! empty($m[2]) || preg_match('/^rp/i', $m[0])));
        $candidates = $explicit ?: $matches;
        if (count($candidates) !== 1) {
            return null;
        }
        $match = $candidates[0];
        $number = $match[1];
        $unit = Str::lower($match[2] ?? '');
        if (preg_match('/^-?\d{1,3}(?:\.\d{3})+(?:,\d{1,2})?$/', $number)) {
            $number = str_replace(',', '.', str_replace('.', '', $number));
        } elseif (preg_match('/^-?\d{1,3}(?:,\d{3})+(?:\.\d{1,2})?$/', $number)) {
            $number = str_replace(',', '', $number);
        } elseif (preg_match('/^-?\d+(?:[.,]\d{1,2})?$/', $number)) {
            $number = str_replace(',', '.', $number);
        } else {
            return null;
        }
        $amount = (float) $number * match ($unit) {
            'jt', 'juta' => 1_000_000,
            'rb', 'ribu', 'k' => 1000,
            default => 1,
        };

        return $amount > 0 && $amount <= 9999999999999.99 ? round($amount, 2) : null;
    }

    private function type(string $s): string
    {
        if (preg_match('/\b(transfer|pindah|kirim)\b.*\b(ke|to)\b/u', $s)) {
            return 'transfer';
        }
        if (preg_match('/\b(gaji|salary|bonus|pemasukan|income|terima|masuk)\b/u', $s)) {
            return 'income';
        }

        return 'expense';
    }

    private function category(User $u, string $s, string $type)
    {
        if ($type === 'transfer') {
            return null;
        }
        $map = ['transport' => ['bensin', 'pertamina', 'shell', 'grab', 'gojek', 'parkir', 'tol'], 'makan' => ['makan', 'kopi', 'resto', 'restaurant', 'warung', 'cafe', 'hokben'], 'belanja' => ['belanja', 'tokopedia', 'shopee', 'mall'], 'tagihan' => ['pln', 'listrik', 'internet', 'indihome', 'tagihan'], 'gaji' => ['gaji', 'salary', 'bonus']];
        $cats = $u->categories()->where('type', $type)->get();
        foreach ($map as $hint => $words) {
            if (Str::contains($s, $words)) {
                $c = $cats->first(fn ($c) => Str::contains(Str::lower($c->name), $hint));
                if ($c) {
                    return $c;
                }
            }
        }

        return null;
    }

    private function note(string $s): string
    {
        return Str::limit(trim(preg_replace('/\s+/', ' ', $s)), 500, '');
    }
}
