<?php

namespace App\Services\WhatsApp;

use App\Models\ConversationSession;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Services\AI\GroqParser;
use App\Services\QuickAdd\QuickAddParser;
use App\Services\TransactionService;
use App\Support\TransactionRules;
use Carbon\Carbon;
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

    /**
     * Process an incoming WhatsApp message.
     *
     * Return the reply text for delivery by the webhook.
     * Do not send network requests inside the transaction.
     */
    public function handle(string $phone, string $text): string
    {
        return DB::transaction(function () use ($phone, $text) {

            $connection = WhatsAppConnection::where(
                'phone_number',
                $phone
            )
                ->where('status', 'connected')
                ->first();

            if (
                ! $connection ||
                preg_match('/^LINK\s+/i', trim($text))
            ) {
                return "🔐 *WhatsApp Belum Terhubung*\n\n"
                    . "Nomor ini belum terhubung ke Ledger.\n\n"
                    . "Buka *Ledger → Pengaturan → WhatsApp* "
                    . "untuk menghubungkan nomor melalui verifikasi OTP.";
            }

            /*
            |--------------------------------------------------------------------------
            | Lock User and Connection
            |--------------------------------------------------------------------------
            */

            $user = User::whereKey(
                $connection->user_id
            )
                ->lockForUpdate()
                ->firstOrFail();

            $connection = WhatsAppConnection::whereKey(
                $connection->id
            )
                ->where('phone_number', $phone)
                ->where('status', 'connected')
                ->lockForUpdate()
                ->first();

            if (! $connection) {
                return "🔌 *Koneksi Terputus*\n\n"
                    . "Nomor WhatsApp kamu sudah tidak terhubung.\n"
                    . "Silakan lakukan verifikasi ulang melalui Ledger.";
            }

            $connection->update([
                'last_message_at' => now(),
            ]);

            /*
            |--------------------------------------------------------------------------
            | Conversation Session
            |--------------------------------------------------------------------------
            */

            $session = ConversationSession::firstOrCreate(
                [
                    'user_id' => $user->id,
                ],
                [
                    'channel' => 'whatsapp',
                    'state' => 'idle',
                ]
            );

            $session = ConversationSession::whereKey(
                $session->id
            )
                ->lockForUpdate()
                ->firstOrFail();

            if ($session->expires_at?->isPast()) {
                $this->clear($session);
            }

            $command = Str::lower(trim($text));

            /*
            |--------------------------------------------------------------------------
            | Help Commands
            |--------------------------------------------------------------------------
            */

            if (in_array($command, [
                'bantuan',
                'help',
                'panduan',
                'halo ledger',
            ], true)) {
                return $this->helpMessage();
            }

            /*
            |--------------------------------------------------------------------------
            | Financial Information Commands
            |--------------------------------------------------------------------------
            |
            | Informational commands must not clear
            | or modify a pending transaction draft.
            |
            */

            $informationalReply = $this->commands->respond(
                $user,
                $command
            );

            if ($informationalReply !== null) {
                return $informationalReply;
            }

            /*
            |--------------------------------------------------------------------------
            | Pending Confirmation
            |--------------------------------------------------------------------------
            */

            if ($session->state === 'waiting_confirmation') {
                return $this->confirmation(
                    $user,
                    $session,
                    $command
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Confirmation Without Pending Draft
            |--------------------------------------------------------------------------
            */

            if (in_array($command, [
                ...self::CONFIRM,
                ...self::CANCEL,
            ], true)) {
                return "💬 *Belum Ada Transaksi*\n\n"
                    . "Tidak ada transaksi yang menunggu konfirmasi.\n\n"
                    . "Kirim detail transaksi baru untuk mulai mencatat.\n\n"
                    . "✍️ _Contoh: bensin 50rb bca_";
            }

            /*
            |--------------------------------------------------------------------------
            | Create Transaction Draft
            |--------------------------------------------------------------------------
            */

            return $this->draft(
                $user,
                $session,
                trim($text)
            );
        }, 3);
    }

    /**
     * Parse and validate a transaction draft.
     */
    private function draft(
        User $user,
        ConversationSession $session,
        string $text
    ): string {
        $accounts = $user->accounts()
            ->where('is_archived', false)
            ->get();

        $draft = $this->parser->parse(
            $user,
            $text
        );

        /*
        |--------------------------------------------------------------------------
        | Unknown Wallet Detection
        |--------------------------------------------------------------------------
        */

        if (
            $draft['type'] !== 'transfer'
            && ! $draft['account_id']
            && preg_match(
                '/\b\d[\d.,]*\s*(?:rb|ribu|k|jt|juta)?\s+(?:(?:masuk|dari|pakai|via|ke|di)\s+)?([\pL][\pL\pN ._-]{0,39})$/iu',
                $text,
                $match
            )
        ) {
            $available = $accounts
                ->pluck('name')
                ->implode(', ');

            $walletName = trim($match[1]);

            return "👛 *Dompet Tidak Ditemukan*\n\n"
                . "Dompet *{$walletName}* belum tersedia di Ledger.\n\n"
                . "💳 *Dompet tersedia:*\n"
                . ($available ?: 'Belum ada dompet aktif.')
                . "\n\n"
                . "Silakan gunakan dompet yang tersedia.\n"
                . "_Transaksi belum dicatat dan saldo tidak berubah._";
        }

        /*
        |--------------------------------------------------------------------------
        | AI Fallback
        |--------------------------------------------------------------------------
        |
        | AI may fill missing information but must not
        | override locally determined transfer direction.
        |
        */

        if (
            $draft['type'] !== 'transfer'
            && (
                $draft['confidence'] < 0.75
                || count($draft['missing'])
            )
        ) {
            $draft = $this->mergeAi(
                $user,
                $draft,
                $text
            );
        }

        $payload = $this->payload($draft);

        /*
        |--------------------------------------------------------------------------
        | Validate Transaction
        |--------------------------------------------------------------------------
        */

        $validator = Validator::make(
            $payload,
            TransactionRules::forUser(
                $user,
                $payload['type']
            )
        );

        if ($validator->fails()) {
            $available = $accounts
                ->pluck('name')
                ->implode(', ');

            return "⚠️ *Transaksi Belum Bisa Diproses*\n\n"
                . "Ada detail transaksi yang belum lengkap atau belum valid.\n\n"
                . "✍️ *Contoh penulisan:*\n"
                . "• bensin 50rb bca\n"
                . "• makan 35k gopay\n"
                . "• transfer 50rb bca ke gopay\n\n"
                . "💳 *Dompet tersedia:*\n"
                . ($available ?: 'Belum ada dompet aktif.')
                . "\n\n"
                . "_Transaksi belum disimpan._";
        }

        /*
        |--------------------------------------------------------------------------
        | Verify Active Wallets
        |--------------------------------------------------------------------------
        */

        $account = $accounts->firstWhere(
            'id',
            $payload['account_id']
        );

        $target = $accounts->firstWhere(
            'id',
            $payload['to_account_id']
        );

        if (
            ! $account ||
            (
                $payload['type'] === 'transfer'
                && ! $target
            )
        ) {
            return "👛 *Dompet Tidak Tersedia*\n\n"
                . "Dompet yang dipilih tidak ditemukan atau sudah diarsipkan.\n\n"
                . "Gunakan dompet aktif yang tersedia di Ledger.";
        }

        /*
        |--------------------------------------------------------------------------
        | Save Draft
        |--------------------------------------------------------------------------
        */

        $draft['account_name'] = $account->name;
        $draft['related_account_name'] = $target?->name;

        $session->update([
            'state' => 'waiting_confirmation',
            'context' => $draft,
            'expires_at' => now()->addMinutes(30),
        ]);

        /*
        |--------------------------------------------------------------------------
        | Confirmation Preview
        |--------------------------------------------------------------------------
        */

        return $this->confirmationMessage(
            $payload,
            $draft,
            $account->name,
            $target?->name
        );
    }

    /**
     * Confirm or cancel a pending transaction.
     */
    private function confirmation(
        User $user,
        ConversationSession $session,
        string $command
    ): string {
        /*
        |--------------------------------------------------------------------------
        | Cancel
        |--------------------------------------------------------------------------
        */

        if (in_array($command, self::CANCEL, true)) {
            $this->clear($session);

            return "↩️ *Transaksi Dibatalkan*\n\n"
                . "Oke, transaksi tadi sudah dibatalkan.\n"
                . "Tidak ada perubahan pada saldo dompet kamu.\n\n"
                . "Kirim transaksi baru kapan saja. 😊";
        }

        /*
        |--------------------------------------------------------------------------
        | Unknown Confirmation Command
        |--------------------------------------------------------------------------
        */

        if (! in_array($command, self::CONFIRM, true)) {
            return "⏳ *Menunggu Konfirmasi*\n\n"
                . "Transaksi kamu sudah siap, tetapi belum disimpan.\n\n"
                . "*1* — Simpan transaksi\n"
                . "*3* — Batalkan transaksi\n\n"
                . "_Konfirmasi berlaku selama 30 menit._";
        }

        /*
        |--------------------------------------------------------------------------
        | Revalidate Pending Draft
        |--------------------------------------------------------------------------
        */

        $confirmedDraft = $session->context ?? [];

        $payload = $this->payload(
            $confirmedDraft
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

            return "⚠️ *Transaksi Tidak Dapat Disimpan*\n\n"
                . "Detail transaksi tidak valid atau sudah berubah.\n\n"
                . "Silakan kirim ulang transaksi dengan informasi terbaru.\n"
                . "_Saldo kamu belum berubah._";
        }

        /*
        |--------------------------------------------------------------------------
        | Save Transaction
        |--------------------------------------------------------------------------
        */

        $transaction = $this->transactions
            ->createTransaction(
                $user,
                $validator->validated()
            );

        $this->clear($session);

        return $this->successMessage(
            $transaction,
            $confirmedDraft
        );
    }

    /**
     * Transaction confirmation preview.
     */
    private function confirmationMessage(
        array $payload,
        array $draft,
        string $accountName,
        ?string $targetName
    ): string {
        $type = $payload['type'];

        $title = match ($type) {
            'income' => '💰 *Konfirmasi Pemasukan*',
            'transfer' => '🔄 *Konfirmasi Transfer*',
            default => '💸 *Konfirmasi Pengeluaran*',
        };

        $typeLabel = match ($type) {
            'income' => 'Pemasukan',
            'transfer' => 'Transfer',
            default => 'Pengeluaran',
        };

        $amount = $this->rupiah(
            $payload['amount']
        );

        $date = $this->formatDate(
            $payload['date']
        );

        $category = $draft['category_name']
            ?? 'Tanpa kategori';

        $note = trim(
            (string) ($payload['note'] ?? '')
        );

        /*
        |--------------------------------------------------------------------------
        | Build WhatsApp Message
        |--------------------------------------------------------------------------
        */

        $message = $title . "\n\n";

        $message .= "Berikut detail transaksi kamu:\n\n";

        $message .= "💵 *{$amount}*\n";

        $message .= "━━━━━━━━━━━━━━━━\n";

        $message .= "🏷️ Jenis: {$typeLabel}\n";

        if ($type === 'transfer') {
            $message .= "💳 Dompet: {$accountName} → {$targetName}\n";
        } else {
            $message .= "💳 Dompet: {$accountName}\n";
            $message .= "📂 Kategori: {$category}\n";
        }

        $message .= "📅 Tanggal: {$date}\n";

        if ($note !== '') {
            $message .= "📝 Catatan: {$note}\n";
        }

        $message .= "━━━━━━━━━━━━━━━━\n\n";

        $message .= "*Sudah sesuai?*\n\n";

        $message .= "*1* — Simpan transaksi\n";
        $message .= "*3* — Batalkan transaksi\n\n";

        $message .= "_Konfirmasi berlaku selama 30 menit._";

        return $message;
    }

    /**
     * Successful transaction response.
     */
    private function successMessage(
        $transaction,
        array $draft = []
    ): string {
        $type = $transaction->type;

        $title = match ($type) {
            'income' => '💰 *Pemasukan Berhasil Dicatat!*',
            'transfer' => '🔄 *Transfer Berhasil Dicatat!*',
            default => '✅ *Pengeluaran Berhasil Dicatat!*',
        };

        $amount = $this->rupiah(
            $transaction->amount
        );

        $accountName = $transaction->account->name;

        $message = $title . "\n\n";

        $message .= "✅ Tersimpan! Transaksi berhasil dicatat di Ledger.\n\n";

        $message .= "💵 *{$amount}*\n";

        if ($type === 'transfer') {
            $targetName = $draft['related_account_name']
                ?? null;

            $message .= "💳 Dari: {$accountName}\n";

            if ($targetName) {
                $message .= "➡️ Ke: {$targetName}\n";
            }
        } else {
            $message .= "💳 Dompet: {$accountName}\n";
        }

        $message .= "\n━━━━━━━━━━━━━━━━\n\n";

        $message .= "Keuangan kamu sudah diperbarui. ✨\n\n";

        $message .= "_Ketik *dompet* untuk melihat saldo terbaru._";

        return $message;
    }

    /**
     * Help menu.
     *
     * Uses a simple text response to preserve
     * compatibility with the existing webhook.
     */
    private function helpMessage(): string
    {
        return "📒 *Panduan Ledger*\n\n"
            . "Halo! 👋\n"
            . "Aku siap membantu kamu mencatat dan memantau keuangan langsung dari WhatsApp.\n\n"

            . "✍️ *CATAT TRANSAKSI*\n"
            . "• bensin 50rb bca\n"
            . "• makan 35k gopay\n"
            . "• gaji 1.000.000 bca\n"
            . "• transfer 50rb bca ke gopay\n\n"

            . "📊 *CEK KEUANGAN*\n"
            . "• *dompet* — Lihat semua saldo\n"
            . "• *saldo bca* — Cek saldo BCA\n"
            . "• *ringkasan hari ini* — Rekap harian\n"
            . "• *ringkasan bulan ini* — Rekap bulanan\n"
            . "• *5 transaksi terakhir* — Riwayat terbaru\n\n"

            . "💡 *CARA KONFIRMASI*\n"
            . "Setelah mengirim transaksi, Ledger akan menampilkan ringkasan.\n\n"
            . "*1* — Simpan transaksi\n"
            . "*3* — Batalkan transaksi\n\n"

            . "Gunakan nama dompet yang sudah terdaftar di Ledger.\n\n"
            . "_Ketik *bantuan* kapan saja untuk membuka panduan ini._";
    }

    /**
     * Convert draft into validated transaction payload.
     */
    private function payload(array $draft): array
    {
        return [
            'type' => $draft['type'] ?? null,

            'account_id' => $draft['account_id']
                ?? null,

            'to_account_id' => (
                ($draft['type'] ?? null) === 'transfer'
            )
                ? ($draft['related_account_id'] ?? null)
                : null,

            'category_id' => $draft['category_id']
                ?? null,

            'amount' => $draft['amount']
                ?? null,

            'date' => $draft['date']
                ?? now()->toDateString(),

            'note' => $draft['note']
                ?? null,
        ];
    }

    /**
     * Reset conversation state.
     */
    private function clear(
        ConversationSession $session
    ): void {
        $session->update([
            'state' => 'idle',
            'context' => null,
            'expires_at' => null,
        ]);
    }

    /**
     * Merge missing information from Groq.
     */
    private function mergeAi(
        User $user,
        array $draft,
        string $text
    ): array {
        $ai = $this->groq->parse(
            $user,
            $text
        );

        if (! $ai) {
            return $draft;
        }

        /*
        |--------------------------------------------------------------------------
        | Amount
        |--------------------------------------------------------------------------
        */

        if (! $draft['amount']) {
            $draft['amount'] = $ai['amount']
                ?? null;
        }

        /*
        |--------------------------------------------------------------------------
        | Wallet
        |--------------------------------------------------------------------------
        */

        if (
            ! $draft['account_id']
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

        /*
        |--------------------------------------------------------------------------
        | Category
        |--------------------------------------------------------------------------
        */

        if (
            ! $draft['category_id']
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

        /*
        |--------------------------------------------------------------------------
        | Preserve Local Transaction Classification
        |--------------------------------------------------------------------------
        |
        | Groq must not change:
        | - Transaction type
        | - Transfer direction
        | - Explicit source wallet
        | - Explicit target wallet
        |
        */

        $draft['source'] = 'rule+groq';

        return $draft;
    }

    /**
     * Format Indonesian Rupiah.
     */
    private function rupiah(
        float|int|string $amount
    ): string {
        $value = (float) $amount;

        $decimals = fmod($value, 1.0) === 0.0
            ? 0
            : 2;

        return 'Rp' . number_format(
            $value,
            $decimals,
            ',',
            '.'
        );
    }

    /**
     * Format dates in Indonesian.
     */
    private function formatDate(
        ?string $date
    ): string {
        try {
            return Carbon::parse(
                $date ?? now()->toDateString()
            )
                ->locale('id')
                ->translatedFormat('d F Y');
        } catch (\Throwable) {
            return (string) $date;
        }
    }
}
