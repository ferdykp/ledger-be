<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Goal;
use App\Models\GoalCashAllocation;
use App\Models\User;
use App\Support\DecimalMath;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GoalCashAllocationService
{
    public function record(User $user, Goal $goal, array $data): GoalCashAllocation
    {
        abort_unless($goal->user_id === $user->id, 404);

        return DB::transaction(function () use ($user, $goal, $data) {
            if (! empty($data['idempotency_key'])) {
                $existing = GoalCashAllocation::where('user_id', $user->id)->where('idempotency_key', $data['idempotency_key'])->first();
                if ($existing) {
                    $same = (int) $existing->goal_id === (int) $goal->id
                        && (int) $existing->account_id === (int) $data['account_id']
                        && $existing->action === $data['action']
                        && DecimalMath::compare((string) $existing->amount, (string) $data['amount']) === 0;
                    abort_unless($same, 409, 'Kunci permintaan sudah digunakan untuk alokasi berbeda.');
                    return $existing;
                }
            }

            $account = Account::where('user_id', $user->id)->lockForUpdate()->findOrFail($data['account_id']);
            $goal = Goal::where('user_id', $user->id)->lockForUpdate()->findOrFail($goal->id);
            $totalAccountAllocated = $this->sumAllocations(GoalCashAllocation::where('user_id', $user->id)->where('account_id', $account->id)->lockForUpdate()->get());
            $goalAllocated = $this->sumAllocations(GoalCashAllocation::where('user_id', $user->id)->where('goal_id', $goal->id)->where('account_id', $account->id)->get());
            $amount = (string) $data['amount'];

            if ($data['action'] === 'allocate') {
                $available = DecimalMath::subtract((string) $account->balance, $totalAccountAllocated);
                if (DecimalMath::compare($available, '0.00') < 0 || DecimalMath::compare($amount, $available) > 0) {
                    throw ValidationException::withMessages(['amount' => 'Saldo rekening yang belum dialokasikan tidak mencukupi. Periksa alokasi dan saldo rekening terlebih dahulu.']);
                }
            } elseif (DecimalMath::compare($goalAllocated, $amount) < 0) {
                throw ValidationException::withMessages(['amount' => 'Nominal pelepasan melebihi dana yang dialokasikan ke target ini dari rekening tersebut.']);
            }

            return GoalCashAllocation::create([
                'user_id' => $user->id,
                'goal_id' => $goal->id,
                'account_id' => $account->id,
                'amount' => $amount,
                'action' => $data['action'],
                'note' => $data['note'] ?? null,
                'idempotency_key' => $data['idempotency_key'] ?? null,
            ]);
        }, 3);
    }

    public function summary(User $user): array
    {
        return Account::where('user_id', $user->id)->where('is_archived', false)->get()->map(function (Account $account) use ($user) {
            $entries = GoalCashAllocation::with('goal')->where('user_id', $user->id)->where('account_id', $account->id)->get();
            $allocated = $this->sumAllocations($entries);
            $available = DecimalMath::subtract((string) $account->balance, $allocated);
            return [
                'account_id' => $account->id,
                'account_name' => $account->name,
                'balance' => (string) $account->balance,
                'allocated' => $allocated,
                'unallocated' => DecimalMath::compare($available, '0.00') < 0 ? '0.00' : $available,
                'allocation_deficit' => DecimalMath::compare($available, '0.00') < 0 ? bcmul($available, '-1', 2) : '0.00',
                'goals' => $entries->groupBy('goal_id')->map(function ($rows) {
                    return ['goal_id' => $rows->first()->goal_id, 'goal_name' => $rows->first()->goal?->name, 'amount' => $this->sumAllocations($rows)];
                })->values(),
            ];
        })->values()->all();
    }

    private function sumAllocations(iterable $entries): string
    {
        $total = '0.00';
        foreach ($entries as $entry) {
            $delta = $entry->action === 'allocate' ? (string) $entry->amount : bcmul((string) $entry->amount, '-1', 2);
            $total = DecimalMath::add($total, $delta);
        }
        return $total;
    }
}
