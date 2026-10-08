<?php

namespace App\Services\WhatsApp;

use App\Models\ConversationSession;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Services\AI\GroqParser;
use App\Services\QuickAdd\QuickAddParser;
use App\Services\TransactionService;
use App\Support\TransactionRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class WhatsAppMessageProcessor
{
    private const CONFIRM = ['1', 'confirm', 'konfirmasi', 'ya', 'yes'];

    private const CANCEL = ['3', 'cancel', 'batal'];

    public function __construct(
        private QuickAddParser $parser,
        private GroqParser $groq,
        private TransactionService $transactions,
    ) {}

    /** Return a reply for durable delivery; never send network replies inside a financial transaction. */
    public function handle(string $phone, string $text): string
    {
        return DB::transaction(function () use ($phone, $text) {
            $connection = WhatsAppConnection::where('phone_number', $phone)->where('status', 'connected')->first();
            if (! $connection || preg_match('/^LINK\s+/i', trim($text))) {
                return 'Hubungkan nomor melalui kode OTP di Pengaturan > WhatsApp Ledger.';
            }
            // Serialize session creation, draft changes and confirmation per user.
            $user = User::whereKey($connection->user_id)->lockForUpdate()->firstOrFail();
            $connection = WhatsAppConnection::whereKey($connection->id)->where('phone_number', $phone)->where('status', 'connected')->lockForUpdate()->first();
            if (! $connection) {
                return 'Koneksi WhatsApp telah diputuskan. Verifikasi ulang melalui Ledger.';
            }
            $connection->update(['last_message_at' => now()]);
            $session = ConversationSession::firstOrCreate(['user_id' => $user->id], ['channel' => 'whatsapp', 'state' => 'idle']);
            $session = ConversationSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($session->expires_at?->isPast()) {
                $this->clear($session);
            }

            $command = Str::lower(trim($text));
            if (in_array($command, ['bantuan', 'help', 'panduan', 'halo ledger'], true)) {
                return "📒 Panduan Ledger\n\n• bensin 50rb bca\n• makan 35k gopay\n• gaji 1.000.000 bca\n• transfer 50rb bca ke gopay\n\nGunakan nama dompet di Ledger. Balas 1 untuk simpan atau 3 untuk batal setelah ringkasan muncul.";
            }
            if ($session->state === 'waiting_confirmation') {
                return $this->confirmation($user, $session, $command);
            }
            if (in_array($command, [...self::CONFIRM, ...self::CANCEL], true)) {
                return 'Tidak ada transaksi yang menunggu konfirmasi. Kirim detail transaksi baru.';
            }

            return $this->draft($user, $session, trim($text));
        }, 3);
    }

    private function draft(User $user, ConversationSession $session, string $text): string
    {
        $accounts = $user->accounts()->where('is_archived', false)->get();
        $draft = $this->parser->parse($user, $text);
        // A transfer contains two wallets: never interpret its entire tail as one wallet.
        if ($draft['type'] !== 'transfer' && ! $draft['account_id'] && preg_match('/\b\d[\d.,]*\s*(?:rb|ribu|k|jt|juta)?\s+(?:(?:masuk|dari|pakai|via|ke|di)\s+)?([\pL][\pL\pN ._-]{0,39})$/iu', $text, $match)) {
            $available = $accounts->pluck('name')->implode(', ');

            return "Dompet {$match[1]} belum tersedia. Transaksi belum dicatat.\nDompet tersedia: ".($available ?: 'Belum ada').'.';
        }
        // Transfer direction is determined only by explicit local parsing, not AI guesses.
        if ($draft['type'] !== 'transfer' && ($draft['confidence'] < 0.75 || count($draft['missing']))) {
            $draft = $this->mergeAi($user, $draft, $text);
        }
        $payload = $this->payload($draft);
        if (Validator::make($payload, TransactionRules::forUser($user, $payload['type']))->fails()) {
            return "Nominal, kategori, tanggal, atau dompet belum valid. Transaksi belum dicatat.\nContoh: bensin 50rb bca; transfer 50rb bca ke gopay.\nDompet tersedia: ".$accounts->pluck('name')->implode(', ');
        }
        $account = $accounts->firstWhere('id', $payload['account_id']);
        $target = $accounts->firstWhere('id', $payload['to_account_id']);
        if (! $account || ($payload['type'] === 'transfer' && ! $target)) {
            return 'Dompet tidak tersedia. Gunakan dompet aktif di Ledger.';
        }
        $draft['account_name'] = $account->name;
        $draft['related_account_name'] = $target?->name;
        $session->update(['state' => 'waiting_confirmation', 'context' => $draft, 'expires_at' => now()->addMinutes(30)]);
        $wallets = $account->name.($target ? ' → '.$target->name : '');
        $amount = number_format($payload['amount'], 2, ',', '.');

        return "Konfirmasi transaksi:\n".strtoupper($payload['type'])." • Rp{$amount}\n{$wallets}\n{$payload['date']} • ".($draft['category_name'] ?: 'Tanpa kategori')."\n{$payload['note']}\n\nBalas 1 / CONFIRM untuk simpan, atau 3 / CANCEL untuk batal.";
    }

    private function confirmation(User $user, ConversationSession $session, string $command): string
    {
        if (in_array($command, self::CANCEL, true)) {
            $this->clear($session);

            return 'Transaksi dibatalkan.';
        }
        if (! in_array($command, self::CONFIRM, true)) {
            return 'Balas 1 / CONFIRM untuk simpan atau 3 / CANCEL untuk batal.';
        }
        $payload = $this->payload($session->context ?? []);
        $validator = Validator::make($payload, TransactionRules::forUser($user, $payload['type']));
        $ids = array_filter([$payload['account_id'], $payload['to_account_id']]);
        $active = $user->accounts()->whereIn('id', $ids)->where('is_archived', false)->count();
        if ($validator->fails() || $active !== count(array_unique($ids))) {
            $this->clear($session);

            return 'Detail transaksi tidak valid atau sudah berubah. Kirim ulang transaksi; saldo belum diubah.';
        }
        $transaction = $this->transactions->createTransaction($user, $validator->validated());
        $this->clear($session);

        return '✅ Tersimpan: '.strtoupper($transaction->type).' Rp'.number_format($transaction->amount, 2, ',', '.').' • '.$transaction->account->name;
    }

    private function payload(array $draft): array
    {
        return [
            'type' => $draft['type'] ?? null,
            'account_id' => $draft['account_id'] ?? null,
            'to_account_id' => ($draft['type'] ?? null) === 'transfer' ? ($draft['related_account_id'] ?? null) : null,
            'category_id' => $draft['category_id'] ?? null,
            'amount' => $draft['amount'] ?? null,
            'date' => $draft['date'] ?? now()->toDateString(),
            'note' => $draft['note'] ?? null,
        ];
    }

    private function clear(ConversationSession $session): void
    {
        $session->update(['state' => 'idle', 'context' => null, 'expires_at' => null]);
    }

    private function mergeAi(User $user, array $draft, string $text): array
    {
        $ai = $this->groq->parse($user, $text);
        if (! $ai) {
            return $draft;
        }
        if (! $draft['amount']) {
            $draft['amount'] = $ai['amount'] ?? null;
        }
        if (! $draft['account_id'] && ! empty($ai['account_name'])) {
            $account = $user->accounts()->where('is_archived', false)->whereRaw('LOWER(name) = ?', [Str::lower($ai['account_name'])])->first();
            $draft['account_id'] = $account?->id;
        }
        // Do not let fallback change an already classified transaction's type or direction.
        if (! $draft['category_id'] && ! empty($ai['category_name'])) {
            $category = $user->categories()->where('type', $draft['type'])->whereRaw('LOWER(name) = ?', [Str::lower($ai['category_name'])])->first();
            $draft['category_id'] = $category?->id;
            $draft['category_name'] = $category?->name;
        }
        $draft['source'] = 'rule+groq';

        return $draft;
    }
}
