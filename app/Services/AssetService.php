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
            // API consumers that omit the onboarding choice still create an empty asset for backward compatibility.
            $ownershipSource = $data['ownership_source'] ?? 'empty';
            $assetType = $data['type'];
            $initial = [
                'quantity' => $data['initial_quantity'] ?? null,
                'amount' => $data['initial_cost_basis'] ?? null,
                'current_value' => $data['initial_current_value'] ?? null,
                'nav' => $data['initial_nav'] ?? null,
                'transaction_date' => $data['initial_date'] ?? null,
                'mode' => $data['opening_mode'] ?? 'units',
            ];
            $purchase = [
                'amount' => $data['purchase_amount'] ?? null,
                'quantity' => $data['purchase_quantity'] ?? null,
                'status' => $data['purchase_status'] ?? 'settled',
                'account_id' => $data['purchase_cash_account_id'] ?? null,
                'cash_debited' => $data['purchase_cash_debited'] ?? true,
                'fees' => $data['purchase_fees'] ?? '0.00',
                'date' => $data['purchase_date'] ?? null,
            ];
            unset($data['ownership_source'], $data['initial_quantity'], $data['initial_cost_basis'], $data['initial_current_value'], $data['initial_nav'], $data['initial_date'], $data['opening_mode'], $data['purchase_amount'], $data['purchase_quantity'], $data['purchase_status'], $data['purchase_cash_account_id'], $data['purchase_cash_debited'], $data['purchase_fees'], $data['purchase_date']);
            if ($assetType === 'rdpu') {
                $data['valuation_mode'] = $ownershipSource === 'existing' && $initial['mode'] === 'value_only' ? 'value_only' : 'units';
                $data['manual_current_value'] = $data['valuation_mode'] === 'value_only' ? $initial['current_value'] : null;
            }
            $asset = $user->assets()->create($data + ['currency' => 'IDR']);

            if ($ownershipSource === 'new') {
                $quantity = (string) ($purchase['quantity'] ?? '0');
                $amount = (string) $purchase['amount'];
                $this->recordTransaction($user, $asset, [
                    'type' => $assetType === 'gold' ? 'purchase' : 'subscription',
                    'status' => $assetType === 'gold' ? 'settled' : $purchase['status'],
                    'amount' => $amount,
                    'quantity' => $quantity,
                    'unit_price' => DecimalMath::compare($quantity, '0.00000000', 8) > 0 ? DecimalMath::divide($amount, $quantity, 8) : null,
                    'fees' => $purchase['fees'],
                    'cash_account_id' => $purchase['account_id'],
                    'cash_debited' => (bool) $purchase['cash_debited'],
                    'transaction_date' => $purchase['date'],
                    'origin' => 'live',
                ]);
            }

            if ($ownershipSource === 'existing') {
                $amount = (string) ($initial['amount'] ?? '0.00');
                $quantity = $asset->type === 'rdpu' && $initial['mode'] === 'value_only' ? '0.00000000' : (string) $initial['quantity'];
                $known = $initial['amount'] !== null && DecimalMath::compare($amount, '0.00') > 0;
                AssetTransaction::create([
                    'user_id' => $user->id, 'asset_id' => $asset->id,
                    'type' => $asset->type === 'gold' ? 'purchase' : 'subscription',
                    'status' => $asset->type === 'gold' ? 'settled' : 'confirmed', 'origin' => 'opening',
                    'cash_debited' => false, 'amount' => $amount, 'quantity' => $quantity,
                    'unit_price' => $known && DecimalMath::compare($quantity, '0.00000000', 8) > 0 ? DecimalMath::divide($amount, $quantity, 8) : '0.00000000',
                    'fees' => '0.00', 'cost_basis' => $known ? $amount : '0.00', 'cost_basis_known' => $known,
                    'transaction_date' => $initial['transaction_date'], 'confirmed_at' => now(),
                    'notes' => 'Saldo awal aset yang sudah dimiliki sebelum menggunakan Ledger.',
                ]);
                if ($asset->type === 'rdpu' && $initial['mode'] === 'units' && $initial['nav']) {
                    $asset->prices()->create(['price' => $initial['nav'], 'unit' => 'IDR/unit', 'source' => 'saldo-awal', 'captured_at' => $initial['transaction_date']]);
                }
            }

            return $asset->fresh();
        }, 3);
    }

    public function updateMetadata(User $user, Asset $asset, array $data): Asset
    {
        abort_unless($asset->user_id === $user->id, 404);
        return DB::transaction(function () use ($user, $asset, $data) {
            $asset = Asset::where('user_id', $user->id)->lockForUpdate()->findOrFail($asset->id);
            $asset->update([
                'name' => trim($data['name']),
                'provider' => $data['provider'] ?? null,
                'identifier' => $data['identifier'] ?? null,
                'storage_type' => $asset->type === 'gold' ? ($data['storage_type'] ?? $asset->storage_type) : null,
            ]);
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
            $asset = Asset::where('user_id', $user->id)->lockForUpdate()->findOrFail($asset->id);
            if (! empty($data['idempotency_key'])) {
                $existing = AssetTransaction::where('user_id', $user->id)->where('idempotency_key', $data['idempotency_key'])->first();
                if ($existing) {
                    $same = (int) $existing->asset_id === (int) $asset->id && $existing->type === $data['type']
                        && DecimalMath::compare((string) $existing->amount, (string) $data['amount']) === 0
                        && (empty($data['quantity']) || DecimalMath::compare((string) $existing->quantity, (string) $data['quantity'], 8) === 0);
                    abort_unless($same, 409, 'Kunci permintaan sudah digunakan untuk transaksi aset berbeda.');
                    return $existing;
                }
            }

            $type = $data['type'];
            $isBuy = in_array($type, ['purchase', 'subscription'], true);
            $isSell = in_array($type, ['sale', 'redemption'], true);
            if (($asset->type === 'gold' && ! in_array($type, ['purchase', 'sale'], true)) || ($asset->type === 'rdpu' && ! in_array($type, ['subscription', 'redemption'], true))) {
                throw ValidationException::withMessages(['type' => 'Jenis transaksi tidak sesuai dengan jenis aset.']);
            }
            $status = $data['status'] ?? ($asset->type === 'gold' ? 'settled' : ($isSell ? 'pending' : 'pending'));
            $amount = (string) $data['amount'];
            $fees = (string) ($data['fees'] ?? '0.00');
            $manualRdpu = $asset->type === 'rdpu' && in_array($asset->valuation_mode, ['manual', 'value_only'], true);
            $quantity = $manualRdpu
                ? ($isSell ? $this->manualQuantityForValue($asset, $amount) : DecimalMath::add($amount, $fees))
                : (string) ($data['quantity'] ?? '0');
            if ($asset->type === 'rdpu' && ! $manualRdpu && in_array($type, ['subscription', 'redemption'], true)
                && ! ($type === 'subscription' && $status === 'pending')
                && DecimalMath::compare($quantity, '0.00000000', 8) <= 0) {
                throw ValidationException::withMessages(['quantity' => 'Masukkan jumlah unit sesuai bukti transaksi.']);
            }
            // Direct transaction calls are live cash events. Opening and imported history are created only by their dedicated flows.
            $origin = 'live';
            if ($type === 'subscription' && $status === 'confirmed' && $origin === 'live' && empty($data['cash_debited'])) {
                throw ValidationException::withMessages(['cash_debited' => 'Transaksi RDPU yang sudah dikonfirmasi harus mencatat apakah dana telah keluar dari rekening.']);
            }
            if ($asset->type === 'gold' && $status !== 'settled') throw ValidationException::withMessages(['status' => 'Transaksi emas dicatat setelah transaksi benar-benar terjadi.']);
            if ($isSell) $this->assertSellable($user, $asset, $quantity);

            $cashDebited = $origin === 'live' && $isBuy && (bool) ($data['cash_debited'] ?? ($asset->type === 'gold'));
            if ($isBuy && DecimalMath::compare($amount, '0.00') <= 0) throw ValidationException::withMessages(['amount' => 'Nominal pembelian harus lebih besar dari nol.']);
            if ($isSell && $status === 'settled' && DecimalMath::compare($amount, '0.00') <= 0) throw ValidationException::withMessages(['amount' => 'Hasil penjualan aktual harus lebih besar dari nol.']);
            $cashAccount = null;
            if (($cashDebited || ($isSell && $status === 'settled')) && empty($data['cash_account_id'])) {
                throw ValidationException::withMessages(['cash_account_id' => 'Pilih rekening yang benar-benar digunakan atau menerima dana.']);
            }
            if (! empty($data['cash_account_id'])) $cashAccount = Account::where('user_id', $user->id)->lockForUpdate()->findOrFail($data['cash_account_id']);

            $unitPrice = $manualRdpu
                ? '0.00000000'
                : (string) ($data['unit_price'] ?? (DecimalMath::compare($quantity, '0.00000000', 8) > 0 ? DecimalMath::divide($amount, $quantity, 8) : '0.00000000'));
            $storedStatus = $isSell && $status === 'settled' ? 'pending' : $status;
            $transaction = AssetTransaction::create([
                'user_id' => $user->id,
                'asset_id' => $asset->id,
                'cash_account_id' => $cashAccount?->id,
                'type' => $type,
                'status' => $storedStatus,
                'origin' => $origin,
                'cash_debited' => $cashDebited,
                'amount' => $amount,
                'quantity' => $quantity,
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

            if ($manualRdpu && $isBuy && in_array($status, ['confirmed', 'settled'], true)) {
                $asset->manual_current_value = DecimalMath::add((string) $asset->manual_current_value, $amount);
                $asset->save();
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
                $manualRdpu = $asset->type === 'rdpu' && in_array($asset->valuation_mode, ['manual', 'value_only'], true);
                $quantity = $manualRdpu ? (string) $transaction->amount : (string) ($data['quantity'] ?? $transaction->quantity);
                $unitPrice = $manualRdpu ? '1.00000000' : (string) ($data['unit_price'] ?? (DecimalMath::compare($quantity, '0.00000000', 8) > 0 ? DecimalMath::divide((string) $transaction->amount, $quantity, 8) : '0.00000000'));
                $cashDebited = (bool) ($data['cash_debited'] ?? $transaction->cash_debited);
                if (! $cashDebited) throw ValidationException::withMessages(['cash_debited' => 'Konfirmasi pembelian memerlukan konfirmasi bahwa dana sudah keluar.']);
                if ($cashDebited && ! $transaction->cash_debited) {
                    $accountId = $data['cash_account_id'] ?? $transaction->cash_account_id;
                    if (! $accountId) throw ValidationException::withMessages(['cash_account_id' => 'Pilih rekening tempat dana dibayarkan.']);
                    $account = Account::where('user_id', $user->id)->lockForUpdate()->findOrFail($accountId);
                    $this->assertCashAvailable($account, DecimalMath::add((string) $transaction->amount, (string) $transaction->fees));
                    $cashTransaction = $this->cashLeg($user, $account, 'expense', DecimalMath::add((string) $transaction->amount, (string) $transaction->fees), 'asset_purchase', "Pembelian {$asset->name}", $transaction->transaction_date->format('Y-m-d'));
                    $transaction->cash_transaction_id = $cashTransaction->id;
                    $transaction->cash_account_id = $account->id;
                }
                if (! $manualRdpu && (DecimalMath::compare($quantity, '0.00000000', 8) <= 0 || DecimalMath::compare($unitPrice, '0.00000000', 8) <= 0)) throw ValidationException::withMessages(['quantity' => 'Masukkan unit penyertaan aktual yang tertera setelah pembelian dikonfirmasi.']);
                $transaction->quantity = $quantity;
                $transaction->unit_price = $unitPrice;
                $transaction->cash_debited = true;
                $transaction->status = 'confirmed';
                $transaction->confirmed_at = now();
                if ($manualRdpu) {
                    $asset->manual_current_value = DecimalMath::add((string) $asset->manual_current_value, (string) $transaction->amount);
                    $asset->save();
                }
            } elseif ($transaction->type === 'redemption' && $next === 'settled') {
                $accountId = $data['cash_account_id'] ?? $transaction->cash_account_id;
                if (! $accountId) throw ValidationException::withMessages(['cash_account_id' => 'Pilih rekening penerima hasil pencairan.']);
                $account = Account::where('user_id', $user->id)->lockForUpdate()->findOrFail($accountId);
                $amount = (string) ($data['amount'] ?? '0');
                $fees = (string) ($data['fees'] ?? $transaction->fees);
                if (DecimalMath::compare($amount, '0.00') <= 0) throw ValidationException::withMessages(['amount' => 'Masukkan hasil pencairan aktual.']);
                if ($asset->type === 'rdpu' && in_array($asset->valuation_mode, ['manual', 'value_only'], true)) {
                    $transaction->quantity = $this->manualQuantityForValue($asset, $amount);
                }
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
        return DB::transaction(function () use ($user, $asset, $data) {
            $asset = Asset::where('user_id', $user->id)->lockForUpdate()->findOrFail($asset->id);
            if ($asset->type === 'rdpu' && in_array($asset->valuation_mode, ['manual', 'value_only'], true)) {
                $asset->manual_current_value = $data['price'];
                $asset->save();
                $unit = 'IDR/portfolio';
            } elseif ($asset->type === 'rdpu') {
                $unit = 'IDR/unit';
            } else {
                $unit = 'IDR/gram';
            }

            return $asset->prices()->create([
                'price' => $data['price'],
                'unit' => $unit,
                'source' => $data['source'] ?? 'manual',
                'captured_at' => $data['captured_at'] ?? now(),
            ]);
        }, 3);
    }

    public function completeRdpuUnits(User $user, Asset $asset, array $data): Asset
    {
        abort_unless($asset->user_id === $user->id, 404);
        return DB::transaction(function () use ($user, $asset, $data) {
            $asset = Asset::where('user_id', $user->id)->lockForUpdate()->findOrFail($asset->id);
            if ($asset->type !== 'rdpu' || ! in_array($asset->valuation_mode, ['manual', 'value_only'], true)) {
                throw ValidationException::withMessages(['asset' => 'Aset ini sudah menggunakan pencatatan unit.']);
            }
            $before = $this->snapshot($asset);
            $legacyUnits = '0.00000000';
            foreach (AssetTransaction::where('asset_id', $asset->id)->whereNull('superseded_at')->orderBy('id')->get() as $row) {
                if ($row->type === 'subscription' && in_array($row->status, ['confirmed', 'settled'], true)) {
                    $legacyUnits = DecimalMath::add($legacyUnits, DecimalMath::add((string) $row->amount, (string) $row->fees), 8);
                } elseif ($row->type === 'redemption' && $row->status === 'settled') {
                    $legacyUnits = DecimalMath::subtract($legacyUnits, (string) $row->cost_basis, 8);
                }
            }
            $currentValue = (string) $data['current_value'];
            if (DecimalMath::compare($legacyUnits, '0.00000000', 8) <= 0) $legacyUnits = $currentValue;
            if (DecimalMath::compare($legacyUnits, '0.00000000', 8) <= 0) {
                throw ValidationException::withMessages(['asset' => 'Saldo nilai sementara tidak dapat direkonsiliasi. Periksa histori transaksi aset terlebih dahulu.']);
            }
            $quantity = (string) $data['quantity'];
            $nav = (string) ($data['nav'] ?? DecimalMath::divide($currentValue, $quantity, 8));
            if (isset($data['nav'])) {
                $valueFromUnits = DecimalMath::quantityValue($quantity, $nav, 8);
                $valueDifference = DecimalMath::subtract($valueFromUnits, $currentValue);
                if (DecimalMath::compare($valueDifference, '0.00') < 0) $valueDifference = bcmul($valueDifference, '-1', 2);
                if (DecimalMath::compare($valueDifference, '1.00') > 0) {
                    throw ValidationException::withMessages(['nav' => 'Nilai unit × NAB berbeda lebih dari Rp1 dari nilai portofolio. Periksa kembali angka yang dimasukkan.']);
                }
            }
            $rows = AssetTransaction::where('asset_id', $asset->id)->whereNull('superseded_at')->lockForUpdate()->get();
            foreach ($rows as $row) $row->update(['superseded_at' => now()]);
            foreach (GoalAssetAllocation::where('asset_id', $asset->id)->lockForUpdate()->get() as $allocation) {
                $allocation->quantity = DecimalMath::multiply(DecimalMath::divide((string) $allocation->quantity, $legacyUnits, 8), $quantity, 8);
                $allocation->save();
            }
            $basisAmount = $data['cost_basis'] ?? ($before['cost_basis_known'] ? $before['cost_basis'] : null);
            $basisKnown = $basisAmount !== null && DecimalMath::compare((string) $basisAmount, '0.00') > 0;
            AssetTransaction::create([
                'user_id' => $user->id, 'asset_id' => $asset->id, 'type' => 'subscription',
                'status' => 'confirmed', 'origin' => 'opening', 'cash_debited' => false,
                'amount' => $basisKnown ? $basisAmount : '0.00', 'quantity' => $quantity,
                'unit_price' => $basisKnown ? DecimalMath::divide((string) $basisAmount, $quantity, 8) : '0.00000000',
                'fees' => '0.00', 'cost_basis' => $basisKnown ? $basisAmount : '0.00', 'cost_basis_known' => $basisKnown,
                'transaction_date' => $data['captured_at'], 'confirmed_at' => now(),
                'notes' => 'Rekonsiliasi saldo sementara ke unit aktual; transaksi lama disimpan sebagai jejak audit.',
            ]);
            $asset->valuation_mode = 'units';
            $asset->manual_current_value = null;
            $asset->save();
            $asset->prices()->create(['price' => $nav, 'unit' => 'IDR/unit', 'source' => 'rekonsiliasi-saldo-awal', 'captured_at' => $data['captured_at']]);
            return $asset->fresh();
        }, 3);
    }

    public function importHistory(User $user, Asset $asset, array $data): array
    {
        abort_unless($asset->user_id === $user->id, 404);
        return DB::transaction(function () use ($user, $asset, $data) {
            $asset = Asset::where('user_id', $user->id)->lockForUpdate()->findOrFail($asset->id);
            if ($asset->type === 'rdpu' && in_array($asset->valuation_mode, ['manual', 'value_only'], true)) {
                throw ValidationException::withMessages(['asset' => 'Lengkapi unit RDPU sebelum mengimpor histori pembelian.']);
            }
            $requestHash = hash('sha256', json_encode([
                'reconcile_opening' => (bool) ($data['reconcile_opening'] ?? false),
                'transactions' => $data['transactions'],
            ], JSON_THROW_ON_ERROR));
            if (! empty($data['idempotency_key'])) {
                $previousBatch = DB::table('asset_import_batches')->where('user_id', $user->id)->where('idempotency_key', $data['idempotency_key'])->first();
                if ($previousBatch) {
                    abort_unless((int) $previousBatch->asset_id === (int) $asset->id, 409, 'Kunci permintaan sudah digunakan untuk impor aset berbeda.');
                    abort_unless(hash_equals((string) $previousBatch->payload_hash, $requestHash), 409, 'Kunci permintaan sudah digunakan untuk data histori berbeda.');
                    return ['batch_id' => $previousBatch->id, 'asset' => $this->snapshot($asset)];
                }
            }
            $buyType = $asset->type === 'gold' ? 'purchase' : 'subscription';
            $sellType = $asset->type === 'gold' ? 'sale' : 'redemption';
            $openingRows = AssetTransaction::where('asset_id', $asset->id)->where('origin', 'opening')->whereNull('superseded_at')->lockForUpdate()->get();
            $openingQuantity = '0.00000000';
            foreach ($openingRows as $row) $openingQuantity = DecimalMath::add($openingQuantity, (string) $row->quantity, 8);
            $reconcile = (bool) ($data['reconcile_opening'] ?? false);
            if (DecimalMath::compare($openingQuantity, '0.00000000', 8) > 0 && ! $reconcile) {
                throw ValidationException::withMessages(['reconcile_opening' => 'Aset sudah memiliki saldo awal. Pilih rekonsiliasi agar histori menggantikan saldo awal, bukan menambahkannya.']);
            }
            if ($reconcile && DecimalMath::compare($openingQuantity, '0.00000000', 8) <= 0) {
                throw ValidationException::withMessages(['reconcile_opening' => 'Tidak ada saldo awal berkuantitas untuk direkonsiliasi.']);
            }
            $entries = collect($data['transactions'])->sortBy('transaction_date')->values()->all();
            $historyQuantity = '0.00000000';
            $runningQuantity = '0.00000000';
            $runningBasis = '0.00';
            foreach ($entries as $entry) {
                $quantity = (string) $entry['quantity'];
                $fees = (string) ($entry['fees'] ?? '0.00');
                $basisCost = DecimalMath::add((string) $entry['amount'], $fees);
                $action = $entry['action'] ?? 'purchase';
                if ($action === 'purchase') {
                    $historyQuantity = DecimalMath::add($historyQuantity, $quantity, 8);
                    $runningQuantity = DecimalMath::add($runningQuantity, $quantity, 8);
                    $runningBasis = DecimalMath::add($runningBasis, $basisCost);
                } else {
                    $historyQuantity = DecimalMath::subtract($historyQuantity, $quantity, 8);
                    if (DecimalMath::compare($quantity, $runningQuantity, 8) > 0) {
                        throw ValidationException::withMessages(['transactions' => 'Histori penjualan melebihi unit yang sudah dibeli sebelumnya. Periksa urutan dan jumlah transaksi.']);
                    }
                    $basisCost = DecimalMath::quantityValue($quantity, DecimalMath::divide($runningBasis, $runningQuantity, 8), 8);
                    $runningQuantity = DecimalMath::subtract($runningQuantity, $quantity, 8);
                    $runningBasis = DecimalMath::subtract($runningBasis, $basisCost);
                }
            }
            if ($reconcile && DecimalMath::compare($historyQuantity, $openingQuantity, 8) !== 0) {
                throw ValidationException::withMessages(['transactions' => 'Total unit histori harus sama dengan unit saldo awal sebelum rekonsiliasi.']);
            }
            $batchId = DB::table('asset_import_batches')->insertGetId([
                'user_id' => $user->id, 'asset_id' => $asset->id, 'reconciled_opening' => $reconcile,
                'transaction_count' => count($entries), 'idempotency_key' => $data['idempotency_key'] ?? null, 'payload_hash' => $requestHash, 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($reconcile) foreach ($openingRows as $row) $row->update(['superseded_at' => now()]);
            $runningQuantity = '0.00000000';
            $runningBasis = '0.00';
            foreach ($entries as $entry) {
                $quantity = (string) $entry['quantity'];
                $amount = (string) $entry['amount'];
                $fees = (string) ($entry['fees'] ?? '0.00');
                $action = $entry['action'] ?? 'purchase';
                $buy = $action === 'purchase';
                $costBasis = $buy ? DecimalMath::add($amount, $fees) : DecimalMath::quantityValue($quantity, DecimalMath::divide($runningBasis, $runningQuantity, 8), 8);
                AssetTransaction::create([
                    'user_id' => $user->id, 'asset_id' => $asset->id, 'import_batch_id' => $batchId,
                    'type' => $buy ? $buyType : $sellType, 'status' => $buy && $asset->type === 'rdpu' ? 'confirmed' : 'settled',
                    'origin' => 'historical', 'cash_debited' => false, 'amount' => $amount,
                    'quantity' => $quantity, 'unit_price' => (string) ($entry['unit_price'] ?? DecimalMath::divide($amount, $quantity, 8)),
                    'fees' => $fees, 'cost_basis' => $costBasis,
                    'cost_basis_known' => true, 'transaction_date' => $entry['transaction_date'], 'confirmed_at' => now(),
                    'notes' => $entry['notes'] ?? 'Impor histori investasi; tidak mengubah saldo rekening.',
                ]);
                if ($buy) {
                    $runningQuantity = DecimalMath::add($runningQuantity, $quantity, 8);
                    $runningBasis = DecimalMath::add($runningBasis, $costBasis);
                } else {
                    $runningQuantity = DecimalMath::subtract($runningQuantity, $quantity, 8);
                    $runningBasis = DecimalMath::subtract($runningBasis, $costBasis);
                }
            }
            return ['batch_id' => $batchId, 'asset' => $this->snapshot($asset->fresh())];
        }, 3);
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
            if ($asset->type === 'rdpu' && in_array($asset->valuation_mode, ['manual', 'value_only'], true)) {
                throw ValidationException::withMessages(['asset' => 'Lengkapi unit penyertaan RDPU sebelum mengalokasikan aset ke target.']);
            }
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
            'transactions' => fn ($q) => $q->whereNull('superseded_at')->with(['cashAccount', 'cashTransaction', 'settlementTransaction', 'refunds']),
            'prices' => fn ($q) => $q->latest('captured_at')->limit(1),
        ]);
        $quantity = '0.00000000';
        $basis = '0.00';
        $basisKnown = true;
        $bookValue = '0.00';
        $reserved = '0.00000000';
        $realized = '0.00';
        $manualRdpu = $asset->type === 'rdpu' && in_array($asset->valuation_mode, ['manual', 'value_only'], true);
        foreach ($asset->transactions->sortBy([['transaction_date', 'asc'], ['id', 'asc']]) as $tx) {
            if (in_array($tx->type, ['purchase', 'subscription'], true) && in_array($tx->status, ['confirmed', 'settled'], true)) {
                $quantity = DecimalMath::add($quantity, $manualRdpu ? DecimalMath::add((string) $tx->amount, (string) $tx->fees) : (string) $tx->quantity, 8);
                if ($tx->cost_basis_known) $basis = DecimalMath::add($basis, DecimalMath::add((string) $tx->amount, (string) $tx->fees));
                else $basisKnown = false;
                $bookValue = DecimalMath::add($bookValue, $manualRdpu ? DecimalMath::add((string) $tx->amount, (string) $tx->fees) : (string) $tx->amount);
            } elseif (in_array($tx->type, ['sale', 'redemption'], true) && $tx->status === 'settled') {
                $disposedBookValue = $manualRdpu
                    ? (string) $tx->cost_basis
                    : (DecimalMath::compare($quantity, '0.00000000', 8) > 0
                    ? DecimalMath::quantityValue((string) $tx->quantity, DecimalMath::divide($bookValue, $quantity, 8), 8)
                    : '0.00');
                $quantity = DecimalMath::subtract($quantity, $manualRdpu ? $disposedBookValue : (string) $tx->quantity, 8);
                if ($tx->cost_basis_known) $basis = DecimalMath::subtract($basis, (string) $tx->cost_basis);
                else $basisKnown = false;
                $bookValue = DecimalMath::subtract($bookValue, $disposedBookValue);
                $realized = DecimalMath::add($realized, DecimalMath::subtract(DecimalMath::subtract((string) $tx->amount, (string) $tx->fees), (string) $tx->cost_basis));
            } elseif ($tx->type === 'redemption' && $tx->status === 'pending') {
                $reserved = DecimalMath::add($reserved, (string) $tx->quantity, 8);
            }
        }
        $allocated = $this->sumAllocations($asset);
        $free = DecimalMath::subtract(DecimalMath::subtract($quantity, $allocated, 8), $reserved, 8);
        $averageCost = DecimalMath::compare($quantity, '0.00000000', 8) > 0
            ? DecimalMath::divide($basis, $quantity, 8)
            : '0.00000000';
        $manualBalance = $manualRdpu ? DecimalMath::add((string) ($asset->manual_current_value ?? $quantity), '0.00') : $bookValue;
        $manualAllocated = $manualRdpu
            ? $allocated
            : DecimalMath::multiply($allocated, $averageCost, 8);
        $manualReserved = $manualRdpu
            ? $reserved
            : DecimalMath::multiply($reserved, $averageCost, 8);
        $manualFree = DecimalMath::subtract(DecimalMath::subtract($manualBalance, $manualAllocated, 8), $manualReserved, 8);
        $price = $asset->prices->sortByDesc('captured_at')->first();
        $value = $manualRdpu
            ? DecimalMath::add((string) ($asset->manual_current_value ?? $bookValue), '0.00')
            : ($price ? DecimalMath::quantityValue($quantity, (string) $price->price, 8) : null);
        $allocationValue = $manualRdpu
            ? (DecimalMath::compare($quantity, '0.00000000', 8) > 0
                ? DecimalMath::quantityValue($allocated, DecimalMath::divide((string) $value, $quantity, 8), 8)
                : '0.00')
            : ($price ? DecimalMath::quantityValue($allocated, (string) $price->price, 8) : null);
        $unrealized = $value === null || ! $basisKnown ? null : DecimalMath::subtract($value, $basis);
        return [
            'id' => $asset->id, 'type' => $asset->type, 'valuation_mode' => $asset->valuation_mode, 'name' => $asset->name, 'provider' => $asset->provider,
            'identifier' => $asset->identifier, 'storage_type' => $asset->storage_type,
            'quantity' => $manualRdpu ? null : $quantity,
            'reserved_quantity' => $manualRdpu ? null : $reserved,
            'allocated_quantity' => $manualRdpu ? null : $allocated,
            'unallocated_quantity' => $manualRdpu ? null : (DecimalMath::compare($free, '0.00000000', 8) < 0 ? '0.00000000' : $free),
            'manual_balance' => $manualBalance, 'manual_reserved' => $manualReserved,
            'manual_allocated' => $manualAllocated,
            'manual_unallocated' => DecimalMath::compare($manualFree, '0.00000000', 8) < 0 ? '0.00000000' : $manualFree,
            'cost_basis' => $basis, 'cost_basis_known' => $basisKnown, 'book_value' => $bookValue,
            'average_cost_per_unit' => $averageCost,
            'latest_price' => $manualRdpu ? (string) $value : ($price ? (string) $price->price : null), 'price_unit' => $manualRdpu ? 'IDR/portfolio' : $price?->unit,
            'price_source' => $price?->source, 'valuation_at' => $price?->captured_at?->toISOString(),
            'estimated_value' => $value, 'allocated_value' => $allocationValue, 'unrealized_profit_loss' => $unrealized,
            'realized_profit_loss' => $basisKnown ? $realized : null,
            'total_profit_loss' => $basisKnown && $unrealized !== null ? DecimalMath::add($unrealized, $realized) : null,
            'valuation_stale' => $price ? $price->captured_at->lt(now()->subDays(30)) : null,
            'allocation_deficit' => DecimalMath::compare($free, '0.00000000', 8) < 0 ? bcmul($free, '-1', 8) : '0.00000000',
            'transactions' => $asset->transactions->sortByDesc('transaction_date')->values(),
            'audit_history' => AssetTransaction::where('asset_id', $asset->id)->whereNotNull('superseded_at')->orderByDesc('transaction_date')->get(),
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
        if ($asset->type === 'rdpu' && in_array($asset->valuation_mode, ['manual', 'value_only'], true)) {
            $asset->manual_current_value = DecimalMath::subtract((string) $asset->manual_current_value, $amount);
            $asset->save();
        }
    }

    private function enableManualRdpuMode(Asset $asset): void
    {
        if ($asset->type !== 'rdpu' || $asset->valuation_mode === 'manual') return;

        $before = $this->snapshot($asset);
        $principalPerUnit = (string) $before['average_cost_per_unit'];
        foreach (GoalAssetAllocation::where('asset_id', $asset->id)->lockForUpdate()->get() as $allocation) {
            $allocation->quantity = DecimalMath::multiply((string) $allocation->quantity, $principalPerUnit, 8);
            $allocation->save();
        }

        $asset->valuation_mode = 'manual';
        $asset->manual_current_value = $before['estimated_value'] ?? $before['book_value'];
        $asset->save();
    }

    private function manualQuantityForValue(Asset $asset, string $value): string
    {
        $snapshot = $this->snapshot($asset);
        $currentValue = (string) ($snapshot['estimated_value'] ?? $snapshot['book_value']);
        $currentQuantity = (string) $snapshot['quantity'];
        if (DecimalMath::compare($value, '0.00') <= 0 || DecimalMath::compare($currentValue, '0.00') <= 0 || DecimalMath::compare($currentQuantity, '0.00000000', 8) <= 0) {
            throw ValidationException::withMessages(['amount' => 'Nilai portofolio RDPU belum cukup untuk mencatat pencairan. Perbarui nilainya terlebih dahulu.']);
        }
        if (DecimalMath::compare($value, $currentValue) > 0) {
            throw ValidationException::withMessages(['amount' => 'Nominal pencairan melebihi nilai portofolio RDPU yang tercatat.']);
        }

        return DecimalMath::divide(DecimalMath::multiply($value, $currentQuantity, 8), $currentValue, 8);
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
