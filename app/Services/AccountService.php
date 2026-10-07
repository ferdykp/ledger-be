<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountService
{
    public function getUserAccounts(User $user, bool $includeArchived = false): Collection
    {
        return Account::where('user_id', $user->id)
            ->when(! $includeArchived, fn ($query) => $query->where('is_archived', false))
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function createAccount(User $user, array $data): Account
    {
        return $user->accounts()->create([
            'name' => $data['name'],
            'type' => $data['type'],
            'balance' => $data['balance'] ?? 0,
            'color' => $data['color'] ?? '#6C4CF1',
            'icon' => $data['icon'] ?? null,
        ]);
    }

    public function updateAccount(Account $account, array $data): Account
    {
        $account->update($data);

        return $account->fresh();
    }

    public function deleteAccount(Account $account): bool
    {
        return DB::transaction(function () use ($account) {
            $account = Account::lockForUpdate()->findOrFail($account->id);
            if (Transaction::where('account_id', $account->id)->orWhere('related_account_id', $account->id)->exists()) {
                throw ValidationException::withMessages([
                    'account' => 'Akun memiliki riwayat transaksi dan tidak dapat dihapus. Arsipkan akun untuk menyembunyikannya.',
                ]);
            }

            return $account->delete();
        });
    }
}
