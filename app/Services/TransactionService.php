<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TransactionService
{
    public function createTransaction(User $user, array $data): Transaction
    {
        return DB::transaction(function () use ($user, $data) {
            $account = Account::where('user_id', $user->id)->findOrFail($data['account_id']);
            $amount = (float) $data['amount'];

            // 1. Mutasi Saldo Sesuai Tipe Transaksi
            if ($data['type'] === 'expense') {
                $account->decrement('balance', $amount);
            } elseif ($data['type'] === 'income') {
                $account->increment('balance', $amount);
            } elseif ($data['type'] === 'transfer') {
                $relatedAccount = Account::where('user_id', $user->id)
                    ->findOrFail($data['related_account_id'] ?? $data['to_account_id']);

                $account->decrement('balance', $amount);
                $relatedAccount->increment('balance', $amount);
            }

            // 2. Simpan Rekam Transaksi
            return $user->transactions()->create([
                'account_id' => $account->id,
                'category_id' => $data['category_id'] ?? null,
                'related_account_id' => $data['related_account_id'] ?? $data['to_account_id'] ?? null,
                'type' => $data['type'],
                'amount' => $amount,
                'note' => $data['note'] ?? null,
                'date' => $data['date'],
            ]);
        });
    }

    public function getRecentTransactions(User $user, int $limit = 5)
    {
        return Transaction::with(['account', 'relatedAccount', 'category'])
            ->where('user_id', $user->id)
            ->orderBy('date', 'desc')
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get();
    }
}
