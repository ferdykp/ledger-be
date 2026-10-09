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
    private const CONFIRM = [
        '1',
        'confirm',
        'konfirmasi',
        'ya',
        'yes',
    ];

    private const CANCEL = [
        '3',
        'cancel',
        'batal',
    ];

    public function __construct(
        private QuickAddParser $parser,
        private GroqParser $groq,
        private TransactionService $transactions,
        private WhatsAppCommandService $commands,
    ) {}

    public function handle(string $phone, string $text): string
    {
        return DB::transaction(function () use ($phone, $text) {
            $connection = WhatsAppConnection::where(
                'phone_number',
                $phone
            )->where('status', 'connected')->first();

            if (! $connection || preg_match('/^LINK\s+/i', trim($text))) {
                return "🔐 *WHATSAPP BELUM TERHUBUNG*\n\n"
                    . "Hubungkan nomor melalui menu "
                    . "*Pengaturan > WhatsApp Ledger*.";
            }

            $user = User::whereKey($connection->user_id)
                ->lockForUpdate()
                ->firstOrFail();

            $connection = WhatsAppConnection::whereKey($connection->id)
                ->where('phone_number', $phone)
                ->where('status', 'connected')
                ->lockForUpdate()
                ->first();

            if (! $connection) {
                return "⚠️ Koneksi WhatsApp telah diputuskan.\n"
                    . "Silakan verifikasi ulang melalui Ledger.";
            }

            $connection->update([
                'last_message_at' => now(),
            ]);

            $session = ConversationSession::firstOrCreate(
                ['user_id' => $user->id],
                ['channel' => 'whatsapp', 'state' => 'idle']
            );

            $session = ConversationSession::whereKey($session->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($session->expires_at?->isPast()) {
                $this->clear($session);
            }

            $command = Str::lower(trim($text));

            $informationalReply = $this->commands->respond(
                $user,
                $command
            );

            if ($informationalReply !== null) {
                return $informationalReply;
            }

            if ($session->state === 'waiting_confirmation') {
                return $this->confirmation(
                    $user,
                    $session,
                    $command
                );
            }

            if (in_array(
                $command,
                [...self::CONFIRM, ...self::CANCEL],
                true
            )) {
                return "ℹ️ *TIDAK ADA TRANSAKSI TERTUNDA*\n\n"
                    . "Kirim detail transaksi baru untuk dicatat.\n"
                    . "Ketik *bantuan* untuk melihat contoh.";
            }

            return $this->draft(
                $user,
                $session,
                trim($text)
            );
        }, 3);
    }

    private function draft(
        User $user,
        ConversationSession $session,
        string $text
    ): string {
        $accounts = $user->accounts()
            ->where('is_archived', false)
            ->get();

        $draft = $this->parser->parse($user, $text);

        /*
         * Deteksi pemasukan eksplisit.
         *
         * Contoh:
         * - uang sate 141rb masuk ke bca
         * - gaji 5jt masuk bca
         * - terima transfer 200rb dari teman ke bca
         *
         * Hanya ubah jenis transaksi apabila kalimat
         * jelas menunjukkan penerimaan uang.
         */
        if ($this->isIncomingMoney($text)) {
            $draft['type'] = 'income';

            // Kategori pengeluaran tidak boleh dipakai
            // untuk transaksi pemasukan.
            if (! empty($draft['category_id'])) {
                $category = $user->categories()
                    ->whereKey($draft['category_id'])
                    ->where('type', 'income')
                    ->first();

                if (! $category) {
                    $draft['category_id'] = null;
                    $draft['category_name'] = null;
                }
            }

            $account = $this->findIncomingAccount(
                $accounts,
                $text
            );

            if ($account) {
                $draft['account_id'] = $account->id;
            }

            // Jika field inti sudah lengkap, tidak perlu
            // memanggil Groq hanya karena confidence rendah.
            if (
                ! empty($draft['amount'])
                && ! empty($draft['account_id'])
            ) {
                $draft['confidence'] = 1;
            }

            $draft['source'] = 'incoming-rule';
        }

        /*
         * Transfer antar-dompet tetap menggunakan
         * QuickAddParser untuk menentukan arah transfer.
         */
        if (
            $draft['type'] !== 'transfer'
            && empty($draft['account_id'])
            && preg_match(
                '/\b\d[\d.,]*\s*(?:rb|ribu|k|jt|juta)?\s+'
                    . '(?:(?:masuk|dari|pakai|via|ke|di)\s+)?'
                    . '([\pL][\pL\pN ._-]{0,39})$/iu',
                $text,
                $match
            )
        ) {
            $available = $accounts->pluck('name')->implode(', ');

            return "⚠️ *DOMPET TIDAK DITEMUKAN*\n\n"
                . "Periksa nama dompet: *" . trim($match[1]) . "*\n\n"
                . "Dompet tersedia:\n"
                . ($available ?: 'Belum ada dompet aktif');
        }

        /*
         * Groq hanya digunakan jika data inti belum
         * lengkap atau parser tidak yakin.
         *
         * Jangan mengubah arah transfer menggunakan AI.
         */
        $needsAi = $draft['type'] !== 'transfer'
            && (
                empty($draft['amount'])
                || empty($draft['account_id'])
                || (
                    ($draft['confidence'] ?? 0) < 0.75
                    && ! $this->isIncomingMoney($text)
                )
            );

        if ($needsAi) {
            $draft = $this->mergeAi(
                $user,
                $draft,
                $text
            );
        }

        $payload = $this->payload($draft);

        $validator = Validator::make(
            $payload,
            TransactionRules::forUser(
                $user,
                $payload['type']
            )
        );

        if ($validator->fails()) {
            return "⚠️ *TRANSAKSI BELUM VALID*\n\n"
                . "Periksa nominal, kategori, tanggal, "
                . "atau nama dompet.\n\n"
                . "*Contoh:*\n"
                . "• bensin 50rb bca\n"
                . "• uang sate 141rb masuk ke bca\n"
                . "• transfer 50rb bca ke gopay\n\n"
                . "*Dompet tersedia:*\n"
                . ($accounts->pluck('name')->implode(', ') ?: 'Belum ada');
        }

        $account = $accounts->firstWhere(
            'id',
            $payload['account_id']
        );

        $target = $accounts->firstWhere(
            'id',
            $payload['to_account_id']
        );

        if (
            ! $account
            || (
                $payload['type'] === 'transfer'
                && ! $target
            )
        ) {
            return "⚠️ *DOMPET TIDAK TERSEDIA*\n\n"
                . "Gunakan dompet aktif yang terdaftar "
                . "di aplikasi Ledger.";
        }

        $draft['account_name'] = $account->name;
        $draft['related_account_name'] = $target?->name;

        $session->update([
            'state' => 'waiting_confirmation',
            'context' => $draft,
            'expires_at' => now()->addMinutes(30),
        ]);

        $typeLabel = match ($payload['type']) {
            'income' => '💰 PEMASUKAN',
            'expense' => '💸 PENGELUARAN',
            'transfer' => '🔄 TRANSFER ANTAR-DOMPET',
            default => 'TRANSAKSI',
        };

        $amount = $this->money($payload['amount']);

        $date = \Carbon\Carbon::parse(
            $payload['date']
        )->format('d/m/Y');

        $walletInfo = $payload['type'] === 'transfer'
            ? "Dari: {$account->name}\nKe: {$target->name}"
            : "Dompet: {$account->name}";

        $category = $draft['category_name']
            ?? 'Tanpa kategori';

        $note = trim((string) ($payload['note'] ?? ''));

        return "📝 *KONFIRMASI TRANSAKSI*\n\n"
            . "*{$typeLabel}*\n"
            . "Nominal: *{$amount}*\n"
            . "{$walletInfo}\n"
            . "Kategori: {$category}\n"
            . "Tanggal: {$date}\n"
            . ($note !== '' ? "Catatan: {$note}\n" : '')
            . "\n━━━━━━━━━━━━━━━━\n\n"
            . "Pastikan detail transaksi sudah benar.\n\n"
            . "Balas *1* untuk menyimpan\n"
            . "Balas *3* untuk membatalkan\n\n"
            . "_Konfirmasi berlaku selama 30 menit._";
    }

    /*
     * Membedakan penerimaan uang dari transfer
     * antar-dompet.
     */
    private function isIncomingMoney(string $text): bool
    {
        $text = Str::lower(trim($text));

        // Transfer eksplisit dari dompet sendiri
        // tidak boleh diubah menjadi pemasukan.
        if (preg_match(
            '/\b(?:transfer|pindah(?:kan)?\s+saldo)\s+'
                . '\d[\d.,]*\s*(?:rb|ribu|k|jt|juta)?\s+'
                . '[\pL\pN_-]+\s+ke\s+[\pL\pN_-]+/iu',
            $text
        )) {
            return false;
        }

        return (bool) preg_match(
            '/\b(?:'
                . 'masuk(?:\s+ke)?'
                . '|pemasukan'
                . '|pendapatan'
                . '|terima\s+transfer'
                . '|ditransfer'
                . '|uang\s+masuk'
                . '|gaji'
                . ')\b/iu',
            $text
        );
    }

    /*
     * Cari dompet tujuan berdasarkan nama dompet
     * aktif yang benar-benar dimiliki user.
     */
    private function findIncomingAccount(
        $accounts,
        string $text
    ) {
        $normalized = Str::lower(trim($text));

        // Utamakan nama terpanjang agar nama dompet
        // yang mirip tidak salah terpilih.
        $sorted = $accounts->sortByDesc(
            fn($account) => mb_strlen($account->name)
        );

        foreach ($sorted as $account) {
            $name = Str::lower(trim($account->name));

            if ($name === '') {
                continue;
            }

            $escaped = preg_quote($name, '/');

            if (preg_match(
                '/(?:^|[^\pL\pN])'
                    . $escaped
                    . '(?=$|[^\pL\pN])/iu',
                $normalized
            )) {
                return $account;
            }
        }

        return null;
    }

    private function confirmation(
        User $user,
        ConversationSession $session,
        string $command
    ): string {
        if (in_array($command, self::CANCEL, true)) {
            $this->clear($session);

            return "❌ *TRANSAKSI DIBATALKAN*\n\n"
                . "Transaksi tidak disimpan.\n"
                . "Saldo dompet tidak berubah.\n\n"
                . "Kirim pesan baru untuk mencatat transaksi.";
        }

        if (! in_array($command, self::CONFIRM, true)) {
            return "⏳ *MENUNGGU KONFIRMASI*\n\n"
                . "Masih ada transaksi yang belum disimpan.\n\n"
                . "Balas *1* untuk menyimpan\n"
                . "Balas *3* untuk membatalkan.";
        }

        $payload = $this->payload(
            $session->context ?? []
        );

        $validator = Validator::make(
            $payload,
            TransactionRules::forUser(
                $user,
                $payload['type']
            )
        );

        $ids = array_filter([
            $payload['account_id'],
            $payload['to_account_id'],
        ]);

        $active = $user->accounts()
            ->whereIn('id', $ids)
            ->where('is_archived', false)
            ->count();

        if (
            $validator->fails()
            || $active !== count(array_unique($ids))
        ) {
            $this->clear($session);

            return "⚠️ *TRANSAKSI TIDAK DAPAT DISIMPAN*\n\n"
                . "Detail transaksi tidak valid "
                . "atau dompet sudah berubah.\n\n"
                . "Saldo belum diubah.\n"
                . "Silakan kirim ulang transaksi.";
        }

        $transaction = $this->transactions
            ->createTransaction(
                $user,
                $validator->validated()
            );

        $this->clear($session);

        $typeLabel = match ($transaction->type) {
            'income' => 'Pemasukan',
            'expense' => 'Pengeluaran',
            'transfer' => 'Transfer antar-dompet',
            default => 'Transaksi',
        };

        return "✅ *TRANSAKSI BERHASIL DISIMPAN*\n\n"
            . "Jenis: {$typeLabel}\n"
            . "Nominal: *" . $this->money($transaction->amount) . "*\n"
            . "Dompet: {$transaction->account->name}\n\n"
            . "Transaksi sudah tercatat di Ledger.\n\n"
            . "Ketik *saldo {$transaction->account->name}* "
            . "untuk melihat saldo terbaru.";
    }

    private function payload(array $draft): array
    {
        return [
            'type' => $draft['type'] ?? null,
            'account_id' => $draft['account_id'] ?? null,
            'to_account_id' => ($draft['type'] ?? null) === 'transfer'
                ? ($draft['related_account_id'] ?? null)
                : null,
            'category_id' => $draft['category_id'] ?? null,
            'amount' => $draft['amount'] ?? null,
            'date' => $draft['date'] ?? now()->toDateString(),
            'note' => $draft['note'] ?? null,
        ];
    }

    private function clear(ConversationSession $session): void
    {
        $session->update([
            'state' => 'idle',
            'context' => null,
            'expires_at' => null,
        ]);
    }

    private function mergeAi(
        User $user,
        array $draft,
        string $text
    ): array {
        $ai = $this->groq->parse($user, $text);

        if (! $ai) {
            return $draft;
        }

        if (empty($draft['amount'])) {
            $draft['amount'] = $ai['amount'] ?? null;
        }

        if (
            empty($draft['account_id'])
            && ! empty($ai['account_name'])
        ) {
            $account = $user->accounts()
                ->where('is_archived', false)
                ->whereRaw(
                    'LOWER(name) = ?',
                    [Str::lower($ai['account_name'])]
                )
                ->first();

            $draft['account_id'] = $account?->id;
        }

        // Jangan izinkan AI mengubah jenis transaksi
        // yang sudah ditentukan oleh parser lokal.
        if (
            empty($draft['category_id'])
            && ! empty($ai['category_name'])
        ) {
            $category = $user->categories()
                ->where('type', $draft['type'])
                ->whereRaw(
                    'LOWER(name) = ?',
                    [Str::lower($ai['category_name'])]
                )
                ->first();

            $draft['category_id'] = $category?->id;
            $draft['category_name'] = $category?->name;
        }

        $draft['source'] = 'rule+groq';

        return $draft;
    }

    private function money(float|int|string|null $amount): string
    {
        return 'Rp' . number_format(
            (float) $amount,
            0,
            ',',
            '.'
        );
    }
}
