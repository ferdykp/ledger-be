<?php

namespace App\Services\WhatsApp;

use App\Models\User;
use Illuminate\Support\Str;

class WhatsAppCommandService
{
    public function respond(User $user, string $input): ?string
    {
        $command = Str::lower(trim(preg_replace('/\s+/u', ' ', $input)));
        if (in_array($command, ['bantuan', 'help', 'panduan', 'halo ledger', 'menu', 'commands'], true)) {
            return $this->help();
        }
        $accounts = fn () => $user->accounts()->where('is_archived', false)->orderBy('name');
        if ($command === 'dompet') {
            $query = $accounts();
            $count = (clone $query)->count();
            if (! $count) {
                return WhatsAppMessageFormat::message('Dompet Anda', 'Belum ada dompet aktif.', 'Tambahkan dompet melalui menu *Akun & Dompet* di aplikasi Ledger.');
            }
            $total = (clone $query)->sum('balance');
            $lines = $query->limit(20)->get()->map(fn ($account) => '• '.WhatsAppMessageFormat::text($account->name)."\n  *".WhatsAppMessageFormat::money($account->balance).'*')->implode("\n\n");

            return WhatsAppMessageFormat::message('Dompet Anda', $lines,
                '*Total saldo aktif: '.WhatsAppMessageFormat::money($total).'*',
                $count > 20 ? 'Menampilkan 20 dari '.$count.' dompet. Lihat seluruh daftar di aplikasi Ledger.' : '',
                'Ketik *saldo [nama dompet]* untuk melihat saldo satu dompet.');
        }
        if (preg_match('/^saldo(?:\s+(.+))?$/u', $command, $matches)) {
            $name = trim($matches[1] ?? '');
            if ($name === '') {
                return WhatsAppMessageFormat::message('Cek saldo', 'Sertakan nama dompet yang ingin dilihat.', "Contoh: *saldo bca*\nKetik *dompet* untuk melihat nama dompet Anda.");
            }
            $account = $accounts()->get(['name', 'balance'])->first(fn ($item) => Str::lower(trim(preg_replace('/\s+/u', ' ', $item->name))) === $name);
            if (! $account) {
                return WhatsAppMessageFormat::message('Dompet tidak ditemukan', 'Nama “'.WhatsAppMessageFormat::text($name).'” tidak cocok dengan dompet aktif Anda.', 'Ketik *dompet*, lalu gunakan nama yang tertera.');
            }

            return WhatsAppMessageFormat::message('Saldo '.WhatsAppMessageFormat::text($account->name), '*'.WhatsAppMessageFormat::money($account->balance).'*', 'Saldo berdasarkan transaksi yang tercatat di Ledger.');
        }
        if (preg_match('/^ringkasan\s+(hari ini|bulan ini)$/u', $command, $matches)) {
            $today = WhatsAppMessageFormat::today();
            $daily = $matches[1] === 'hari ini';
            $start = $daily ? $today : $today->startOfMonth();
            $end = $daily ? $today : $today->endOfMonth();
            $totals = $user->transactions()->whereIn('type', ['income', 'expense'])
                ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                ->selectRaw('type, SUM(amount) as total, COUNT(*) as count')->groupBy('type')->get()->keyBy('type');
            $income = (float) ($totals->get('income')?->total ?? 0);
            $expense = (float) ($totals->get('expense')?->total ?? 0);
            $count = $totals->sum('count');

            return WhatsAppMessageFormat::message('Ringkasan '.$matches[1],
                $daily ? WhatsAppMessageFormat::date($today->toDateString()) : $today->locale('id')->translatedFormat('F Y'),
                'Pemasukan: *'.WhatsAppMessageFormat::money($income)."*\nPengeluaran: *".WhatsAppMessageFormat::money($expense)."*\nSelisih: *".WhatsAppMessageFormat::money($income - $expense).'*',
                $count ? $count.' transaksi tercatat.' : 'Belum ada transaksi pada periode ini.',
                '_Transfer antar-dompet tidak dihitung. Selisih periode bukan saldo dompet._');
        }
        if ($command === '5 transaksi terakhir') {
            $transactions = $user->transactions()->with(['account:id,name', 'relatedAccount:id,name'])->orderByDesc('date')->orderByDesc('id')->limit(5)->get();
            if ($transactions->isEmpty()) {
                return WhatsAppMessageFormat::message('Transaksi terakhir', 'Belum ada transaksi yang tercatat.', 'Ketik *bantuan* untuk mulai mencatat.');
            }
            $lines = $transactions->map(function ($tx, $index) {
                $type = match ($tx->type) {
                    'income' => 'Pemasukan', 'transfer' => 'Transfer', default => 'Pengeluaran'
                };
                $wallet = WhatsAppMessageFormat::text($tx->account?->name ?? 'Dompet tidak tersedia');
                if ($tx->type === 'transfer') {
                    $wallet .= ' → '.WhatsAppMessageFormat::text($tx->relatedAccount?->name ?? 'Dompet tidak tersedia');
                }

                return ($index + 1).'. *'.$type.' · '.WhatsAppMessageFormat::money($tx->amount)."*\n".WhatsAppMessageFormat::date($tx->date->toDateString()).' · '.$wallet
                    .($tx->note ? "\n".WhatsAppMessageFormat::text($tx->note, 100) : '');
            })->implode("\n\n");

            return WhatsAppMessageFormat::message('Transaksi terakhir', $lines, 'Lihat atau ubah detail melalui menu *Transaksi* di aplikasi Ledger.');
        }

        return null;
    }

    public function help(): string
    {
        return WhatsAppMessageFormat::message('Panduan WhatsApp', 'Catat transaksi, cek saldo, dan lihat ringkasan keuangan lewat chat.',
            "*Catat transaksi*\n• Pengeluaran: makan 35rb gopay\n• Pemasukan: gaji 5jt masuk bca\n• Transfer: transfer 100rb bca ke gopay",
            "*Pantau keuangan*\n• dompet\n• saldo bca\n• ringkasan hari ini\n• ringkasan bulan ini\n• 5 transaksi terakhir",
            "*Periksa sebelum menyimpan*\nSetiap transaksi ditampilkan sebagai draf. Balas *1* untuk menyimpan atau *3* untuk membatalkan. Draf berlaku 30 menit.",
            'Gunakan nama dompet Anda di Ledger. Nominal dapat ditulis *50rb*, *50k*, *50000*, atau *1,5jt*.');
    }
}
