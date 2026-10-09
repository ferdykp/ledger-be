<?php

namespace App\Services\WhatsApp;

use App\Models\User;
use Illuminate\Support\Str;

class WhatsAppCommandService
{
    public function respond(User $user, string $input): ?string
    {
        $command = Str::lower(trim(
            preg_replace('/\s+/u', ' ', $input) ?? ''
        ));

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
            return $this->wallets($user);
        }

        if (preg_match('/^saldo(?:\s+(.+))?$/u', $command, $matches)) {
            return $this->balance(
                $user,
                trim($matches[1] ?? '')
            );
        }

        if (preg_match(
            '/^ringkasan\s+(hari ini|bulan ini)$/u',
            $command,
            $matches
        )) {
            return $this->summary($user, $matches[1]);
        }

        if ($command === '5 transaksi terakhir') {
            return $this->recentTransactions($user);
        }

        return null;
    }

    public function help(): string
    {
        return implode("\n", [
            "📒 *LEDGER — ASISTEN KEUANGAN*",
            "",
            "Halo! 👋",
            "Selamat datang di Ledger, asisten keuangan pribadimu.",
            "",
            "Catat transaksi, pantau saldo, dan kelola keuangan langsung dari WhatsApp. Cukup kirim perintah atau tulis transaksi dengan bahasa sehari-hari.",
            "",
            "━━━━━━━━━━━━━━━━",
            "✦ *CATAT TRANSAKSI*",
            "━━━━━━━━━━━━━━━━",
            "",
            "💸 *Pengeluaran*",
            "Catat uang yang kamu keluarkan.",
            "Contoh: `bensin 50rb bca`",
            "",
            "💰 *Pemasukan*",
            "Catat uang yang kamu terima.",
            "Contoh: `gaji 1jt bca`",
            "",
            "🔄 *Transfer Antar-Dompet*",
            "Pindahkan saldo dari satu dompet ke dompet lainnya.",
            "Contoh: `transfer 100rb bca ke gopay`",
            "",
            "━━━━━━━━━━━━━━━━",
            "✦ *INFORMASI KEUANGAN*",
            "━━━━━━━━━━━━━━━━",
            "",
            "👛 *dompet*",
            "Lihat daftar dompet aktif, saldo masing-masing, dan total saldo.",
            "",
            "💳 *saldo [nama dompet]*",
            "Periksa saldo dompet tertentu.",
            "Contoh: `saldo bca`",
            "",
            "📊 *ringkasan hari ini*",
            "Lihat total pemasukan, pengeluaran, dan selisih hari ini.",
            "",
            "📈 *ringkasan bulan ini*",
            "Lihat ringkasan keuangan selama bulan berjalan.",
            "",
            "🧾 *5 transaksi terakhir*",
            "Lihat lima transaksi terbaru yang tercatat.",
            "",
            "━━━━━━━━━━━━━━━━",
            "✦ *KONFIRMASI TRANSAKSI*",
            "━━━━━━━━━━━━━━━━",
            "",
            "Setiap transaksi akan ditampilkan untuk diperiksa sebelum disimpan.",
            "",
            "✅ `1` atau `konfirmasi`",
            "Setujui dan simpan transaksi.",
            "",
            "❌ `3` atau `batal`",
            "Batalkan transaksi yang sedang diproses.",
            "",
            "━━━━━━━━━━━━━━━━",
            "✦ *TIPS PENGGUNAAN*",
            "━━━━━━━━━━━━━━━━",
            "",
            "• Gunakan nama dompet sesuai yang terdaftar di Ledger.",
            "• Nominal bisa ditulis seperti `20rb`, `35k`, atau `1jt`.",
            "• Periksa detail transaksi sebelum melakukan konfirmasi.",
            "• Ketik `bantuan` kapan saja untuk membuka panduan ini.",
            "",
            "━━━━━━━━━━━━━━━━",
            "✨ *Ledger*",
            "_Your money, clearly managed._",
        ]);
    }

    private function wallets(User $user): string
    {
        $accounts = $user->accounts()
            ->where('is_archived', false)
            ->orderBy('name')
            ->get(['name', 'balance']);

        if ($accounts->isEmpty()) {
            return implode("\n", [
                "👛 *DOMPET LEDGER*",
                "",
                "Belum ada dompet aktif yang dapat ditampilkan.",
                "",
                "Silakan buat dompet terlebih dahulu melalui aplikasi Ledger.",
                "",
                "Ketik `bantuan` untuk melihat panduan.",
            ]);
        }

        $lines = $accounts->values()->map(
            fn($account, $index) => ($index + 1) . ". *" . $account->name . "*\n"
                . "   " . $this->money($account->balance)
        )->all();

        return implode("\n", [
            "👛 *DOMPET LEDGER*",
            "",
            "Berikut daftar dompet aktif beserta saldo terkinimu.",
            "",
            ...$lines,
            "",
            "━━━━━━━━━━━━━━━━",
            "💰 *TOTAL SALDO*",
            "*" . $this->money($accounts->sum('balance')) . "*",
            "━━━━━━━━━━━━━━━━",
            "",
            "💡 Ketik `saldo bca` untuk melihat saldo dompet tertentu.",
            "",
            "_Ledger • Financial Overview_",
        ]);
    }

    private function balance(User $user, string $name): string
    {
        if ($name === '') {
            return implode("\n", [
                "💳 *CEK SALDO DOMPET*",
                "",
                "Silakan sertakan nama dompet yang ingin diperiksa.",
                "",
                "Contoh:",
                "`saldo bca`",
                "",
                "Ketik `dompet` untuk melihat daftar dompet yang tersedia.",
            ]);
        }

        $accounts = $user->accounts()
            ->where('is_archived', false)
            ->get(['name', 'balance']);

        $account = $accounts->first(
            fn($item) =>
            Str::lower(trim($item->name)) === $name
        );

        if (! $account) {
            return implode("\n", [
                "🔎 *DOMPET TIDAK DITEMUKAN*",
                "",
                "Kami tidak menemukan dompet dengan nama *{$name}*.",
                "",
                "Pastikan nama dompet sesuai dengan yang terdaftar di Ledger.",
                "",
                "Ketik `dompet` untuk melihat daftar dompet aktif.",
            ]);
        }

        return implode("\n", [
            "💳 *INFORMASI SALDO*",
            "",
            "👛 *Dompet*",
            $account->name,
            "",
            "💰 *Saldo Saat Ini*",
            "*" . $this->money($account->balance) . "*",
            "",
            "━━━━━━━━━━━━━━━━",
            "💡 Ketik `dompet` untuk melihat seluruh saldo.",
            "",
            "_Ledger • Balance Information_",
        ]);
    }

    private function summary(User $user, string $period): string
    {
        $now = now();

        $query = $user->transactions()
            ->whereIn('type', ['income', 'expense']);

        if ($period === 'hari ini') {
            $query->whereDate('date', $now->toDateString());

            $title = 'RINGKASAN HARI INI';
            $dateLabel = $now->translatedFormat('d F Y');
        } else {
            $query->whereDate(
                'date',
                '>=',
                $now->copy()->startOfMonth()->toDateString()
            )->whereDate(
                'date',
                '<=',
                $now->copy()->endOfMonth()->toDateString()
            );

            $title = 'RINGKASAN BULAN INI';
            $dateLabel = $now->translatedFormat('F Y');
        }

        $totals = $query
            ->selectRaw('type, SUM(amount) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $income = (float) ($totals['income'] ?? 0);
        $expense = (float) ($totals['expense'] ?? 0);
        $difference = $income - $expense;

        $status = $difference > 0
            ? '🟢 Surplus'
            : ($difference < 0 ? '🔴 Defisit' : '⚪ Seimbang');

        return implode("\n", [
            "📊 *{$title}*",
            "📅 {$dateLabel}",
            "",
            "Berikut gambaran aktivitas keuanganmu selama periode ini.",
            "",
            "📥 *TOTAL PEMASUKAN*",
            $this->money($income),
            "",
            "📤 *TOTAL PENGELUARAN*",
            $this->money($expense),
            "",
            "━━━━━━━━━━━━━━━━",
            "💰 *SELISIH PEMASUKAN & PENGELUARAN*",
            "*" . $this->money($difference) . "*",
            "",
            "*Status:* {$status}",
            "━━━━━━━━━━━━━━━━",
            "",
            "ℹ️ Transfer antar-dompet tidak termasuk dalam perhitungan ini.",
            "",
            "Ketik `5 transaksi terakhir` untuk melihat aktivitas terbaru.",
            "",
            "_Ledger • Financial Summary_",
        ]);
    }

    private function recentTransactions(User $user): string
    {
        $transactions = $user->transactions()
            ->with('account:id,name')
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        if ($transactions->isEmpty()) {
            return implode("\n", [
                "🧾 *RIWAYAT TRANSAKSI*",
                "",
                "Belum ada transaksi yang tercatat.",
                "",
                "Mulai catat transaksi pertamamu melalui WhatsApp.",
                "",
                "Contoh: `bensin 50rb bca`",
            ]);
        }

        $lines = $transactions->values()->map(function ($tx, $index) {
            [$icon, $type] = match ($tx->type) {
                'income' => ['📥', 'Pemasukan'],
                'expense' => ['📤', 'Pengeluaran'],
                'transfer' => ['🔄', 'Transfer'],
                default => ['🧾', ucfirst((string) $tx->type)],
            };

            return implode("\n", [
                ($index + 1) . ". {$icon} *{$type}*",
                "   " . $this->money($tx->amount),
                "   📅 " . $tx->date->format('d/m/Y'),
                "   👛 " . ($tx->account?->name ?? 'Dompet tidak tersedia'),
            ]);
        })->all();

        return implode("\n", [
            "🧾 *5 TRANSAKSI TERAKHIR*",
            "",
            "Berikut aktivitas keuangan terbarumu.",
            "",
            implode("\n\n", $lines),
            "",
            "━━━━━━━━━━━━━━━━",
            "💡 Ketik `ringkasan bulan ini` untuk melihat gambaran keuangan bulanan.",
            "",
            "_Ledger • Transaction History_",
        ]);
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





// <?php

// namespace App\Services\WhatsApp;

// use App\Models\User;
// use Illuminate\Support\Str;

// class WhatsAppCommandService
// {
//     public function respond(User $user, string $input): ?string
//     {
//         $command = Str::lower(trim(
//             preg_replace('/\s+/u', ' ', $input) ?? ''
//         ));

//         if (in_array($command, [
//             'bantuan', 'help', 'panduan', 'halo ledger',
//             'menu', 'commands',
//         ], true)) {
//             return $this->help();
//         }

//         if ($command === 'dompet') {
//             return $this->wallets($user);
//         }

//         if (preg_match('/^saldo(?:\s+(.+))?$/u', $command, $matches)) {
//             return $this->balance(
//                 $user,
//                 trim($matches[1] ?? '')
//             );
//         }

//         if (preg_match(
//             '/^ringkasan\s+(hari ini|bulan ini)$/u',
//             $command,
//             $matches
//         )) {
//             return $this->summary($user, $matches[1]);
//         }

//         if ($command === '5 transaksi terakhir') {
//             return $this->recentTransactions($user);
//         }

//         return null;
//     }

//     public function help(): string
//     {
//         return implode("\n", [
//             "📒 *LEDGER — ASISTEN KEUANGAN*",
//             "",
//             "Halo! 👋",
//             "Selamat datang di Ledger, asisten keuangan pribadimu.",
//             "",
//             "Catat transaksi, pantau saldo, dan kelola keuangan langsung dari WhatsApp. Cukup kirim perintah atau tulis transaksi dengan bahasa sehari-hari.",
//             "",
//             "━━━━━━━━━━━━━━━━",
//             "✦ *CATAT TRANSAKSI*",
//             "━━━━━━━━━━━━━━━━",
//             "",
//             "💸 *Pengeluaran*",
//             "Catat uang yang kamu keluarkan.",
//             "Contoh: `bensin 50rb bca`",
//             "",
//             "💰 *Pemasukan*",
//             "Catat uang yang kamu terima.",
//             "Contoh: `gaji 1jt bca`",
//             "",
//             "🔄 *Transfer Antar-Dompet*",
//             "Pindahkan saldo dari satu dompet ke dompet lainnya.",
//             "Contoh: `transfer 100rb bca ke gopay`",
//             "",
//             "━━━━━━━━━━━━━━━━",
//             "✦ *INFORMASI KEUANGAN*",
//             "━━━━━━━━━━━━━━━━",
//             "",
//             "👛 *dompet*",
//             "Lihat daftar dompet aktif, saldo masing-masing, dan total saldo.",
//             "",
//             "💳 *saldo [nama dompet]*",
//             "Periksa saldo dompet tertentu.",
//             "Contoh: `saldo bca`",
//             "",
//             "📊 *ringkasan hari ini*",
//             "Lihat total pemasukan, pengeluaran, dan selisih hari ini.",
//             "",
//             "📈 *ringkasan bulan ini*",
//             "Lihat ringkasan keuangan selama bulan berjalan.",
//             "",
//             "🧾 *5 transaksi terakhir*",
//             "Lihat lima transaksi terbaru yang tercatat.",
//             "",
//             "━━━━━━━━━━━━━━━━",
//             "✦ *KONFIRMASI TRANSAKSI*",
//             "━━━━━━━━━━━━━━━━",
//             "",
//             "Setiap transaksi akan ditampilkan untuk diperiksa sebelum disimpan.",
//             "",
//             "✅ `1` atau `konfirmasi`",
//             "Setujui dan simpan transaksi.",
//             "",
//             "❌ `3` atau `batal`",
//             "Batalkan transaksi yang sedang diproses.",
//             "",
//             "━━━━━━━━━━━━━━━━",
//             "✦ *TIPS PENGGUNAAN*",
//             "━━━━━━━━━━━━━━━━",
//             "",
//             "• Gunakan nama dompet sesuai yang terdaftar di Ledger.",
//             "• Nominal bisa ditulis seperti `20rb`, `35k`, atau `1jt`.",
//             "• Periksa detail transaksi sebelum melakukan konfirmasi.",
//             "• Ketik `bantuan` kapan saja untuk membuka panduan ini.",
//             "",
//             "━━━━━━━━━━━━━━━━",
//             "✨ *Ledger*",
//             "_Your money, clearly managed._",
//         ]);
//     }

//     private function wallets(User $user): string
//     {
//         $accounts = $user->accounts()
//             ->where('is_archived', false)
//             ->orderBy('name')
//             ->get(['name', 'balance']);

//         if ($accounts->isEmpty()) {
//             return implode("\n", [
//                 "👛 *DOMPET LEDGER*",
//                 "",
//                 "Belum ada dompet aktif yang dapat ditampilkan.",
//                 "",
//                 "Silakan buat dompet terlebih dahulu melalui aplikasi Ledger.",
//                 "",
//                 "Ketik `bantuan` untuk melihat panduan.",
//             ]);
//         }

//         $lines = $accounts->values()->map(
//             fn ($account, $index) =>
//                 ($index + 1) . ". *" . $account->name . "*\n"
//                 . "   " . $this->money($account->balance)
//         )->all();

//         return implode("\n", [
//             "👛 *DOMPET LEDGER*",
//             "",
//             "Berikut daftar dompet aktif beserta saldo terkinimu.",
//             "",
//             ...$lines,
//             "",
//             "━━━━━━━━━━━━━━━━",
//             "💰 *TOTAL SALDO*",
//             "*" . $this->money($accounts->sum('balance')) . "*",
//             "━━━━━━━━━━━━━━━━",
//             "",
//             "💡 Ketik `saldo bca` untuk melihat saldo dompet tertentu.",
//             "",
//             "_Ledger • Financial Overview_",
//         ]);
//     }

//     private function balance(User $user, string $name): string
//     {
//         if ($name === '') {
//             return implode("\n", [
//                 "💳 *CEK SALDO DOMPET*",
//                 "",
//                 "Silakan sertakan nama dompet yang ingin diperiksa.",
//                 "",
//                 "Contoh:",
//                 "`saldo bca`",
//                 "",
//                 "Ketik `dompet` untuk melihat daftar dompet yang tersedia.",
//             ]);
//         }

//         $accounts = $user->accounts()
//             ->where('is_archived', false)
//             ->get(['name', 'balance']);

//         $account = $accounts->first(
//             fn ($item) =>
//                 Str::lower(trim($item->name)) === $name
//         );

//         if (! $account) {
//             return implode("\n", [
//                 "🔎 *DOMPET TIDAK DITEMUKAN*",
//                 "",
//                 "Kami tidak menemukan dompet dengan nama *{$name}*.",
//                 "",
//                 "Pastikan nama dompet sesuai dengan yang terdaftar di Ledger.",
//                 "",
//                 "Ketik `dompet` untuk melihat daftar dompet aktif.",
//             ]);
//         }

//         return implode("\n", [
//             "💳 *INFORMASI SALDO*",
//             "",
//             "👛 *Dompet*",
//             $account->name,
//             "",
//             "💰 *Saldo Saat Ini*",
//             "*" . $this->money($account->balance) . "*",
//             "",
//             "━━━━━━━━━━━━━━━━",
//             "💡 Ketik `dompet` untuk melihat seluruh saldo.",
//             "",
//             "_Ledger • Balance Information_",
//         ]);
//     }

//     private function summary(User $user, string $period): string
//     {
//         $now = now();

//         $query = $user->transactions()
//             ->whereIn('type', ['income', 'expense']);

//         if ($period === 'hari ini') {
//             $query->whereDate('date', $now->toDateString());

//             $title = 'RINGKASAN HARI INI';
//             $dateLabel = $now->translatedFormat('d F Y');
//         } else {
//             $query->whereDate(
//                 'date',
//                 '>=',
//                 $now->copy()->startOfMonth()->toDateString()
//             )->whereDate(
//                 'date',
//                 '<=',
//                 $now->copy()->endOfMonth()->toDateString()
//             );

//             $title = 'RINGKASAN BULAN INI';
//             $dateLabel = $now->translatedFormat('F Y');
//         }

//         $totals = $query
//             ->selectRaw('type, SUM(amount) as total')
//             ->groupBy('type')
//             ->pluck('total', 'type');

//         $income = (float) ($totals['income'] ?? 0);
//         $expense = (float) ($totals['expense'] ?? 0);
//         $difference = $income - $expense;

//         $status = $difference > 0
//             ? '🟢 Surplus'
//             : ($difference < 0 ? '🔴 Defisit' : '⚪ Seimbang');

//         return implode("\n", [
//             "📊 *{$title}*",
//             "📅 {$dateLabel}",
//             "",
//             "Berikut gambaran aktivitas keuanganmu selama periode ini.",
//             "",
//             "📥 *TOTAL PEMASUKAN*",
//             $this->money($income),
//             "",
//             "📤 *TOTAL PENGELUARAN*",
//             $this->money($expense),
//             "",
//             "━━━━━━━━━━━━━━━━",
//             "💰 *SELISIH PEMASUKAN & PENGELUARAN*",
//             "*" . $this->money($difference) . "*",
//             "",
//             "*Status:* {$status}",
//             "━━━━━━━━━━━━━━━━",
//             "",
//             "ℹ️ Transfer antar-dompet tidak termasuk dalam perhitungan ini.",
//             "",
//             "Ketik `5 transaksi terakhir` untuk melihat aktivitas terbaru.",
//             "",
//             "_Ledger • Financial Summary_",
//         ]);
//     }

//     private function recentTransactions(User $user): string
//     {
//         $transactions = $user->transactions()
//             ->with('account:id,name')
//             ->orderByDesc('date')
//             ->orderByDesc('id')
//             ->limit(5)
//             ->get();

//         if ($transactions->isEmpty()) {
//             return implode("\n", [
//                 "🧾 *RIWAYAT TRANSAKSI*",
//                 "",
//                 "Belum ada transaksi yang tercatat.",
//                 "",
//                 "Mulai catat transaksi pertamamu melalui WhatsApp.",
//                 "",
//                 "Contoh: `bensin 50rb bca`",
//             ]);
//         }

//         $lines = $transactions->values()->map(function ($tx, $index) {
//             [$icon, $type] = match ($tx->type) {
//                 'income' => ['📥', 'Pemasukan'],
//                 'expense' => ['📤', 'Pengeluaran'],
//                 'transfer' => ['🔄', 'Transfer'],
//                 default => ['🧾', ucfirst((string) $tx->type)],
//             };

//             return implode("\n", [
//                 ($index + 1) . ". {$icon} *{$type}*",
//                 "   " . $this->money($tx->amount),
//                 "   📅 " . $tx->date->format('d/m/Y'),
//                 "   👛 " . ($tx->account?->name ?? 'Dompet tidak tersedia'),
//             ]);
//         })->all();

//         return implode("\n", [
//             "🧾 *5 TRANSAKSI TERAKHIR*",
//             "",
//             "Berikut aktivitas keuangan terbarumu.",
//             "",
//             implode("\n\n", $lines),
//             "",
//             "━━━━━━━━━━━━━━━━",
//             "💡 Ketik `ringkasan bulan ini` untuk melihat gambaran keuangan bulanan.",
//             "",
//             "_Ledger • Transaction History_",
//         ]);
//     }

//     private function money(float|int|string|null $amount): string
//     {
//         return 'Rp' . number_format(
//             (float) $amount,
//             0,
//             ',',
//             '.'
//         );
//     }
// }
