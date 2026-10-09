<?php

namespace App\Services\WhatsApp;

use App\Models\User;
use Illuminate\Support\Str;

class WhatsAppCommandService
{
    public function respond(User $user, string $input): ?string
    {
        $command = Str::lower(trim(preg_replace('/\s+/u', ' ', $input)));

        if (in_array($command, [
            'bantuan',
            'help',
            'panduan',
            'halo ledger',
            'menu',
            'commands',
        ], true)) {
            return $this->help();
        }

        if ($command === 'dompet') {
            $accounts = $user->accounts()
                ->where('is_archived', false)
                ->orderBy('name')
                ->get(['name', 'balance']);

            if ($accounts->isEmpty()) {
                return "🏦 *DOMPET LEDGER*\n\n"
                    . "Belum ada dompet aktif.\n"
                    . "Tambahkan dompet melalui aplikasi Ledger.";
            }

            $lines = $accounts->map(
                fn($account) => "• *{$account->name}*\n"
                    . "  " . $this->money($account->balance)
            )->implode("\n\n");

            return "🏦 *DAFTAR DOMPET*\n\n"
                . $lines
                . "\n\n━━━━━━━━━━━━━━━━\n"
                . "*Total Saldo*\n"
                . $this->money($accounts->sum('balance'))
                . "\n\n_Ketik saldo [nama dompet] untuk detail._";
        }

        if (preg_match('/^saldo(?:\s+(.+))?$/u', $command, $matches)) {
            $name = trim($matches[1] ?? '');

            if ($name === '') {
                return "💰 *CEK SALDO*\n\n"
                    . "Masukkan nama dompet.\n\n"
                    . "Contoh: *saldo bca*\n"
                    . "Ketik *dompet* untuk melihat daftar.";
            }

            $account = $user->accounts()
                ->where('is_archived', false)
                ->get(['name', 'balance'])
                ->first(
                    fn($item) =>
                    Str::lower(trim($item->name)) === $name
                );

            if (! $account) {
                return "⚠️ *DOMPET TIDAK DITEMUKAN*\n\n"
                    . "Dompet *{$name}* tidak tersedia.\n"
                    . "Ketik *dompet* untuk melihat daftar dompet aktif.";
            }

            return "💰 *SALDO DOMPET*\n\n"
                . "Dompet: *{$account->name}*\n"
                . "Saldo tersedia:\n"
                . "*" . $this->money($account->balance) . "*";
        }

        if (preg_match(
            '/^ringkasan\s+(hari ini|bulan ini)$/u',
            $command,
            $matches
        )) {
            $period = $matches[1];

            $query = $user->transactions()
                ->whereIn('type', ['income', 'expense']);

            if ($period === 'hari ini') {
                $query->whereDate('date', now()->toDateString());
                $title = 'HARI INI';
            } else {
                $query->whereDate(
                    'date',
                    '>=',
                    now()->startOfMonth()->toDateString()
                )->whereDate(
                    'date',
                    '<=',
                    now()->endOfMonth()->toDateString()
                );

                $title = 'BULAN INI';
            }

            $totals = $query
                ->selectRaw('type, SUM(amount) as total')
                ->groupBy('type')
                ->pluck('total', 'type');

            $income = (float) ($totals['income'] ?? 0);
            $expense = (float) ($totals['expense'] ?? 0);

            return "📊 *RINGKASAN {$title}*\n\n"
                . "📈 *Pemasukan*\n"
                . $this->money($income)
                . "\n\n📉 *Pengeluaran*\n"
                . $this->money($expense)
                . "\n\n━━━━━━━━━━━━━━━━\n"
                . "*Selisih*\n"
                . $this->money($income - $expense)
                . "\n\n_Transfer antar-dompet tidak dihitung._";
        }

        if ($command === '5 transaksi terakhir') {
            $transactions = $user->transactions()
                ->with('account:id,name')
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->limit(5)
                ->get();

            if ($transactions->isEmpty()) {
                return "🧾 *RIWAYAT TRANSAKSI*\n\n"
                    . "Belum ada transaksi yang tercatat.";
            }

            $lines = $transactions->map(function ($tx) {
                $label = match ($tx->type) {
                    'income' => '📈 Pemasukan',
                    'expense' => '📉 Pengeluaran',
                    'transfer' => '🔄 Transfer',
                    default => ucfirst($tx->type),
                };

                return "*{$label}*\n"
                    . $this->money($tx->amount)
                    . "\n"
                    . $tx->date->format('d/m/Y')
                    . ' • '
                    . ($tx->account?->name ?? 'Dompet tidak tersedia');
            })->implode("\n\n");

            return "🧾 *5 TRANSAKSI TERAKHIR*\n\n"
                . $lines
                . "\n\n━━━━━━━━━━━━━━━━\n"
                . "_Lihat detail lengkap melalui aplikasi Ledger._";
        }

        return null;
    }

    public function help(): string
    {
        return <<<'TEXT'
        📒 *LEDGER — PANDUAN WHATSAPP*

        Catat transaksi dan pantau keuangan langsung dari WhatsApp.

        ━━━━━━━━━━━━━━━━

        💸 *1. PENGELUARAN*

        • bensin 50rb bca
        • makan 35rb gopay
        • bayar listrik 250rb bca

        💰 *2. PEMASUKAN*

        • uang sate 141rb masuk ke bca
        • gaji 5jt masuk bca
        • pemasukan 500rb bca
        • terima transfer 200rb dari teman ke bca

        🔄 *3. TRANSFER ANTAR-DOMPET*

        • transfer 100rb bca ke gopay
        • pindah saldo 200rb dari bca ke dana

        ━━━━━━━━━━━━━━━━

        🏦 *4. INFORMASI DOMPET*

        • dompet
        • saldo bca
        • saldo gopay

        📊 *5. LAPORAN*

        • ringkasan hari ini
        • ringkasan bulan ini
        • 5 transaksi terakhir

        ━━━━━━━━━━━━━━━━

        ✅ *6. KONFIRMASI*

        Setelah transaksi dikirim, periksa ringkasannya.

        *1* — Simpan transaksi
        *3* — Batalkan transaksi

        Konfirmasi berlaku selama 30 menit.

        ━━━━━━━━━━━━━━━━

        💡 *TIPS*

        • Gunakan nama dompet yang ada di Ledger.
        • Nominal: 50rb, 50k, 50000, atau 5jt.
        • Uang diterima dari orang lain = pemasukan.
        • Pindah uang antar-dompet sendiri = transfer.

        Ketik *bantuan* untuk membuka panduan ini.
        TEXT;
    }

    private function money(float|int|string|null $amount): string
    {
        return 'Rp' . number_format((float) $amount, 0, ',', '.');
    }
}
