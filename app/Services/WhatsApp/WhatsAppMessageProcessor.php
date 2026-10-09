
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

    /**
     * Process incoming messages.
     *
     * Return a reply for durable delivery.
     * Never send network replies inside a financial transaction.
     */
    public function handle(string $phone, string $text): string
    {
        return DB::transaction(function () use ($phone, $text) {
            $connection = WhatsAppConnection::where('phone_number', $phone)
                ->where('status', 'connected')
                ->first();

            if (! $connection || preg_match('/^LINK\s+/i', trim($text))) {
                return $this->message(
                    "🔐 *Hubungkan WhatsApp ke Ledger*",
                    [
                        "Nomor ini belum terhubung dengan akun Ledger.",
                        "Buka aplikasi Ledger → *Pengaturan* → *WhatsApp*, lalu lakukan verifikasi nomor.",
                        "Setelah terhubung, kamu bisa mencatat transaksi langsung dari chat ini. ✨",
                    ]
                );
            }

            // Serialize session creation and confirmation per user.
            $user = User::whereKey($connection->user_id)
                ->lockForUpdate()
                ->firstOrFail();

            $connection = WhatsAppConnection::whereKey($connection->id)
                ->where('phone_number', $phone)
                ->where('status', 'connected')
                ->lockForUpdate()
                ->first();

            if (! $connection) {
                return $this->message(
                    "🔌 *Koneksi Terputus*",
                    [
                        "Nomor WhatsApp kamu sudah tidak terhubung ke Ledger.",
                        "Silakan verifikasi ulang melalui *Pengaturan → WhatsApp* di aplikasi Ledger.",
                    ]
                );
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

            if (in_array($command, [
                'bantuan',
                'help',
                'panduan',
                'halo ledger',
            ], true)) {
                return $this->helpMessage();
            }

            // Informational commands do not clear a pending draft.
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

            if (in_array($command, [
                ...self::CONFIRM,
                ...self::CANCEL,
            ], true)) {
                return $this->message(
                    "💬 *Belum Ada Transaksi*",
                    [
                        "Saat ini tidak ada transaksi yang menunggu konfirmasi.",
                        "Kamu bisa langsung mengirim transaksi baru.",
                        "_Contoh: bensin 50rb bca_",
                    ]
                );
            }

            return $this->draft(
                $user,
                $session,
                trim($text)
            );
        }, 3);
    }

    /**
     * Create a transaction draft.
     */
    private function draft(
        User $user,
        ConversationSession $session,
        string $text
    ): string {
        $accounts = $user->accounts()
            ->where('is_archived', false)
            ->get();

        $draft = $this->parser->parse($user, $text);

        // A transfer contains two wallets.
        // Do not interpret its entire tail as one wallet.
        if (
            $draft['type'] !== 'transfer'
            && ! $draft['account_id']
            && preg_match(
                '/\b\d[\d.,]*\s*(?:rb|ribu|k|jt|juta)?\s+(?:(?:masuk|dari|pakai|via|ke|di)\s+)?([\pL][\pL\pN ._-]{0,39})$/iu',
                $text,
                $match
            )
        ) {
            $walletName = trim($match[1]);
            $available = $accounts->pluck('name')->implode(', ');

            return $this->message(
                "👛 *Dompet Tidak Ditemukan*",
                [
                    "Dompet *{$walletName}* belum tersedia di akun Ledger kamu.",
                    "Tenang, transaksi ini *belum dicatat* dan saldo kamu tetap aman.",
                    "💳 *Dompet yang tersedia:*",
                    $available ?: '_Belum ada dompet aktif._',
                    "Tambahkan dompet melalui aplikasi Ledger, atau kirim ulang menggunakan nama dompet yang tersedia.",
                ]
            );
        }

        // AI fallback cannot change transaction type or direction.
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

        $validator = Validator::make(
            $payload,
            TransactionRules::forUser(
                $user,
                $payload['type']
            )
        );

        if ($validator->fails()) {
            $available = $accounts->pluck('name')->implode(', ');

            return $this->message(
                "⚠️ *Transaksi Belum Bisa Diproses*",
                [
                    "Ada detail transaksi yang belum lengkap atau belum valid.",
                    "Pastikan nominal, dompet, kategori, dan tanggal sudah benar.",
                    "✍️ *Contoh penulisan:*",
                    "• bensin 50rb bca",
                    "• makan 35k gopay",
                    "• transfer 50rb bca ke gopay",
                    "💳 *Dompet tersedia:*",
                    $available ?: '_Belum ada dompet aktif._',
                    "_Belum ada perubahan pada saldo kamu._",
                ]
            );
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
            return $this->message(
                "👛 *Dompet Tidak Tersedia*",
                [
                    "Salah satu dompet yang dipilih tidak tersedia atau sudah diarsipkan.",
                    "Silakan gunakan dompet aktif yang ada di aplikasi Ledger.",
                    "_Transaksi belum dicatat._",
                ]
            );
        }

        $draft['account_name'] = $account->name;
        $draft['related_account_name'] = $target?->name;

        $session->update([
            'state' => 'waiting_confirmation',
            'context' => $draft,
            'expires_at' => now()->addMinutes(30),
        ]);

        return $this->confirmationMessage(
            $payload,
            $draft,
            $account->name,
            $target?->name
        );
    }

    /**
     * Confirm or cancel pending transactions.
     */
    private function confirmation(
        User $user,
        ConversationSession $session,
        string $command
    ): string {
        if (in_array($command, self::CANCEL, true)) {
            $this->clear($session);

            return $this->message(
                "↩️ *Transaksi Dibatalkan*",
                [
                    "Oke, transaksi tadi sudah dibatalkan.",
                    "Tidak ada perubahan pada saldo dompet kamu.",
                    "Kalau mau mencoba lagi, cukup kirim detail transaksi baru. 😊",
                ]
            );
        }

        if (! in_array($command, self::CONFIRM, true)) {
            return $this->message(
                "⏳ *Menunggu Konfirmasi*",
                [
                    "Transaksi kamu sudah siap, tetapi belum disimpan.",
                    "Balas salah satu pilihan berikut:",
                    "*1* — Simpan transaksi",
                    "*3* — Batalkan transaksi",
                    "_Konfirmasi berlaku selama 30 menit sejak transaksi dibuat._",
                ]
            );
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

            return $this->message(
                "⚠️ *Transaksi Perlu Diperbarui*",
                [
                    "Beberapa detail transaksi sudah tidak valid atau mengalami perubahan.",
                    "Untuk keamanan, transaksi tidak disimpan dan saldo tidak berubah.",
                    "Silakan kirim ulang transaksi dengan informasi terbaru.",
                ]
            );
        }

        $transaction = $this->transactions->createTransaction(
            $user,
            $validator->validated()
        );

        $this->clear($session);

        $type = $transaction->type;
        $amount = $this->rupiah($transaction->amount);
        $accountName = $transaction->account->name;

        $title = match ($type) {
            'income' => '💰 *Pemasukan Berhasil Dicatat!*',
            'transfer' => '🔄 *Transfer Berhasil Dicatat!*',
            default => '✅ *Pengeluaran Berhasil Dicatat!*',
        };

        $lines = [
            "Transaksi kamu berhasil disimpan di Ledger.",
            "💵 *{$amount}*",
            "💳 Dompet: *{$accountName}*",
        ];

        if ($type === 'transfer') {
            $target = $transaction->toAccount?->name
                ?? $transaction->relatedAccount?->name
                ?? null;

            if ($target) {
                $lines[] = "➡️ Tujuan: *{$target}*";
            }
        }

        $lines[] = "Semua sudah tercatat. Keuangan jadi lebih mudah dipantau. ✨";
        $lines[] = "_Ketik *dompet* untuk melihat saldo terbaru._";

        return $this->message($title, $lines);
    }

    /**
     * Build confirmation message.
     */
    private function confirmationMessage(
        array $payload,
        array $draft,
        string $accountName,
        ?string $targetName
    ): string {
        $type = $payload['type'];

        $title = match ($type) {
            'income' => '💰 *Pemasukan Baru*',
            'transfer' => '🔄 *Transfer Antar Dompet*',
            default => '💸 *Pengeluaran Baru*',
        };

        $typeLabel = match ($type) {
            'income' => 'Pemasukan',
            'transfer' => 'Transfer',
            default => 'Pengeluaran',
        };

        $amount = $this->rupiah($payload['amount']);
        $category = $draft['category_name'] ?: 'Tanpa kategori';
        $date = $this->formatDate($payload['date']);
        $note = trim((string) ($payload['note'] ?? ''));

        $lines = [
            "Berikut detail transaksi yang akan dicatat:",
            "💵 *{$amount}*",
            "━━━━━━━━━━━━━━━━",
            "🏷️ Jenis: *{$typeLabel}*",
        ];

        if ($type === 'transfer' && $targetName) {
            $lines[] = "💳 Dari: *{$accountName}*";
            $lines[] = "➡️ Ke: *{$targetName}*";
        } else {
            $lines[] = "💳 Dompet: *{$accountName}*";
            $lines[] = "📂 Kategori: {$category}";
        }

        $lines[] = "📅 Tanggal: {$date}";

        if ($note !== '') {
            $lines[] = "📝 Catatan: {$note}";
        }

        $lines[] = "━━━━━━━━━━━━━━━━";
        $lines[] = "*Sudah sesuai?*";
        $lines[] = "Balas *1* untuk simpan";
        $lines[] = "Balas *3* untuk batal";
        $lines[] = "_Berlaku selama 30 menit._";

        return $this->message($title, $lines);
    }

    /**
     * Help and command list.
     */
    private function helpMessage(): string
    {
        return $this->message(
            "👋 *Halo! Selamat datang di Ledger*",
            [
                "Asisten keuangan pribadi kamu, langsung dari WhatsApp.",
                "Catat transaksi, cek saldo, dan pantau keuangan cukup lewat chat. ✨",
                "✍️ *CATAT TRANSAKSI*",
                "• bensin 50rb bca",
                "• makan 35k gopay",
                "• gaji 1.000.000 bca",
                "• transfer 50rb bca ke gopay",
                "📊 *CEK KEUANGAN*",
                "• *dompet* — Semua saldo dompet",
                "• *saldo bca* — Saldo dompet tertentu",
                "• *ringkasan hari ini* — Aktivitas hari ini",
                "• *ringkasan bulan ini* — Rekap bulanan",
                "• *5 transaksi terakhir* — Riwayat terbaru",
                "💡 *TIPS*",
                "Gunakan nama dompet yang sudah terdaftar di Ledger.",
                "Setiap transaksi akan meminta konfirmasi sebelum disimpan.",
                "Balas *1* untuk menyimpan atau *3* untuk membatalkan.",
                "_Ketik *bantuan* kapan saja untuk melihat panduan ini._",
            ]
        );
    }

    /**
     * Convert draft to validated transaction payload.
     */
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

    /**
     * Reset conversation session.
     */
    private function clear(ConversationSession $session): void
    {
        $session->update([
            'state' => 'idle',
            'context' => null,
            'expires_at' => null,
        ]);
    }

    /**
     * AI fallback for missing fields.
     */
    private function mergeAi(
        User $user,
        array $draft,
        string $text
    ): array {
        $ai = $this->groq->parse($user, $text);

        if (! $ai) {
            return $draft;
        }

        if (! $draft['amount']) {
            $draft['amount'] = $ai['amount'] ?? null;
        }

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

        // Do not let AI change an already classified
        // transaction type or transfer direction.
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

        $draft['source'] = 'rule+groq';

        return $draft;
    }

    /**
     * Format a WhatsApp message consistently.
     */
    private function message(
        string $title,
        array $lines = []
    ): string {
        $body = collect($lines)
            ->filter(fn($line) => $line !== null && $line !== '')
            ->implode("\n\n");

        return trim($title . "\n\n" . $body);
    }

    /**
     * Format Indonesian currency.
     */
    private function rupiah(float|int|string $amount): string
    {
        $value = (float) $amount;

        $decimals = fmod($value, 1.0) === 0.0 ? 0 : 2;

        return 'Rp' . number_format(
            $value,
            $decimals,
            ',',
            '.'
        );
    }

    /**
     * Format date for Indonesian users.
     */
    private function formatDate(?string $date): string
    {
        try {
            return \Carbon\Carbon::parse(
                $date ?? now()->toDateString()
            )->locale('id')->translatedFormat('d F Y');
        } catch (\Throwable) {
            return (string) $date;
        }
    }
}
