<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Category;
use App\Models\Transaction;
use App\Support\DecimalMath;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AutomaticBillPaymentService
{
    public function __construct(private TransactionService $transactions) {}

    /** @return array{paid:int,attention:int,skipped:int} */
    public function processDue(?Carbon $today = null): array
    {
        $today ??= Carbon::now('Asia/Jakarta')->startOfDay();
        $stats = ['paid' => 0, 'attention' => 0, 'skipped' => 0];
        $ids = Bill::query()->where('auto_pay_enabled', true)->where('status', 'active')
            ->whereDate('due_date', '<=', $today->toDateString())->orderBy('due_date')->pluck('id');

        foreach ($ids as $id) {
            try {
                $result = $this->processOne((int) $id, $today);
                $stats[$result]++;
            } catch (\Throwable $error) {
                report($error);
                $stats['attention']++;
            }
        }

        return $stats;
    }

    private function processOne(int $billId, Carbon $today): string
    {
        return DB::transaction(function () use ($billId, $today) {
            $bill = Bill::query()->lockForUpdate()->find($billId);
            if (! $bill || ! $bill->auto_pay_enabled || $bill->status !== 'active' || $bill->due_date->isAfter($today)) {
                return 'skipped';
            }

            $period = $bill->due_date->format('Y-m-d');
            $payment = BillPayment::query()->where('bill_id', $bill->id)->whereDate('scheduled_for', $period)->lockForUpdate()->first();
            if ($payment?->status === 'paid') return 'skipped';
            $payment ??= new BillPayment(['bill_id' => $bill->id, 'user_id' => $bill->user_id, 'scheduled_for' => $period]);
            $payment->amount = $bill->amount;
            $payment->attempted_at = now('Asia/Jakarta');

            $account = $bill->auto_pay_account_id
                ? Account::query()->where('user_id', $bill->user_id)->lockForUpdate()->find($bill->auto_pay_account_id)
                : null;
            if (! $account || $account->is_archived || ! in_array($account->type, ['cash', 'bank', 'ewallet'], true)) {
                $payment->account_id = null;
                $payment->status = 'account_unavailable';
                $payment->message = 'Rekening pembayaran tidak tersedia. Pilih rekening aktif di pengaturan tagihan.';
                $payment->save();
                return 'attention';
            }

            if (DecimalMath::compare((string) $account->balance, (string) $bill->amount) < 0) {
                $payment->account_id = $account->id;
                $payment->status = 'insufficient_balance';
                $payment->message = 'Saldo belum cukup. Ledger akan mencoba kembali pada jadwal berikutnya.';
                $payment->save();
                return 'attention';
            }

            $categoryId = $bill->category
                ? Category::query()->where('user_id', $bill->user_id)->where('type', 'expense')
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($bill->category))])->value('id')
                : null;
            $idempotencyKey = 'bill-payment-'.$bill->id.'-'.$period;

            $transaction = $this->transactions->createTransaction($bill->user, [
                'account_id' => $account->id,
                'category_id' => $categoryId,
                'type' => 'expense',
                'amount' => (string) $bill->amount,
                'note' => 'Pembayaran otomatis: '.$bill->name,
                'date' => $period,
                'idempotency_key' => $idempotencyKey,
            ]);

            $payment->account_id = $account->id;
            $payment->transaction_id = $transaction->id;
            $payment->status = 'paid';
            $payment->message = null;
            $payment->save();

            if ($bill->frequency === 'once') {
                $bill->status = 'paid';
            } else {
                $bill->due_date = match ($bill->frequency) {
                    'weekly' => $bill->due_date->copy()->addWeek(),
                    'monthly' => $bill->due_date->copy()->addMonthNoOverflow(),
                    'yearly' => $bill->due_date->copy()->addYearNoOverflow(),
                    default => $bill->due_date,
                };
            }
            $bill->save();
            return 'paid';
        }, 3);
    }
}
