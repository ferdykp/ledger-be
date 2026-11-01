<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Models\GoalObligation;
use App\Models\GoalCashAllocation;
use App\Support\DecimalMath;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class TransactionService
{
    public function query(User $user, array $filters = []): Builder
    {
        $query = Transaction::with(['account', 'relatedAccount', 'category'])
            ->where('user_id', $user->id)
            ->whereNull('financial_purpose');

        if (! empty($filters['month'])) {
            [$year, $month] = array_map('intval', explode('-', $filters['month']));
            $query->whereYear('date', $year)->whereMonth('date', $month);
        }
        if (! empty($filters['from'])) {
            $query->whereDate('date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('date', '<=', $filters['to']);
        }
        if (! empty($filters['type']) && $filters['type'] !== 'all') {
            $query->where('type', $filters['type']);
        }
        if (! empty($filters['account_id']) && $filters['account_id'] !== 'all') {
            $accountId = $filters['account_id'];
            $query->where(fn ($q) => $q->where('account_id', $accountId)->orWhere('related_account_id', $accountId));
        }
        if (! empty($filters['category_id']) && $filters['category_id'] !== 'all') {
            $query->where('category_id', $filters['category_id']);
        }
        if (! empty($filters['search'])) {
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
            if (! empty($data['idempotency_key'])) {
                $existing = Transaction::where('user_id', $user->id)->where('idempotency_key', $data['idempotency_key'])->first();
                if ($existing) {
                    $same = $existing->type === $data['type']
                        && (int) $existing->account_id === (int) $data['account_id']
                        && (int) ($existing->related_account_id ?? 0) === (int) ($data['related_account_id'] ?? $data['to_account_id'] ?? 0)
                        && DecimalMath::compare((string) $existing->amount, (string) $data['amount']) === 0
                        && $existing->date->format('Y-m-d') === $data['date']
                        && (int) ($existing->goal_obligation_id ?? 0) === (int) ($data['goal_obligation_id'] ?? 0);
                    abort_unless($same, 409, 'Kunci permintaan sudah digunakan untuk transaksi berbeda.');
                    return $existing->load(['account', 'relatedAccount', 'category']);
                }
            }
            $this->lockAccounts($user, [$data['account_id'], $data['to_account_id'] ?? null]);
            $account = $this->lockAccount($user, (int) $data['account_id']);
            $related = $data['type'] === 'transfer'
                ? $this->lockAccount($user, (int) ($data['related_account_id'] ?? $data['to_account_id']))
                : null;

            $this->applyBalanceEffect($account, $related, $data['type'], $data['amount'], 1);

            $allocationAmount = '0.00';
            if (! empty($data['goal_obligation_id'])) {
                $obligation = GoalObligation::whereHas('item.goal', fn ($q) => $q->where('user_id', $user->id))
                    ->lockForUpdate()->findOrFail($data['goal_obligation_id']);
                $remaining = (string) $obligation->remaining_amount;
                if (DecimalMath::compare((string) $data['amount'], $remaining) > 0) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['amount' => 'Nominal pembayaran melebihi sisa kewajiban.']);
                }
                $allocationAmount = $this->consumeGoalAllocation($user, $obligation, $account, (string) $data['amount']);
            }

            return $user->transactions()->create([
                'account_id' => $account->id,
                'category_id' => $data['category_id'] ?? null,
                'related_account_id' => $related?->id,
                'type' => $data['type'],
                'amount' => $data['amount'],
                'note' => $data['note'] ?? null,
                'date' => $data['date'],
                'goal_obligation_id' => $data['goal_obligation_id'] ?? null,
                'goal_allocation_amount' => $allocationAmount,
                'idempotency_key' => $data['idempotency_key'] ?? null,
            ])->load(['account', 'relatedAccount', 'category']);
        }, 3);
    }

    public function updateTransaction(User $user, Transaction $transaction, array $data): Transaction
    {
        abort_unless($transaction->user_id === $user->id, 403);

        return DB::transaction(function () use ($user, $transaction, $data) {
            $transaction = $user->transactions()->lockForUpdate()->findOrFail($transaction->id);
            abort_if($transaction->financial_purpose, 409, 'Transaksi investasi hanya dapat diubah dari halaman aset.');
            $this->lockAccounts($user, [$transaction->account_id, $transaction->related_account_id, $data['account_id'], $data['to_account_id'] ?? null]);
            $oldAccount = $this->lockAccount($user, (int) $transaction->account_id);
            $oldRelated = $transaction->type === 'transfer' && $transaction->related_account_id
                ? $this->lockAccount($user, (int) $transaction->related_account_id) : null;
            $this->applyBalanceEffect($oldAccount, $oldRelated, $transaction->type, $transaction->amount, -1);
            $this->restoreGoalAllocation($user, $transaction);

            $newAccount = $this->lockAccount($user, (int) $data['account_id']);
            $newRelated = $data['type'] === 'transfer'
                ? $this->lockAccount($user, (int) ($data['related_account_id'] ?? $data['to_account_id'])) : null;
            $this->applyBalanceEffect($newAccount, $newRelated, $data['type'], $data['amount'], 1);

            $allocationAmount = '0.00';
            if (! empty($data['goal_obligation_id'])) {
                $obligation = GoalObligation::whereHas('item.goal', fn ($q) => $q->where('user_id', $user->id))
                    ->lockForUpdate()->findOrFail($data['goal_obligation_id']);
                $availableObligation = (string) $obligation->remaining_amount;
                if ((int) ($data['goal_obligation_id'] ?? 0) === (int) $transaction->goal_obligation_id) {
                    $availableObligation = DecimalMath::add($availableObligation, (string) $transaction->amount);
                }
                if (DecimalMath::compare((string) $data['amount'], $availableObligation) > 0) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['amount' => 'Nominal pembayaran melebihi sisa kewajiban.']);
                }
                $allocationAmount = $this->consumeGoalAllocation($user, $obligation, $newAccount, (string) $data['amount']);
            }

            $transaction->update([
                'account_id' => $newAccount->id,
                'category_id' => $data['category_id'] ?? null,
                'related_account_id' => $newRelated?->id,
                'type' => $data['type'], 'amount' => $data['amount'],
                'note' => $data['note'] ?? null, 'date' => $data['date'],
                'goal_obligation_id' => $data['goal_obligation_id'] ?? null,
                'goal_allocation_amount' => $allocationAmount,
            ]);

            return $transaction->fresh(['account', 'relatedAccount', 'category']);
        }, 3);
    }

    public function deleteTransaction(User $user, Transaction $transaction): void
    {
        abort_unless($transaction->user_id === $user->id, 403);
        DB::transaction(function () use ($user, $transaction) {
            $transaction = $user->transactions()->lockForUpdate()->findOrFail($transaction->id);
            abort_if($transaction->financial_purpose, 409, 'Transaksi investasi hanya dapat dibatalkan dari halaman aset.');
            $this->lockAccounts($user, [$transaction->account_id, $transaction->related_account_id]);
            $account = $this->lockAccount($user, (int) $transaction->account_id);
            $related = $transaction->type === 'transfer' && $transaction->related_account_id
                ? $this->lockAccount($user, (int) $transaction->related_account_id) : null;
            $this->applyBalanceEffect($account, $related, $transaction->type, $transaction->amount, -1);
            $this->restoreGoalAllocation($user, $transaction);
            $transaction->delete();
        }, 3);
    }

    public function report(User $user, string $month): array
    {
        $start = Carbon::createFromFormat('!Y-m', $month)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $prevStart = $start->copy()->subMonth()->startOfMonth();
        $prevEnd = $prevStart->copy()->endOfMonth();

        $summary = fn ($s, $e) => Transaction::where('user_id', $user->id)->whereNull('financial_purpose')->whereBetween('date', [$s->toDateString(), $e->toDateString()])
            ->selectRaw("COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE 0 END),0) income, COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END),0) expense")->first();
        $cur = $summary($start, $end);
        $prev = $summary($prevStart, $prevEnd);

        $categories = Transaction::leftJoin('categories', 'transactions.category_id', '=', 'categories.id')
            ->where('transactions.user_id', $user->id)->whereNull('transactions.financial_purpose')->where('transactions.type', 'expense')->whereBetween('transactions.date', [$start->toDateString(), $end->toDateString()])
            ->groupBy('transactions.category_id', 'categories.name')
            ->selectRaw("COALESCE(categories.name,'Lainnya') name, SUM(transactions.amount) amount")
            ->orderByDesc('amount')->get();

        $weekly = collect(range(1, 5))->map(function ($week) use ($user, $start, $end) {
            $s = $start->copy()->addDays(($week - 1) * 7);
            $e = $s->copy()->addDays(6)->min($end);
            if ($s->gt($end)) {
                return ['label' => "Week {$week}", 'income' => '0.00', 'expense' => '0.00'];
            }
            $r = Transaction::where('user_id', $user->id)->whereNull('financial_purpose')->whereBetween('date', [$s->toDateString(), $e->toDateString()])
                ->selectRaw("COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE 0 END),0) income, COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END),0) expense")->first();

            return ['label' => "Week {$week}", 'income' => DecimalMath::add((string) $r->income, '0.00'), 'expense' => DecimalMath::add((string) $r->expense, '0.00')];
        })->values();

        return ['month' => $month, 'income' => DecimalMath::add((string) $cur->income, '0.00'), 'expense' => DecimalMath::add((string) $cur->expense, '0.00'), 'net' => DecimalMath::subtract((string) $cur->income, (string) $cur->expense),
            'previous' => ['income' => DecimalMath::add((string) $prev->income, '0.00'), 'expense' => DecimalMath::add((string) $prev->expense, '0.00'), 'net' => DecimalMath::subtract((string) $prev->income, (string) $prev->expense)],
            'categories' => $categories->map(fn ($x) => ['name' => $x->name, 'amount' => DecimalMath::add((string) $x->amount, '0.00')])->values(), 'weekly' => $weekly];
    }

    public function cashFlow(User $user, int $months = 6): array
    {
        $months = max(1, min($months, 24));
        $now = now()->startOfMonth();

        $start = $now->copy()->subMonths($months - 1);
        $totals = Transaction::where('user_id', $user->id)
            ->whereNull('financial_purpose')
            ->whereBetween('date', [$start->toDateString(), $now->copy()->endOfMonth()->toDateString()])
            ->selectRaw("SUBSTR(date, 1, 7) AS month, SUM(CASE WHEN type='income' THEN amount ELSE 0 END) AS income, SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS expense")
            ->groupByRaw('SUBSTR(date, 1, 7)')->get()->keyBy('month');

        return collect(range($months - 1, 0))->map(function ($offset) use ($totals, $now) {
            $date = $now->copy()->subMonths($offset);
            $total = $totals->get($date->format('Y-m'));

            return ['month' => $date->format('Y-m'), 'label' => $date->locale('id')->translatedFormat('M'),
                'income' => DecimalMath::add((string) ($total?->income ?? '0.00'), '0.00'), 'expense' => DecimalMath::add((string) ($total?->expense ?? '0.00'), '0.00')];
        })->all();
    }

    private function lockAccounts(User $user, array $ids): void
    {
        // Acquire every affected account in the same order, including both transfer sides.
        Account::where('user_id', $user->id)->whereIn('id', array_filter($ids))
            ->orderBy('id')->lockForUpdate()->get();
    }

    private function lockAccount(User $user, int $id): Account
    {
        return Account::where('user_id', $user->id)->lockForUpdate()->findOrFail($id);
    }

    private function applyBalanceEffect(Account $account, ?Account $related, string $type, $amount, int $direction): void
    {
        if ($type === 'income') {
            $direction === 1 ? $account->increment('balance', $amount) : $account->decrement('balance', $amount);
        } elseif ($type === 'expense') {
            $direction === 1 ? $account->decrement('balance', $amount) : $account->increment('balance', $amount);
        } elseif ($type === 'transfer') {
            $direction === 1 ? $account->decrement('balance', $amount) : $account->increment('balance', $amount);
            if ($related) {
                $direction === 1 ? $related->increment('balance', $amount) : $related->decrement('balance', $amount);
            }
        }
    }

    private function consumeGoalAllocation(User $user, GoalObligation $obligation, Account $account, string $paymentAmount): string
    {
        $item = $obligation->item()->firstOrFail();
        $goalId = $item->goal_id;
        $rows = GoalCashAllocation::where('user_id', $user->id)->where('goal_id', $goalId)->where('account_id', $account->id)->lockForUpdate()->get();
        $allocated = '0.00';
        foreach ($rows as $row) {
            $delta = $row->action === 'allocate' ? (string) $row->amount : bcmul((string) $row->amount, '-1', 2);
            $allocated = DecimalMath::add($allocated, $delta);
        }
        $consume = DecimalMath::compare($allocated, '0.00') < 0
            ? '0.00'
            : (DecimalMath::compare($allocated, $paymentAmount) < 0 ? $allocated : $paymentAmount);
        if (DecimalMath::compare($consume, '0.00') > 0) {
            GoalCashAllocation::create(['user_id' => $user->id, 'goal_id' => $goalId, 'account_id' => $account->id, 'amount' => $consume, 'action' => 'payment', 'note' => 'Digunakan untuk pembayaran target']);
        }
        return $consume;
    }

    private function restoreGoalAllocation(User $user, Transaction $transaction): void
    {
        if (! $transaction->goal_obligation_id || DecimalMath::compare((string) $transaction->goal_allocation_amount, '0.00') <= 0) return;
        $obligation = GoalObligation::with('item')->find($transaction->goal_obligation_id);
        if (! $obligation) return;
        GoalCashAllocation::create([
            'user_id' => $user->id,
            'goal_id' => $obligation->item->goal_id,
            'account_id' => $transaction->account_id,
            'amount' => $transaction->goal_allocation_amount,
            'action' => 'allocate',
            'note' => 'Pemulihan alokasi akibat perubahan atau pembatalan pembayaran',
        ]);
    }
}
