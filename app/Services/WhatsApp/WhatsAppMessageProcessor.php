<?php

namespace App\Services\WhatsApp;

use App\Models\{ConversationSession, WhatsAppConnection, WhatsAppPairingCode, User};
use App\Services\AI\GroqParser;
use App\Services\QuickAdd\QuickAddParser;
use App\Services\TransactionService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class WhatsAppMessageProcessor
{
    public function __construct(
        private QuickAddParser $parser,
        private GroqParser $groq,
        private EvolutionProvider $provider,
        private TransactionService $transactions
    ) {}

    public function handle(string $phone, string $text): void
    {
        $text = trim($text);
        if (preg_match('/^LINK\s+(\d{6})$/i', $text, $matches)) {
            $this->link($phone, $matches[1]);
            return;
        }

        $conn = WhatsAppConnection::with('user')
            ->where('phone_number', $phone)->where('status', 'connected')->first();
        if (!$conn) {
            $this->provider->sendText($phone, 'Nomor ini belum terhubung ke Ledger. Buka Settings > WhatsApp di Ledger untuk verifikasi nomor.');
            return;
        }
        $conn->update(['last_message_at' => now()]);
        $user = $conn->user;
        $session = ConversationSession::firstOrCreate(
            ['user_id' => $conn->user_id],
            ['channel' => 'whatsapp', 'state' => 'idle']
        );
        if ($session->expires_at && $session->expires_at->isPast()) {
            $session->update(['state' => 'idle', 'context' => null, 'expires_at' => null]);
        }
        if (in_array(Str::lower($text), ['bantuan', 'help', 'panduan'], true)) {
            $this->provider->sendText($phone, "📒 Panduan Ledger\n\nPengeluaran:\n• bensin 50rb bca\n• makan 35k gopay\n\nPemasukan:\n• gaji 8jt masuk bca\n\nGunakan nama dompet yang tersedia di aplikasi. Setelah menerima ringkasan, balas 1 untuk simpan atau 3 untuk batal.");
            return;
        }
        if ($session->state === 'waiting_confirmation') {
            $this->confirmation($user, $session, $text, $phone);
            return;
        }

        $accounts = $user->accounts()->where('is_archived', false)->get();
        $draft = $this->parser->parse($user, $text);
        // Jika nama dompet eksplisit terdeteksi tetapi tidak ada, jangan fallback ke dompet lain.
        $requested = $this->requestedWallet($text);
        if ($requested !== null) {
            $wallet = $accounts->first(fn($a) => $this->normalize($a->name) === $this->normalize($requested));
            if (!$wallet) {
                $available = $accounts->pluck('name')->map(fn($n) => '• ' . $n)->implode("\n");
                $this->provider->sendText($phone, "⚠️ Dompet *{$requested}* belum tersedia di akun Ledger kamu.\n\nTransaksi belum dicatat.\n\nDompet tersedia:\n" . ($available ?: 'Belum ada dompet.') . "\n\nBuat dompet di aplikasi Ledger atau kirim ulang menggunakan dompet yang tersedia.");
                return;
            }
            $draft['account_id'] = $wallet->id;
            $draft['account_name'] = $wallet->name;
            $draft['missing'] = array_values(array_diff($draft['missing'], ['account']));
        }
        if ($draft['confidence'] < 0.75 || count($draft['missing'])) {
            $draft = $this->mergeAi($user, $draft, $text);
        }
        // Wajib validasi ulang setelah AI, termasuk kepemilikan akun.
        $account = $accounts->firstWhere('id', $draft['account_id'] ?? null);
        if (!$account || !$draft['amount'] || ($draft['type'] === 'transfer' && !$draft['related_account_id'])) {
            $available = $accounts->pluck('name')->implode(', ');
            $this->provider->sendText($phone, "Saya belum bisa memastikan nominal atau dompet.\nDompet tersedia: " . ($available ?: 'Belum ada') . "\n\nContoh: bensin 50rb bca");
            return;
        }
        $draft['account_name'] = $account->name;
        if ($draft['type'] === 'transfer') {
            $target = $accounts->firstWhere('id', $draft['related_account_id']);
            if (!$target || $target->id === $account->id) {
                $this->provider->sendText($phone, 'Dompet tujuan transfer tidak valid. Gunakan dua dompet berbeda yang tersedia di Ledger.');
                return;
            }
        }
        $session->update(['state' => 'waiting_confirmation', 'context' => $draft, 'expires_at' => now()->addMinutes(30)]);
        $category = $draft['category_name'] ?: 'Tanpa kategori';
        $this->provider->sendText($phone, "Konfirmasi transaksi:\n" . strtoupper($draft['type']) . ' • Rp' . number_format($draft['amount'], 0, ',', '.') . "\n{$draft['account_name']} • {$category}\n{$draft['note']}\n\nBalas 1 / CONFIRM untuk simpan, atau 3 / CANCEL untuk batal.");
    }

    // Konvensi V1: nama dompet eksplisit di akhir kalimat, setelah nominal.
    // Jika tidak ada token setelah nominal, minta klarifikasi lewat jalur normal.
    private function requestedWallet(string $text): ?string
    {
        $text = trim($text);
        if (!preg_match('/\b\d+(?:[.,]\d+)?\s*(?:rb|ribu|k|jt|juta)?\b\s+(.+)$/iu', $text, $m)) return null;
        $tail = trim($m[1]);
        $tail = preg_replace('/^(?:masuk|dari|pakai|via|ke|di)\s+/iu', '', $tail);
        // Jangan tebak dompet dari deskripsi panjang; parser biasa menangani kasus ambigu.
        if (!$tail || !preg_match('/^[\pL\pN][\pL\pN ._-]{0,39}$/u', $tail)) return null;
        return trim($tail);
    }

    private function normalize(string $value): string
    {
        return Str::lower(trim(preg_replace('/\s+/u', ' ', $value)));
    }

    private function confirmation(User $user, ConversationSession $session, string $text, string $phone): void
    {
        if (in_array(Str::lower($text), ['3', 'cancel', 'batal'], true)) {
            $session->update(['state' => 'idle', 'context' => null, 'expires_at' => null]);
            $this->provider->sendText($phone, 'Transaksi dibatalkan.');
            return;
        }
        if (!in_array(Str::lower($text), ['1', 'confirm', 'konfirmasi', 'ya', 'yes'], true)) {
            $this->provider->sendText($phone, 'Balas 1 / CONFIRM untuk simpan atau 3 / CANCEL untuk batal.');
            return;
        }
        $draft = $session->context;
        $account = $user->accounts()->where('is_archived', false)->find($draft['account_id'] ?? null);
        $target = ($draft['type'] ?? null) === 'transfer'
            ? $user->accounts()->where('is_archived', false)->find($draft['related_account_id'] ?? null)
            : null;
        if (!$account || (($draft['type'] ?? null) === 'transfer' && (!$target || $target->id === $account->id))) {
            $session->update(['state' => 'idle', 'context' => null, 'expires_at' => null]);
            $this->provider->sendText($phone, 'Dompet transaksi tidak tersedia lagi. Silakan kirim ulang transaksi.');
            return;
        }
        $tx = $this->transactions->createTransaction($user, [
            'type' => $draft['type'],
            'account_id' => $account->id,
            'to_account_id' => $target?->id,
            'related_account_id' => $target?->id,
            'category_id' => $draft['category_id'] ?? null,
            'amount' => $draft['amount'],
            'note' => $draft['note'] ?? null,
            'date' => $draft['date'] ?? now()->toDateString(),
        ]);
        $session->update(['state' => 'idle', 'context' => null, 'expires_at' => null]);
        $this->provider->sendText($phone, '✅ Tersimpan: ' . strtoupper($tx->type) . ' Rp' . number_format($tx->amount, 0, ',', '.') . ' • ' . $tx->account->name);
    }

    private function link(string $phone, string $code): void
    {
        $rows = WhatsAppPairingCode::whereNull('used_at')->where('expires_at', '>', now())->latest()->limit(50)->get();
        $pair = $rows->first(fn($p) => Hash::check($code, $p->code_hash));
        if (!$pair) {
            $this->provider->sendText($phone, 'Kode pairing tidak valid atau sudah kedaluwarsa.');
            return;
        }
        WhatsAppConnection::updateOrCreate(['user_id' => $pair->user_id], [
            'phone_number' => $phone,
            'provider' => 'evolution',
            'status' => 'connected',
            'verified_at' => now(),
        ]);
        $pair->update(['used_at' => now()]);
        $this->provider->sendText($phone, "✅ WhatsApp terhubung ke Ledger.\nKetik bantuan untuk panduan atau coba: bensin 50rb bca");
    }

    private function mergeAi(User $user, array $draft, string $text): array
    {
        $ai = $this->groq->parse($user, $text);
        if (!$ai) return $draft;
        if (!$draft['amount'] && is_numeric($ai['amount'] ?? null)) $draft['amount'] = (float) $ai['amount'];
        if (!$draft['account_id'] && !empty($ai['account_name'])) {
            $account = $user->accounts()->where('is_archived', false)
                ->whereRaw('LOWER(name) = ?', [Str::lower($ai['account_name'])])->first();
            if ($account) {
                $draft['account_id'] = $account->id;
                $draft['account_name'] = $account->name;
            }
        }
        if (!$draft['category_id'] && !empty($ai['category_name'])) {
            $category = $user->categories()->where('type', $draft['type'])
                ->whereRaw('LOWER(name) = ?', [Str::lower($ai['category_name'])])->first();
            if ($category) {
                $draft['category_id'] = $category->id;
                $draft['category_name'] = $category->name;
            }
        }
        if (in_array($ai['type'] ?? null, ['income', 'expense'], true)) $draft['type'] = $ai['type'];
        if (!empty($ai['note'])) $draft['note'] = $ai['note'];
        $draft['source'] = 'rule+groq';
        return $draft;
    }
}
