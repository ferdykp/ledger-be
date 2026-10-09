<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class BillService
{
    public function save(User $user, array $data, ?Bill $bill = null): Bill
    {
        return DB::transaction(function () use ($user, $data, $bill) {
            if ($bill) {
                $bill = $user->bills()->lockForUpdate()->findOrFail($bill->id);
                $bill->update($data);
            } else {
                $bill = $user->bills()->create($data);
            }

            if ($bill->status === 'paid' && ! $bill->recurrence_generated_at) {
                $nextDate = match ($bill->frequency) {
                    'weekly' => $bill->due_date->copy()->addWeek(),
                    'monthly' => $bill->due_date->copy()->addMonthNoOverflow(),
                    'yearly' => $bill->due_date->copy()->addYearNoOverflow(),
                    default => null,
                };
                if ($nextDate) {
                    $next = $bill->replicate(['recurrence_generated_at']);
                    $next->fill(['status' => 'active', 'due_date' => $nextDate]);
                    $next->save();
                    $bill->forceFill(['recurrence_generated_at' => now()])->save();
                }
            }

            return $bill->fresh();
        }, 3);
    }
}
