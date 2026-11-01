<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Asset;
use App\Models\AssetPrice;
use App\Models\AssetRefund;
use App\Models\AssetTransaction;
use App\Models\Goal;
use App\Models\GoalAssetAllocation;
use App\Models\Transaction;
use App\Models\User;
use App\Support\DecimalMath;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssetService
{
    public function assets(User $user): array
    {
        return Asset::where('user_id', $user->id)->with(['prices' => fn ($q) => $q->latest('captured_at')->limit(1), 'transactions', 'allocations'])
            ->latest()->get()->map(fn (Asset $asset) => $this->snapshot($asset))->all();
    }

    public function create(User $user, array $data): Asset
    {
        return DB::transaction(function () use ($user, $data) {
            $ownershipSource = $data['ownership_source'] ?? 'new';
            $assetType = $data['type'];
            $initial = [
                'quantity' => $data['initial_quantity'] ?? null,
                'amount' => $data['initial_cost_basis'] ?? null,
                'unit_price' => $data['initial_unit_price'] ?? null,
                'transaction_date' => $data['initial_date'] ?? null,
            ];
            unset($data['ownership_source'], $data['initial_quantity'], $data['initial_cost_basis'], $data['initial_unit_price'], $data['initial_date']);
            $asset = $user->assets()->create($data + ['currency' => 'IDR']);

            if ($ownershipSource === 'existing') {
                $type = $asset->type === 'gold' ? 'purchase' : 'subscription';
                $amount = (string) $initial['amount'];
                $unitPrice = $assetType === 'rdpu'
                    ? (string) $initial['unit_price']
                    : DecimalMath::divide($amount, (string) $initial['quantity'], 8);
                $quantity = $assetType === 'rdpu'
                    ? DecimalMath::divide($amount, $unitPrice, 8)
                    : (string) $initial['quantity'];
                $this->recordTransaction($user, $asset, [
                    'type' => $type,
                    'status' => 'settled',
                    'amount' => $amount,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'fees' => '0.00',
                    'cash_debited' => false,
                    'transaction_date' => $initial['transaction_date'],
                    'notes' => 'Saldo awal aset yang sudah dimiliki sebelum menggunakan Ledger.',
                ]);
            }

            return $asset->fresh();
        }, 3);
    }

    public function delete(User $user, Asset $asset): void
    {
        abort_unless($asset->user_id === $user->id, 404);

        DB::transaction(function () use ($user, $asset) {
            $asset = Asset::where('user_id', $user->id)->lockForUpdate()->findOrFail($asset->id);
            $assetTransactions = AssetTransaction::where('user_id', $user->id)
                ->where('asset_id', $asset->id)
                ->lockForUpdate()
                ->get();
            $transactionIds = $assetTransactions
                ->flatMap(fn (AssetTransaction $entry) => [$entry->cash_transaction_id, $entry->settlement_transaction_id])
                ->filter()
                ->unique()
                ->values();
            $refunds = AssetRefund::whereIn('asset_transaction_id', $assetTransactions->pluck('id'))
                ->lockForUpdate()
                ->get();
            $transactionIds = $transactionIds->merge($refunds->pluck('transaction_id'))->filter()->unique()->values();
            $cashEntries = Transaction::where('user_id', $user->id)
                ->whereIn('id', $transactionIds)
                ->orderBy('account_id')
                ->lockForUpdate()
                ->get();
            $accountIds = $cashEntries->pluck('account_id')->filter()->unique();
            $accounts = Account::where('user_id', $user->id)
                ->whereIn('id', $accountIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($cashEntries as $entry) {
                if (! in_array($entry->financial_purpose, ['asset_purchase', 'asset_sale', 'asset_refund'], true)) {
                    throw ValidationException::withMessages(['asset' => 'Arus kas aset tidak dikenali. Aset belum dihapus agar saldo tetap aman.']);
                }
                $account = $accounts->get($entry->account_id);
                if (! $account) {
                    throw ValidationException::withMessages(['asset' => 'Rekening terkait tidak ditemukan. Aset belum dihapus agar saldo tetap aman.']);
                }
                if ($entry->type === 'expense') {
                    $account->balance = DecimalMath::add((string) $account->balance, (string) $entry->amount);
                } elseif ($entry->type === 'income') {
                    $account->balance = DecimalMath::subtract((string) $account->balance, (string) $entry->amount);
                } else {
                    throw ValidationException::withMessages(['asset' => 'Jenis arus kas aset tidak dapat dibalik otomatis. Aset belum dihapus agar saldo tetap aman.']);
                }
                $account->save();
            }

            $refundIds = $refunds->pluck('id');
            if ($refundIds->isNotEmpty()) AssetRefund::whereIn('id', $refundIds)->delete();
            if ($transactionIds->isNotEmpty()) Transaction::where('user_id', $user->id)->whereIn('id', $transactionIds)->delete();
            GoalAssetAllocation::where('asset_id', $asset->id)->delete();
            AssetTransaction::where('asset_id', $asset->id)->delete();
            $asset->prices()->delete();
            $asset->delete();
        }, 3);
    }

    public function recordTransaction(User $user, Asset $asset, array $data): AssetTransaction
    {
        abort_unless($asset->user_id === $user->id, 404);
        return DB::transaction(function () use ($user, $asset, $data) {
            if (! empty($data['idempotency_key'])) {
                $existing = AssetTransaction::where('user_id', $user->id)->where('idempotency_key', $data['idempotency_key'])->first();
                if ($existing) {
                    $same = (int) $existing->asset_id === (int) $asset->id && $existing->type === $data['type']
                        && DecimalMath::compare((string) $existing->amount, (string) $data['amount']) === 0
                        && DecimalMath::compare((string) $existing->quantity, (string) $data['quantity'], 8) === 0;
                    abort_unless($same, 409, 'Kunci permintaan sudah digunakan untuk transaksi aset berbeda.');
                    return $existing;
                }
            }

            $asset = Asset::where('user_id', $user->id)->lockForUpdate()->findOrFail($asset->id);
            $type = $data['type'];
            $isBuy = in_array($type, ['purchase', 'subscription'], true);
            $isSell = in_array($type, ['sale', 'redemption'], true);
            if (($asset->type === 'gold' && ! in_array($type, ['purchase', 'sale'], true)) || ($asset->type === 'rdpu' && ! in_array($type, ['subscription', 'redemption'], true))) {
                throw ValidationException::withMessages(['type' => 'Jenis transaksi tidak sesuai dengan jenis aset.']);
            }
            $status = $data['status'] ?? ($asset->type === 'gold' ? 'settled' : ($isSell ? 'pending' : 'pending'));
            $amount = (string) $data['amount'];
            $fees = (string) ($data['fees'] ?? '0.00');
            if ($type === 'subscription' && $status === 'confirmed' && empty($data['cash_debited'])) {
                throw ValidationException::withMessages(['cash_debited' => 'Transaksi RDPU yang sudah dikonfirmasi harus mencatat apakah dana telah keluar dari rekening.']);
            }
            if ($asset->type === 'gold' && $status !== 'settled') throw ValidationException::withMessages(['status' => 'Transaksi emas dicatat setelah transaksi benar-benar terjadi.']);
            if ($isSell) $this->assertSellable($user, $asset, (string) $data['quantity']);

            $cashDebited = $isBuy && (bool) ($data['cash_debited'] ?? ($asset->type === 'gold'));
            if ($isBuy && DecimalMath::compare($amount, '0.00') <= 0) throw ValidationException::withMessages(['amount' => 'Nominal pembelian harus lebih besar dari nol.']);
            if ($isSell && $status === 'settled' && DecimalMath::compare($amount, '0.00') <= 0) throw ValidationException::withMessages(['amount' => 'Hasil penjualan aktual harus lebih besar dari nol.']);
            $cashAccount = null;
            if (($cashDebited || ($isSell && $status === 'settled')) && empty($data['cash_account_id'])) {
                throw ValidationException::withMessages(['cash_account_id' => 'Pilih rekening yang benar-benar digunakan atau menerima dana.']);
            }
            if (! empty($data['cash_account_id'])) $cashAccount = Account::where('user_id', $user->id)->lockForUpdate()->findOrFail($data['cash_account_id']);

            $unitPrice = (string) ($data['unit_price'] ?? DecimalMath::divide($amount, (string) $data['quantity'], 8));
            $storedStatus = $isSell && $status === 'settled' ? 'pending' : $status;
            $transaction = AssetTransaction::create([
                'user_id' => $user->id,
                'asset_id' => $asset->id,
                'cash_account_id' => $cashAccount?->id,
                'type' => $type,
                'status' => $storedStatus,
                'cash_debited' => $cashDebited,
                'amount' => $amount,
                'quantity' => $data['quantity'],
                'unit_price' => $unitPrice,
                'price_unit_grams' => $asset->type === 'gold' ? ($data['price_unit_grams'] ?? '1.00000000') : '1.00000000',
                'fees' => $fees,
                'transaction_date' => $data['transaction_date'],
                'confirmed_at' => in_array($status, ['confirmed', 'settled'], true) ? now() : null,
                'settled_at' => $storedStatus === 'settled' ? now() : null,
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            if ($cashDebited && $cashAccount) {
                $totalCash = DecimalMath::add($amount, $fees);
                $this->assertCashAvailable($cashAccount, $totalCash);
                $cashTransaction = $this->cashLeg($user, $cashAccount, 'expense', $totalCash, 'asset_purchase', "Pembelian {$asset->name}", $data['transaction_date']);
                $transaction->update(['cash_transaction_id' => $cashTransaction->id]);
            }

            if ($isSell && $status === 'settled') {
                $this->settleSale($user, $asset, $transaction, $cashAccount, $amount, $fees);
                $transaction->update(['status' => 'settled', 'settled_at' => now()]);
            }

            return $transaction->fresh(['asset', 'cashAccount', 'cashTransaction', 'settlementTransaction']);
        }, 3);
    }

    public function transition(User $user, AssetTransaction $transaction, array $data): AssetTransaction
    {
        abort_unless($transaction->user_id === $user->id, 404);
        return DB::transaction(function () use ($user, $transaction, $data) {
            $transaction = AssetTransaction::where('user_id', $user->id)->lockForUpdate()->findOrFail($transaction->id);
            $asset = Asset::where('user_id', $user->id)->lockForUpdate()->findOrFail($transaction->asset_id);
            if ($transaction->status === $data['status']) return $transaction->fresh(['asset', 'cashAccount', 'cashTransaction', 'settlementTransaction']);
            if ($transaction->status !== 'pending') throw ValidationException::withMessages(['status' => 'Hanya transaksi pending yang dapat diperbarui.']);
            $next = $data['status'];

            if (in_array($transaction->type, ['subscription'], true) && $next === 'confirmed') {
                $quantity = (string) ($data['quantity'] ?? $transaction->quantity);
                $unitPrice = (string) ($data['unit_price'] ?? $transaction->unit_price);
                $cashDebited = (bool) ($data['cash_debited'] ?? $transaction->cash_debited);
                if (! $cashDebited) throw ValidationException::withMessages(['cash_debited' => 'Konfirmasi unit memerlukan konfirmasi bahwa dana pembelian sudah keluar.']);
                if ($cashDebited && ! $transaction->cash_debited) {
                    $accountId = $data['cash_account_id'] ?? $transaction->cash_account_id;
                    if (! $accountId) throw ValidationException::withMessages(['cash_account_id' => 'Pilih rekening tempat dana dibayarkan.']);
                    $account = Account::where('user_id', $user->id)->lockForUpdate()->findOrFail($accountId);
                    $this->assertCashAvailable($account, DecimalMath::add((string) $transaction->amount, (string) $transaction->fees));
                    $cashTransaction = $this->cashLeg($user, $account, 'expense', DecimalMath::add((string) $transaction->amount, (string) $transaction->fees), 'asset_purchase', "Pembelian {$asset->name}", $transaction->transaction_date->format('Y-m-d'));
                    $transaction->cash_transaction_id = $cashTransaction->id;
                    $transaction->cash_account_id = $account->id;
                }
                if (DecimalMath::compare($quantity, '0.00000000', 8) <= 0 || DecimalMath::compare($unitPrice, '0.00000000', 8) <= 0) throw ValidationException::withMessages(['quantity' => 'Unit dan NAB aktual harus lebih besar dari nol.']);
                $transaction->quantity = $quantity;
                $transaction->unit_price = $unitPrice;
                $transaction->cash_debited = true;
                $transaction->status = 'confirmed';
                $transaction->confirmed_at = now();
            } elseif ($transaction->type === 'redemption' && $next === 'settled') {
                $accountId = $data['cash_account_id'] ?? $transaction->cash_account_id;
                if (! $accountId) throw ValidationException::withMessages(['cash_account_id' => 'Pilih rekening penerima hasil pencairan.']);
                $account = Account::where('user_id', $user->id)->lockForUpdate()->findOrFail($accountId);
                $amount = (string) ($data['amount'] ?? '0');
                $fees = (string) ($data['fees'] ?? $transaction->fees);
                if (DecimalMath::compare($amount, '0.00') <= 0) throw ValidationException::withMessages(['amount' => 'Masukkan hasil pencairan aktual.']);
                $this->assertSellable($user, $asset, (string) $transaction->quantity, $transaction->id);
                $this->settleSale($user, $asset, $transaction, $account, $amount, $fees);
                $transaction->status = 'settled';
                $transaction->settled_at = now();
                $transaction->amount = $amount;
                $transaction->fees = $fees;
                $transaction->cash_account_id = $account->id;
            } elseif (in_array($next, ['rejected', 'cancelled'], true)) {
                if ($transaction->cash_debited && ! empty($data['refund_received'])) {
                    $accountId = $data['cash_account_id'] ?? $transaction->cash_account_id;
                    if (! $accountId) throw ValidationException::withMessages(['cash_account_id' => 'Pilih rekening penerima pengembalian.']);
                    $this->createRefund($user, $transaction, (int) $accountId, (string) ($data['refund_amount'] ?? DecimalMath::add((string) $transaction->amount, (string) $transaction->fees)), now()->toDateString());
                }
                $transaction->status = $next;
            } else {
                throw ValidationException::withMessages(['status' => 'Perubahan status tersebut tidak didukung.']);
            }

            $transaction->save();
            return $transaction->fresh(['asset', 'cashAccount', 'cashTransaction', 'settlementTransaction']);
        }, 3);
    }

    public function setPrice(User $user, Asset $asset, array $data): AssetPrice
    {
        abort_unless($asset->user_id === $user->id, 404);
        $unit = $asset->type === 'gold' ? 'IDR/gram' : 'IDR/unit';
        return $asset->prices()->create(['price' => $data['price'], 'unit' => $unit, 'source' => $data['source'] ?? 'manual', 'captured_at' => $data['captured_at'] ?? now()]);
    }

    public function recordRefund(User $user, AssetTransaction $transaction, array $data): AssetRefund
    {
        abort_unless($transaction->user_id === $user->id, 404);
        return DB::transaction(function () use ($user, $transaction, $data) {
            $locked = AssetTransaction::where('user_id', $user->id)->lockForUpdate()->findOrFail($transaction->id);
            if (! empty($data['idempotency_key'])) {
                $existing = AssetRefund::where('user_id', $user->id)->where('idempotency_key', $data['idempotency_key'])->first();
                if ($existing) {
                    abort_unless((int) $existing->asset_transaction_id === (int) $locked->id && DecimalMath::compare((string) $existing->amount, (string) $data['amount']) === 0, 409, 'Kunci permintaan sudah digunakan untuk pengembalian berbeda.');
                    return $existing;
                }
            }
            if (! in_array($locked->status, ['rejected', 'cancelled'], true) || ! $locked->cash_debited) {
                throw ValidationException::withMessages(['transaction' => 'Pengembalian hanya dapat dicatat untuk pembelian RDPU yang ditolak/dibatalkan dan dananya sudah keluar.']);
            }
            $account = Account::where('user_id', $user->id)->lockForUpdate()->findOrFail($data['account_id']);
            return $this->createRefund($user, $locked, $account->id, (string) $data['amount'], $data['received_date'], $data['idempotency_key'] ?? null);
        }, 3);
    }

    public function allocate(User $user, Goal $goal, Asset $asset, array $data): GoalAssetAllocation
    {
        abort_unless($goal->user_id === $user->id && $asset->user_id === $user->id, 404);
        return DB::transaction(function () use ($user, $goal, $asset, $data) {
            $asset = Asset::where('user_id', $user->id)->lockForUpdate()->findOrFail($asset->id);
            if (! empty($data['idempotency_key'])) {
                $existing = GoalAssetAllocation::where('user_id', $user->id)->where('idempotency_key', $data['idempotency_key'])->first();
                if ($existing) {
                    $same = (int) $existing->goal_id === (int) $goal->id && (int) $existing->asset_id === (int) $asset->id
                        && $existing->action === $data['action']
                        && DecimalMath::compare((string) $existing->quantity, (string) $data['quantity'], 8) === 0;
                    abort_unless($same, 409, 'Kunci permintaan sudah digunakan untuk alokasi aset berbeda.');
                    return $existing;
                }
            }
            $available = $this->snapshot($asset)['unallocated_quantity'];
            $qty = (string) $data['quantity'];
            if ($data['action'] === 'allocate' && DecimalMath::compare($qty, $available, 8) > 0) {
                throw ValidationException::withMessages(['quantity' => 'Kepemilikan yang belum dialokasikan tidak mencukupi.']);
            }
            $goalAllocated = $this->sumAllocations($asset, $goal->id);
            if ($data['action'] === 'release' && DecimalMath::compare($qty, $goalAllocated, 8) > 0) {
                throw ValidationException::withMessages(['quantity' => 'Jumlah pelepasan melebihi alokasi target ini.']);
            }
            return GoalAssetAllocation::create(['user_id' => $user->id, 'goal_id' => $goal->id, 'asset_id' => $asset->id, 'quantity' => $qty, 'action' => $data['action'], 'note' => $data['note'] ?? null, 'idempotency_key' => $data['idempotency_key'] ?? null]);
        }, 3);
    }

    public function snapshot(Asset $asset): array
    {
        $asset->unsetRelation('transactions')->unsetRelation('prices')->load([
            'transactions.cashAccount', 'transactions.cashTransaction', 'transactions.settlementTransaction', 'transactions.refunds',
            'prices' => fn ($q) => $q->latest('captured_at')->limit(1),
        ]);
        $quantity = '0.00000000';
        $basis = '0.00';
        $bookValue = '0.00';
        $reserved = '0.00000000';
        $realized = '0.00';
        foreach ($asset->transactions->sortBy('id') as $tx) {
            if (in_array($tx->type, ['purchase', 'subscription'], true) && in_array($tx->status, ['confirmed', 'settled'], true)) {
                $quantity = DecimalMath::add($quantity, (string) $tx->quantity, 8);
                $basis = DecimalMath::add($basis, DecimalMath::add((string) $tx->amount, (string) $tx->fees));
                $bookValue = DecimalMath::add($bookValue, (string) $tx->amount);
            } elseif (in_array($tx->type, ['sale', 'redemption'], true) && $tx->status === 'settled') {
                $disposedBookValue = DecimalMath::compare($quantity, '0.00000000', 8) > 0
                    ? DecimalMath::quantityValue((string) $tx->quantity, DecimalMath::divide($bookValue, $quantity, 8), 8)
                    : '0.00';
                $quantity = DecimalMath::subtract($quantity, (string) $tx->quantity, 8);
                $basis = DecimalMath::subtract($basis, (string) $tx->cost_basis);
                $bookValue = DecimalMath::subtract($bookValue, $disposedBookValue);
                $realized = DecimalMath::add($realized, DecimalMath::subtract(DecimalMath::subtract((string) $tx->amount, (string) $tx->fees), (string) $tx->cost_basis));
            } elseif ($tx->type === 'redemption' && $tx->status === 'pending') {
                $reserved = DecimalMath::add($reserved, (string) $tx->quantity, 8);
            }
        }
        $allocated = $this->sumAllocations($asset);
        $free = DecimalMath::subtract(DecimalMath::subtract($quantity, $allocated, 8), $reserved, 8);
        $price = $asset->prices->sortByDesc('captured_at')->first();
        $value = $price ? DecimalMath::quantityValue($quantity, (string) $price->price, 8) : null;
        $allocationValue = $price ? DecimalMath::quantityValue($allocated, (string) $price->price, 8) : null;
        $unrealized = $value === null ? null : DecimalMath::subtract($value, $basis);
        return [
            'id' => $asset->id, 'type' => $asset->type, 'name' => $asset->name, 'provider' => $asset->provider,
            'identifier' => $asset->identifier, 'storage_type' => $asset->storage_type,
            'quantity' => $quantity, 'reserved_quantity' => $reserved, 'allocated_quantity' => $allocated,
            'unallocated_quantity' => DecimalMath::compare($free, '0.00000000', 8) < 0 ? '0.00000000' : $free,
            'cost_basis' => $basis, 'book_value' => $bookValue,
            'average_cost_per_unit' => DecimalMath::compare($quantity, '0.00000000', 8) > 0 ? DecimalMath::divide($basis, $quantity, 8) : '0.00000000',
            'latest_price' => $price ? (string) $price->price : null, 'price_unit' => $price?->unit,
            'price_source' => $price?->source, 'valuation_at' => $price?->captured_at?->toISOString(),
            'estimated_value' => $value, 'allocated_value' => $allocationValue, 'unrealized_profit_loss' => $unrealized,
            'realized_profit_loss' => $realized,
            'valuation_stale' => $price ? $price->captured_at->lt(now()->subDays(30)) : null,
            'allocation_deficit' => DecimalMath::compare($free, '0.00000000', 8) < 0 ? bcmul($free, '-1', 8) : '0.00000000',
            'transactions' => $asset->transactions->sortByDesc('transaction_date')->values(),
        ];
    }

    private function settleSale(User $user, Asset $asset, AssetTransaction $sale, Account $account, string $amount, string $fees): void
    {
        $this->assertSellable($user, $asset, (string) $sale->quantity, $sale->id);
        $snapshot = $this->snapshot($asset);
        $costBasis = DecimalMath::quantityValue((string) $sale->quantity, (string) $snapshot['average_cost_per_unit'], 8);
        $proceeds = DecimalMath::subtract($amount, $fees);
        if (DecimalMath::compare($proceeds, '0.00') < 0) throw ValidationException::withMessages(['fees' => 'Biaya tidak boleh melebihi hasil penjualan.']);
        $cashTransaction = $this->cashLeg($user, $account, 'income', $proceeds, 'asset_sale', "Pencairan {$asset->name}", now()->toDateString());
        $sale->update(['cost_basis' => $costBasis, 'settlement_transaction_id' => $cashTransaction->id]);
    }

    private function assertSellable(User $user, Asset $asset, string $quantity, ?int $excludeTransactionId = null): void
    {
        $snapshot = $this->snapshot($asset);
        $pending = $asset->transactions()->where('type', 'redemption')->where('status', 'pending')->when($excludeTransactionId, fn ($q) => $q->where('id', '!=', $excludeTransactionId))->get();
        $reserved = '0.00000000';
        foreach ($pending as $tx) $reserved = DecimalMath::add($reserved, (string) $tx->quantity, 8);
        $available = DecimalMath::subtract(DecimalMath::subtract($snapshot['quantity'], $snapshot['allocated_quantity'], 8), $reserved, 8);
        if (DecimalMath::compare($quantity, $available, 8) > 0) throw ValidationException::withMessages(['quantity' => 'Kepemilikan tersedia tidak mencukupi setelah memperhitungkan alokasi dan pencairan pending.']);
    }

    private function sumAllocations(Asset $asset, ?int $goalId = null): string
    {
        $rows = GoalAssetAllocation::where('asset_id', $asset->id)->when($goalId, fn ($q) => $q->where('goal_id', $goalId))->get();
        $total = '0.00000000';
        foreach ($rows as $row) $total = $row->action === 'allocate' ? DecimalMath::add($total, (string) $row->quantity, 8) : DecimalMath::subtract($total, (string) $row->quantity, 8);
        return $total;
    }

    private function assertCashAvailable(Account $account, string $amount): void
    {
        if (DecimalMath::compare((string) $account->balance, $amount) < 0) throw ValidationException::withMessages(['amount' => 'Saldo rekening tidak mencukupi untuk pembelian aset ini.']);
    }

    private function cashLeg(User $user, Account $account, string $type, string $amount, string $purpose, string $note, string $date): Transaction
    {
        if ($type === 'expense') $account->decrement('balance', $amount);
        else $account->increment('balance', $amount);
        return $user->transactions()->create(['account_id' => $account->id, 'type' => $type, 'amount' => $amount, 'date' => $date, 'note' => $note, 'financial_purpose' => $purpose]);
    }

    private function createRefund(User $user, AssetTransaction $assetTransaction, int $accountId, string $amount, string $date, ?string $idempotencyKey = null): AssetRefund
    {
        $account = Account::where('user_id', $user->id)->lockForUpdate()->findOrFail($accountId);
        $original = $assetTransaction->cash_transaction_id ? Transaction::whereKey($assetTransaction->cash_transaction_id)->value('amount') : '0.00';
        $alreadyRefunded = '0.00';
        foreach (AssetRefund::where('asset_transaction_id', $assetTransaction->id)->lockForUpdate()->get() as $refund) $alreadyRefunded = DecimalMath::add($alreadyRefunded, (string) $refund->amount);
        $remaining = DecimalMath::subtract((string) $original, $alreadyRefunded);
        if (DecimalMath::compare($amount, '0.00') <= 0 || DecimalMath::compare($amount, $remaining) > 0) {
            throw ValidationException::withMessages(['amount' => 'Nominal pengembalian melebihi sisa dana yang belum dikembalikan.']);
        }
        $cashTransaction = $this->cashLeg($user, $account, 'income', $amount, 'asset_refund', 'Pengembalian pembelian aset', $date);
        return AssetRefund::create(['user_id' => $user->id, 'asset_transaction_id' => $assetTransaction->id, 'account_id' => $account->id, 'transaction_id' => $cashTransaction->id, 'amount' => $amount, 'received_date' => $date, 'idempotency_key' => $idempotencyKey]);
    }
}
