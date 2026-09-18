<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class TransactionService
{
    public function query(User $user, array $filters = []): Builder
    {
        $query = Transaction::with(['account', 'relatedAccount', 'category'])
            ->where('user_id', $user->id);

        if (!empty($filters['month'])) {
            [$year, $month] = array_map('intval', explode('-', $filters['month']));
            $query->whereYear('date', $year)->whereMonth('date', $month);
        }
        if (!empty($filters['from'])) $query->whereDate('date', '>=', $filters['from']);
        if (!empty($filters['to'])) $query->whereDate('date', '<=', $filters['to']);
        if (!empty($filters['type']) && $filters['type'] !== 'all') $query->where('type', $filters['type']);
        if (!empty($filters['account_id']) && $filters['account_id'] !== 'all') {
            $accountId = $filters['account_id'];
            $query->where(fn ($q) => $q->where('account_id', $accountId)->orWhere('related_account_id', $accountId));
        }
        if (!empty($filters['category_id']) && $filters['category_id'] !== 'all') $query->where('category_id', $filters['category_id']);
        if (!empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('note', 'like', "%{$search}%")
                    ->orWhereHas('category', fn ($c) => $c->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('account', fn ($a) => $a->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('relatedAccount', fn ($a) => $a->where('name', 'like', "%{$search}%"));
            });
        }

        return $query->orderByDesc('date')->orderByDesc('id');
    }

    public function createTransaction(User $user, array $data): Transaction
    {
        return DB::transaction(function () use ($user, $data) {
            $account = $this->lockAccount($user, (int) $data['account_id']);
            $related = $data['type'] === 'transfer'
                ? $this->lockAccount($user, (int) ($data['related_account_id'] ?? $data['to_account_id']))
                : null;

            $this->applyBalanceEffect($account, $related, $data['type'], $data['amount'], 1);

            return $user->transactions()->create([
                'account_id' => $account->id,
                'category_id' => $data['category_id'] ?? null,
                'related_account_id' => $related?->id,
                'type' => $data['type'],
                'amount' => $data['amount'],
                'note' => $data['note'] ?? null,
                'date' => $data['date'],
            ])->load(['account', 'relatedAccount', 'category']);
        });
    }

    public function updateTransaction(User $user, Transaction $transaction, array $data): Transaction
    {
        abort_unless($transaction->user_id === $user->id, 403);

        return DB::transaction(function () use ($user, $transaction, $data) {
            $oldAccount = $this->lockAccount($user, (int) $transaction->account_id);
            $oldRelated = $transaction->type === 'transfer' && $transaction->related_account_id
                ? $this->lockAccount($user, (int) $transaction->related_account_id) : null;
            $this->applyBalanceEffect($oldAccount, $oldRelated, $transaction->type, $transaction->amount, -1);

            $newAccount = $this->lockAccount($user, (int) $data['account_id']);
            $newRelated = $data['type'] === 'transfer'
                ? $this->lockAccount($user, (int) ($data['related_account_id'] ?? $data['to_account_id'])) : null;
            $this->applyBalanceEffect($newAccount, $newRelated, $data['type'], $data['amount'], 1);

            $transaction->update([
                'account_id' => $newAccount->id,
                'category_id' => $data['category_id'] ?? null,
                'related_account_id' => $newRelated?->id,
                'type' => $data['type'], 'amount' => $data['amount'],
                'note' => $data['note'] ?? null, 'date' => $data['date'],
            ]);
            return $transaction->fresh(['account', 'relatedAccount', 'category']);
        });
    }

    public function deleteTransaction(User $user, Transaction $transaction): void
    {
        abort_unless($transaction->user_id === $user->id, 403);
        DB::transaction(function () use ($user, $transaction) {
            $account = $this->lockAccount($user, (int) $transaction->account_id);
            $related = $transaction->type === 'transfer' && $transaction->related_account_id
                ? $this->lockAccount($user, (int) $transaction->related_account_id) : null;
            $this->applyBalanceEffect($account, $related, $transaction->type, $transaction->amount, -1);
            $transaction->delete();
        });
    }

    public function report(User $user, string $month): array
    {
        $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $prevStart = $start->copy()->subMonth()->startOfMonth();
        $prevEnd = $prevStart->copy()->endOfMonth();

        $summary = fn ($s, $e) => Transaction::where('user_id', $user->id)->whereBetween('date', [$s, $e])
            ->selectRaw("COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE 0 END),0) income, COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END),0) expense")->first();
        $cur = $summary($start, $end); $prev = $summary($prevStart, $prevEnd);

        $categories = Transaction::leftJoin('categories', 'transactions.category_id', '=', 'categories.id')
            ->where('transactions.user_id', $user->id)->where('transactions.type', 'expense')->whereBetween('transactions.date', [$start, $end])
            ->groupBy('transactions.category_id', 'categories.name')
            ->selectRaw("COALESCE(categories.name,'Lainnya') name, SUM(transactions.amount) amount")
            ->orderByDesc('amount')->get();

        $weekly = collect(range(1, 5))->map(function ($week) use ($user, $start, $end) {
            $s = $start->copy()->addDays(($week - 1) * 7);
            $e = $s->copy()->addDays(6)->min($end);
            if ($s->gt($end)) return ['label' => "Week {$week}", 'income' => 0, 'expense' => 0];
            $r = Transaction::where('user_id', $user->id)->whereBetween('date', [$s, $e])
                ->selectRaw("COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE 0 END),0) income, COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END),0) expense")->first();
            return ['label' => "Week {$week}", 'income' => (float)$r->income, 'expense' => (float)$r->expense];
        })->values();

        return ['month'=>$month,'income'=>(float)$cur->income,'expense'=>(float)$cur->expense,'net'=>(float)$cur->income-(float)$cur->expense,
            'previous'=>['income'=>(float)$prev->income,'expense'=>(float)$prev->expense,'net'=>(float)$prev->income-(float)$prev->expense],
            'categories'=>$categories->map(fn($x)=>['name'=>$x->name,'amount'=>(float)$x->amount])->values(), 'weekly'=>$weekly];
    }

    public function cashFlow(User $user, int $months = 6): array
    {
        $months = max(1, min($months, 24)); $now = now()->startOfMonth();
        return collect(range($months - 1, 0))->map(function ($offset) use ($user, $now) {
            $d = $now->copy()->subMonths($offset);
            $r = Transaction::where('user_id',$user->id)->whereYear('date',$d->year)->whereMonth('date',$d->month)
                ->selectRaw("COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE 0 END),0) income, COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END),0) expense")->first();
            return ['month'=>$d->format('Y-m'),'label'=>$d->locale('id')->translatedFormat('M'),'income'=>(float)$r->income,'expense'=>(float)$r->expense];
        })->values()->all();
    }

    private function lockAccount(User $user, int $id): Account { return Account::where('user_id',$user->id)->lockForUpdate()->findOrFail($id); }
    private function applyBalanceEffect(Account $account, ?Account $related, string $type, $amount, int $direction): void
    {
        if ($type === 'income') $direction === 1 ? $account->increment('balance',$amount) : $account->decrement('balance',$amount);
        elseif ($type === 'expense') $direction === 1 ? $account->decrement('balance',$amount) : $account->increment('balance',$amount);
        elseif ($type === 'transfer') {
            $direction === 1 ? $account->decrement('balance',$amount) : $account->increment('balance',$amount);
            if ($related) $direction === 1 ? $related->increment('balance',$amount) : $related->decrement('balance',$amount);
        }
    }
}
