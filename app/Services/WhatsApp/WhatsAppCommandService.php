<?php

namespace App\Services\WhatsApp;

use App\Models\User;
use Illuminate\Support\Str;

class WhatsAppCommandService
{
    public function respond(User $user, string $input): ?string
    {
        $command = Str::lower(trim(preg_replace('/\s+/u', ' ', $input)));
        if ($command === 'dompet') {
            $accounts = $user->accounts()->where('is_archived', false)->orderBy('name')->get(['name', 'balance']);
            if ($accounts->isEmpty()) {
                return 'Belum ada dompet aktif. Buat dompet terlebih dahulu di aplikasi Ledger.';
            }
            $lines = $accounts->map(fn ($account) => '• '.$account->name.': '.$this->money($account->balance))->all();
            return "💼 Dompet Ledger\n".implode("\n", $lines)."\n\nTotal saldo: ".$this->money($accounts->sum('balance'));
        }
        if (preg_match('/^saldo(?:\s+(.+))?$/u', $command, $matches)) {
            $name = trim($matches[1] ?? '');
            if ($name === '') {
                return 'Sebutkan nama dompet. Contoh: saldo bca. Ketik dompet untuk melihat daftar dompet.';
            }
            $accounts = $user->accounts()->where('is_archived', false)->get(['name', 'balance']);
            $account = $accounts->first(fn ($item) => Str::lower(trim($item->name)) === $name);
            if (! $account) {
                return 'Dompet "'.$name.'" tidak ditemukan. Ketik dompet untuk melihat dompet yang tersedia.';
            }
            return '💰 Saldo '.$account->name.': '.$this->money($account->balance);
        }
        if (preg_match('/^ringkasan\s+(hari ini|bulan ini)$/u', $command, $matches)) {
            $today = now()->toDateString();
            $query = $user->transactions()->whereIn('type', ['income', 'expense']);
            if ($matches[1] === 'hari ini') {
                $query->whereDate('date', $today);
                $period = 'hari ini';
            } else {
                $query->whereDate('date', '>=', now()->startOfMonth()->toDateString())
                    ->whereDate('date', '<=', now()->endOfMonth()->toDateString());
                $period = 'bulan ini';
            }
            $totals = $query->selectRaw('type, SUM(amount) as total')->groupBy('type')->pluck('total', 'type');
            $income = (float) ($totals['income'] ?? 0);
            $expense = (float) ($totals['expense'] ?? 0);
            return "📊 Ringkasan {$period}\nPemasukan: ".$this->money($income)."\nPengeluaran: ".$this->money($expense)."\nSelisih: ".$this->money($income - $expense)."\n(Transfer antar-dompet tidak dihitung.)";
        }
        if ($command === '5 transaksi terakhir') {
            $transactions = $user->transactions()->with('account:id,name')->orderByDesc('date')->orderByDesc('id')->limit(5)->get();
            if ($transactions->isEmpty()) {
                return 'Belum ada transaksi yang tercatat.';
            }
            $lines = $transactions->map(function ($tx) {
                $type = match ($tx->type) { 'income' => 'Masuk', 'expense' => 'Keluar', 'transfer' => 'Transfer', default => ucfirst($tx->type) };
                return '• '.$tx->date->format('d/m/Y').' | '.$type.' | '.$this->money($tx->amount).' | '.($tx->account?->name ?? 'Dompet tidak tersedia');
            })->all();
            return "🧾 5 transaksi terakhir\n".implode("\n", $lines);
        }
        return null;
    }

    private function money(float|int|string|null $amount): string
    {
        return 'Rp'.number_format((float) $amount, 2, ',', '.');
    }
}
