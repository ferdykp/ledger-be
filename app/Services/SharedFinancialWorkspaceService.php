<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Support\DecimalMath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SharedFinancialWorkspaceService
{
    public function list(User $user): array
    {
        $ids = DB::table('shared_financial_workspace_members')->where('user_id', $user->id)->pluck('workspace_id');
        return DB::table('shared_financial_workspaces')->whereIn('id', $ids)->orderBy('name')->get()
            ->map(fn ($workspace) => $this->snapshot($user, (int) $workspace->id, false))->all();
    }

    public function create(User $user, string $name): array
    {
        return DB::transaction(function () use ($user, $name) {
            $workspaceId = DB::table('shared_financial_workspaces')->insertGetId([
                'owner_user_id' => $user->id, 'name' => trim($name), 'currency' => 'IDR', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('shared_financial_workspace_members')->insert([
                'workspace_id' => $workspaceId, 'user_id' => $user->id, 'role' => 'owner', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('shared_financial_workspace_member_cash')->insert([
                'workspace_id' => $workspaceId, 'user_id' => $user->id, 'balance' => '0.00', 'created_at' => now(), 'updated_at' => now(),
            ]);
            return $this->snapshot($user, $workspaceId);
        }, 3);
    }

    public function deleteWorkspace(User $user, int $workspaceId): void
    {
        DB::transaction(function () use ($user, $workspaceId) {
            $this->lockedWorkspace($user, $workspaceId, true);
            $cash = (string) (DB::table('shared_financial_workspace_member_cash')->where('workspace_id', $workspaceId)->sum('balance') ?? '0.00');
            abort_if(DecimalMath::compare($cash, '0.00') > 0, 409, 'Kosongkan kas bersama dengan menarik saldo anggota terlebih dahulu.');
            $hasUnits = DB::table('shared_asset_member_holdings')->join('shared_assets', 'shared_assets.id', '=', 'shared_asset_member_holdings.asset_id')
                ->where('shared_assets.workspace_id', $workspaceId)->where('shared_asset_member_holdings.quantity', '>', 0)->exists();
            abort_if($hasUnits, 409, 'Selesaikan atau jual seluruh kepemilikan aset bersama sebelum menghapus ruang.');
            DB::table('shared_financial_workspaces')->where('id', $workspaceId)->delete();
        }, 3);
    }

    public function updateWorkspace(User $user, int $workspaceId, string $name): array
    {
        $this->lockedWorkspace($user, $workspaceId, true);
        DB::table('shared_financial_workspaces')->where('id', $workspaceId)->update([
            'name' => trim($name), 'updated_at' => now(),
        ]);
        return $this->snapshot($user, $workspaceId);
    }

    public function invite(User $user, int $workspaceId, string $email): array
    {
        $workspace = $this->lockedWorkspace($user, $workspaceId, true);
        $email = mb_strtolower(trim($email));
        $token = Str::random(64);
        $existingMember = DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->value('id');
        if ($existingMember && DB::table('shared_financial_workspace_members')->where('workspace_id', $workspaceId)->where('user_id', $existingMember)->exists()) {
            throw ValidationException::withMessages(['email' => 'Pengguna tersebut sudah menjadi anggota ruang ini.']);
        }
        DB::table('shared_financial_workspace_invitations')->where('workspace_id', $workspaceId)->where('email', $email)->where('status', 'pending')->update(['status' => 'revoked', 'updated_at' => now()]);
        $invitationId = DB::table('shared_financial_workspace_invitations')->insertGetId([
            'workspace_id' => $workspaceId, 'invited_by' => $user->id, 'email' => $email,
            'token_hash' => hash('sha256', $token), 'status' => 'pending', 'expires_at' => now()->addDays(7),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return [
            'id' => $invitationId, 'email' => $email, 'status' => 'pending', 'expires_at' => now()->addDays(7)->toISOString(),
            'invite_token' => $token, 'workspace_name' => $workspace->name,
        ];
    }

    public function revokeInvitation(User $user, int $workspaceId, int $invitationId): void
    {
        $this->lockedWorkspace($user, $workspaceId, true);
        $updated = DB::table('shared_financial_workspace_invitations')->where('workspace_id', $workspaceId)->where('id', $invitationId)->where('status', 'pending')->update(['status' => 'revoked', 'updated_at' => now()]);
        abort_unless($updated, 404, 'Undangan aktif tidak ditemukan.');
    }

    public function pendingInvitations(User $user): array
    {
        return DB::table('shared_financial_workspace_invitations as i')
            ->join('shared_financial_workspaces as w', 'w.id', '=', 'i.workspace_id')
            ->join('users as u', 'u.id', '=', 'i.invited_by')
            ->whereRaw('LOWER(i.email) = ?', [mb_strtolower($user->email)])
            ->where('i.status', 'pending')->where('i.expires_at', '>', now())
            ->select('i.id', 'i.email', 'i.expires_at', 'w.id as workspace_id', 'w.name as workspace_name', 'u.name as invited_by_name')
            ->orderBy('i.created_at')->get()->all();
    }

    public function acceptInvitation(User $user, string $token): array
    {
        return DB::transaction(function () use ($user, $token) {
            $invitation = DB::table('shared_financial_workspace_invitations')->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            return $this->joinInvitation($user, $invitation);
        }, 3);
    }

    public function acceptPendingInvitation(User $user, int $invitationId): array
    {
        return DB::transaction(function () use ($user, $invitationId) {
            $invitation = DB::table('shared_financial_workspace_invitations')->where('id', $invitationId)->lockForUpdate()->first();
            abort_unless($invitation && mb_strtolower($invitation->email) === mb_strtolower($user->email), 404, 'Undangan tidak ditemukan.');
            return $this->joinInvitation($user, $invitation);
        }, 3);
    }

    public function contribute(User $user, int $workspaceId, array $data): array
    {
        return DB::transaction(function () use ($user, $workspaceId, $data) {
            $this->lockedWorkspace($user, $workspaceId);
            $key = $data['idempotency_key'] ?? null;
            if ($key) {
                $prior = DB::table('shared_financial_workspace_cash_entries')->where('workspace_id', $workspaceId)->where('idempotency_key', $key)->first();
                if ($prior) {
                    abort_unless($prior->type === 'contribution' && (int) $prior->member_user_id === (int) $user->id
                        && (int) $prior->source_account_id === (int) $data['source_account_id']
                        && DecimalMath::compare((string) $prior->amount, (string) $data['amount']) === 0
                        && $prior->entry_date === $data['entry_date'], 409, 'Kunci permintaan telah digunakan untuk setoran berbeda.');
                    return $this->snapshot($user, $workspaceId);
                }
            }
            $account = Account::where('user_id', $user->id)->where('is_archived', false)->lockForUpdate()->findOrFail($data['source_account_id']);
            if (DecimalMath::compare((string) $account->balance, (string) $data['amount']) < 0) {
                throw ValidationException::withMessages(['amount' => 'Saldo rekening tidak mencukupi untuk setoran.']);
            }
            $account->balance = DecimalMath::subtract((string) $account->balance, (string) $data['amount']);
            $account->save();
            $personal = Transaction::create([
                'user_id' => $user->id, 'account_id' => $account->id, 'type' => 'expense', 'amount' => $data['amount'],
                'note' => 'Setoran ruang bersama: '.DB::table('shared_financial_workspaces')->where('id', $workspaceId)->value('name'),
                'date' => $data['entry_date'], 'financial_purpose' => 'shared_contribution',
                'idempotency_key' => $key ? 'shared-'.hash('sha256', $workspaceId.'|'.$key) : null,
            ]);
            $this->updateMemberCash($workspaceId, $user->id, (string) $data['amount'], true);
            DB::table('shared_financial_workspace_cash_entries')->insert([
                'workspace_id' => $workspaceId, 'member_user_id' => $user->id, 'source_account_id' => $account->id,
                'personal_transaction_id' => $personal->id, 'type' => 'contribution', 'direction' => 'in', 'amount' => $data['amount'],
                'entry_date' => $data['entry_date'], 'idempotency_key' => $key, 'note' => $data['note'] ?? null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            return $this->snapshot($user, $workspaceId);
        }, 3);
    }

    public function createGoal(User $user, int $workspaceId, array $data): array
    {
        $this->lockedWorkspace($user, $workspaceId);
        DB::table('shared_financial_workspace_goals')->insert([
            'workspace_id' => $workspaceId, 'name' => trim($data['name']), 'target_amount' => $data['target_amount'],
            'target_date' => $data['target_date'] ?? null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $this->snapshot($user, $workspaceId);
    }

    public function deleteGoal(User $user, int $workspaceId, int $goalId): array
    {
        return DB::transaction(function () use ($user, $workspaceId, $goalId) {
            $this->lockedWorkspace($user, $workspaceId, true);
            $goal = DB::table('shared_financial_workspace_goals')->where('workspace_id', $workspaceId)->lockForUpdate()->find($goalId);
            abort_unless($goal, 404, 'Target bersama tidak ditemukan.');
            $allocated = (string) (DB::table('shared_financial_workspace_goal_allocations')->where('workspace_id', $workspaceId)->where('goal_id', $goalId)
                ->selectRaw("COALESCE(SUM(CASE WHEN action = 'allocate' THEN amount ELSE -amount END), 0) as total")->value('total') ?? '0.00');
            abort_if(DecimalMath::compare($allocated, '0.00') !== 0, 409, 'Lepaskan seluruh cadangan kas pada target ini sebelum menghapusnya.');
            DB::table('shared_financial_workspace_goals')->where('id', $goalId)->delete();
            return $this->snapshot($user, $workspaceId);
        }, 3);
    }

    public function updateGoal(User $user, int $workspaceId, int $goalId, array $data): array
    {
        return DB::transaction(function () use ($user, $workspaceId, $goalId, $data) {
            $this->lockedWorkspace($user, $workspaceId, true);
            $goal = DB::table('shared_financial_workspace_goals')->where('workspace_id', $workspaceId)->lockForUpdate()->find($goalId);
            abort_unless($goal, 404, 'Target bersama tidak ditemukan.');
            DB::table('shared_financial_workspace_goals')->where('id', $goalId)->update([
                'name' => trim($data['name']), 'target_amount' => $data['target_amount'],
                'target_date' => $data['target_date'] ?? null, 'updated_at' => now(),
            ]);
            return $this->snapshot($user, $workspaceId);
        }, 3);
    }

    public function allocateGoal(User $user, int $workspaceId, int $goalId, array $data): array
    {
        return DB::transaction(function () use ($user, $workspaceId, $goalId, $data) {
            $this->lockedWorkspace($user, $workspaceId);
            $goal = DB::table('shared_financial_workspace_goals')->where('workspace_id', $workspaceId)->lockForUpdate()->find($goalId);
            abort_unless($goal && $goal->is_active, 404);
            $key = $data['idempotency_key'] ?? null;
            if ($key) {
                $prior = DB::table('shared_financial_workspace_goal_allocations')->where('workspace_id', $workspaceId)->where('idempotency_key', $key)->first();
                if ($prior) {
                    abort_unless((int) $prior->goal_id === $goalId && (int) $prior->member_user_id === (int) $user->id
                        && $prior->action === $data['action'] && DecimalMath::compare((string) $prior->amount, (string) $data['amount']) === 0,
                        409, 'Kunci permintaan sudah digunakan untuk alokasi target yang berbeda.');
                    return $this->snapshot($user, $workspaceId);
                }
            }
            $allocated = (string) (DB::table('shared_financial_workspace_goal_allocations')->where('workspace_id', $workspaceId)->where('goal_id', $goalId)->where('member_user_id', $user->id)
                ->selectRaw("COALESCE(SUM(CASE WHEN action = 'allocate' THEN amount ELSE -amount END), 0) as total")->value('total') ?? '0.00');
            if ($data['action'] === 'allocate') {
                $memberCash = DB::table('shared_financial_workspace_member_cash')->where('workspace_id', $workspaceId)->where('user_id', $user->id)->lockForUpdate()->value('balance') ?? '0.00';
                $allReserved = $this->reservedCashForMember($workspaceId, $user->id);
                $available = DecimalMath::subtract((string) $memberCash, $allReserved);
                if (DecimalMath::compare((string) $data['amount'], $available) > 0) throw ValidationException::withMessages(['amount' => 'Nominal melebihi kas anggota yang belum dicadangkan.']);
            } elseif (DecimalMath::compare((string) $data['amount'], $allocated) > 0) {
                throw ValidationException::withMessages(['amount' => 'Nominal yang dilepas melebihi alokasi Anda pada target ini.']);
            }
            DB::table('shared_financial_workspace_goal_allocations')->insert([
                'workspace_id' => $workspaceId, 'goal_id' => $goalId, 'member_user_id' => $user->id,
                'action' => $data['action'], 'amount' => $data['amount'], 'allocation_date' => $data['allocation_date'],
                'idempotency_key' => $key, 'note' => $data['note'] ?? null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            return $this->snapshot($user, $workspaceId);
        }, 3);
    }

    public function createAsset(User $user, int $workspaceId, array $data): array
    {
        $this->lockedWorkspace($user, $workspaceId);
        $id = DB::table('shared_assets')->insertGetId([
            'workspace_id' => $workspaceId, 'type' => 'gold', 'name' => trim($data['name']), 'provider' => $data['provider'] ?? null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $this->snapshot($user, $workspaceId);
    }

    public function deleteAsset(User $user, int $workspaceId, int $assetId): array
    {
        return DB::transaction(function () use ($user, $workspaceId, $assetId) {
            $this->lockedWorkspace($user, $workspaceId, true);
            $asset = DB::table('shared_assets')->where('workspace_id', $workspaceId)->lockForUpdate()->find($assetId);
            abort_unless($asset, 404, 'Aset bersama tidak ditemukan.');
            $hasUnits = DB::table('shared_asset_member_holdings')->where('asset_id', $assetId)->where('quantity', '>', 0)->exists();
            abort_if($hasUnits, 409, 'Aset masih memiliki kepemilikan. Jual atau selesaikan semua gram sebelum menghapus aset.');
            DB::table('shared_assets')->where('id', $assetId)->delete();
            return $this->snapshot($user, $workspaceId);
        }, 3);
    }

    public function updateAsset(User $user, int $workspaceId, int $assetId, array $data): array
    {
        return DB::transaction(function () use ($user, $workspaceId, $assetId, $data) {
            $this->lockedWorkspace($user, $workspaceId, true);
            $asset = DB::table('shared_assets')->where('workspace_id', $workspaceId)->lockForUpdate()->find($assetId);
            abort_unless($asset, 404, 'Aset bersama tidak ditemukan.');
            DB::table('shared_assets')->where('id', $assetId)->update([
                'name' => trim($data['name']), 'provider' => $data['provider'] ?? null, 'updated_at' => now(),
            ]);
            return $this->snapshot($user, $workspaceId);
        }, 3);
    }

    public function transactAsset(User $user, int $workspaceId, int $assetId, array $data): array
    {
        return DB::transaction(function () use ($user, $workspaceId, $assetId, $data) {
            $this->lockedWorkspace($user, $workspaceId);
            $asset = DB::table('shared_assets')->where('workspace_id', $workspaceId)->lockForUpdate()->find($assetId);
            abort_unless($asset, 404);
            $key = $data['idempotency_key'] ?? null;
            if ($key) {
                $prior = DB::table('shared_asset_transactions')->where('workspace_id', $workspaceId)->where('idempotency_key', $key)->first();
                if ($prior) {
                    $same = $prior->action === $data['action']
                        && (int) $prior->asset_id === $assetId
                        && DecimalMath::compare((string) $prior->amount, (string) ($data['amount'] ?? '0')) === 0
                        && DecimalMath::compare((string) $prior->quantity, (string) ($data['quantity'] ?? '0'), 8) === 0
                        && DecimalMath::compare((string) ($prior->unit_price ?? '0'), (string) ($data['unit_price'] ?? '0'), 8) === 0
                        && DecimalMath::compare((string) $prior->fees, (string) ($data['fees'] ?? '0'), 2) === 0
                        && $prior->transaction_date === $data['transaction_date'];
                    abort_unless($same, 409, 'Kunci permintaan sudah digunakan untuk transaksi aset bersama yang berbeda.');
                    return $this->snapshot($user, $workspaceId);
                }
            }

            $action = $data['action'];
            $amount = (string) ($data['amount'] ?? '0.00');
            $quantity = (string) ($data['quantity'] ?? '0.00000000');
            $fees = (string) ($data['fees'] ?? '0.00');
            $unitPrice = $data['unit_price'] ?? null;
            if ($action === 'purchase') {
                $spent = DecimalMath::add($amount, $fees);
                $members = DB::table('shared_financial_workspace_member_cash')->where('workspace_id', $workspaceId)->orderBy('user_id')->lockForUpdate()->get();
                $available = '0.00';
                foreach ($members as $member) {
                    $member->available_balance = DecimalMath::subtract((string) $member->balance, $this->reservedCashForMember($workspaceId, (int) $member->user_id));
                    $available = DecimalMath::add($available, (string) $member->available_balance);
                }
                $members = $members->filter(fn ($member) => DecimalMath::compare((string) $member->available_balance, '0.00') > 0)->values();
                if (DecimalMath::compare($available, $spent) < 0) throw ValidationException::withMessages(['amount' => 'Kas bersama tidak mencukupi untuk pembelian dan biaya.']);
                $transactionId = $this->createSharedTransaction($user, $workspaceId, $assetId, $data, $amount, $quantity, $fees, $unitPrice);
                $remainingCash = $spent;
                $remainingQuantity = $quantity;
                $remainingSpend = $spent;
                foreach ($members as $index => $member) {
                    $last = $index === $members->count() - 1;
                    $cashShare = $last ? $remainingCash : DecimalMath::round(DecimalMath::divide(DecimalMath::multiply($spent, (string) $member->available_balance, 8), $available, 8), 2);
                    if (DecimalMath::compare($cashShare, (string) $member->available_balance) > 0) $cashShare = (string) $member->available_balance;
                    if (DecimalMath::compare($cashShare, $remainingCash) > 0) $cashShare = $remainingCash;
                    if (DecimalMath::compare($cashShare, '0.00') <= 0) continue;
                    $qtyShare = $cashShare === $remainingCash ? $remainingQuantity : DecimalMath::round(DecimalMath::divide(DecimalMath::multiply($quantity, $cashShare, 16), $spent, 16), 8);
                    if (DecimalMath::compare($qtyShare, $remainingQuantity, 8) > 0) $qtyShare = $remainingQuantity;
                    DB::table('shared_asset_transaction_splits')->insert([
                        'shared_asset_transaction_id' => $transactionId, 'user_id' => $member->user_id, 'cash_amount' => $cashShare,
                        'quantity' => $qtyShare, 'cost_basis' => $cashShare, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $this->updateMemberCash($workspaceId, (int) $member->user_id, $cashShare, false);
                    $this->updateHolding($assetId, (int) $member->user_id, $qtyShare, $cashShare, true);
                    $remainingCash = DecimalMath::subtract($remainingCash, $cashShare);
                    $remainingQuantity = DecimalMath::subtract($remainingQuantity, $qtyShare, 8);
                }
                if (DecimalMath::compare($remainingCash, '0.00') !== 0 || DecimalMath::compare($remainingQuantity, '0.00000000', 8) !== 0) {
                    throw ValidationException::withMessages(['amount' => 'Pembagian saldo atau unit tidak dapat dihitung dengan presisi.']);
                }
                DB::table('shared_financial_workspace_cash_entries')->insert([
                    'workspace_id' => $workspaceId, 'type' => 'asset_purchase', 'direction' => 'out', 'amount' => $spent,
                    'entry_date' => $data['transaction_date'], 'idempotency_key' => $key, 'note' => 'Pembelian '.$asset->name,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } elseif ($action === 'sale') {
                $holders = DB::table('shared_asset_member_holdings')->where('asset_id', $assetId)->where('quantity', '>', 0)->orderBy('user_id')->lockForUpdate()->get();
                $totalQuantity = '0.00000000';
                foreach ($holders as $holder) $totalQuantity = DecimalMath::add($totalQuantity, (string) $holder->quantity, 8);
                if (DecimalMath::compare($quantity, $totalQuantity, 8) > 0) throw ValidationException::withMessages(['quantity' => 'Jumlah gram yang dijual melebihi kepemilikan bersama.']);
                if (DecimalMath::compare($amount, $fees) < 0) throw ValidationException::withMessages(['fees' => 'Biaya tidak boleh melebihi hasil penjualan.']);
                $netProceeds = DecimalMath::subtract($amount, $fees);
                $transactionId = $this->createSharedTransaction($user, $workspaceId, $assetId, $data, $amount, $quantity, $fees, $unitPrice);
                $remainingQty = $quantity;
                $remainingProceeds = $netProceeds;
                $lastHolder = $holders->last();
                foreach ($holders as $holder) {
                    $qtyShare = (int) $holder->user_id === (int) $lastHolder->user_id
                        ? $remainingQty
                        : DecimalMath::round(DecimalMath::divide(DecimalMath::multiply($quantity, (string) $holder->quantity, 16), $totalQuantity, 16), 8);
                    if (DecimalMath::compare($qtyShare, (string) $holder->quantity, 8) > 0) $qtyShare = (string) $holder->quantity;
                    if (DecimalMath::compare($qtyShare, $remainingQty, 8) > 0) $qtyShare = $remainingQty;
                    $basis = DecimalMath::compare((string) $holder->quantity, '0.00000000', 8) > 0
                        ? DecimalMath::round(DecimalMath::divide(DecimalMath::multiply((string) $holder->cost_basis, $qtyShare, 12), (string) $holder->quantity, 12), 2)
                        : '0.00';
                    $proceeds = (int) $holder->user_id === (int) $lastHolder->user_id
                        ? $remainingProceeds
                        : DecimalMath::round(DecimalMath::divide(DecimalMath::multiply($netProceeds, $qtyShare, 12), $quantity, 12), 2);
                    if (DecimalMath::compare($proceeds, $remainingProceeds) > 0) $proceeds = $remainingProceeds;
                    DB::table('shared_asset_transaction_splits')->insert([
                        'shared_asset_transaction_id' => $transactionId, 'user_id' => $holder->user_id, 'cash_amount' => $proceeds,
                        'quantity' => DecimalMath::multiply($qtyShare, '-1', 8), 'cost_basis' => DecimalMath::multiply($basis, '-1', 2),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $this->updateHolding($assetId, (int) $holder->user_id, DecimalMath::multiply($qtyShare, '-1', 8), DecimalMath::multiply($basis, '-1', 2), true);
                    $this->updateMemberCash($workspaceId, (int) $holder->user_id, $proceeds, true);
                    $remainingQty = DecimalMath::subtract($remainingQty, $qtyShare, 8);
                    $remainingProceeds = DecimalMath::subtract($remainingProceeds, $proceeds);
                }
                DB::table('shared_financial_workspace_cash_entries')->insert([
                    'workspace_id' => $workspaceId, 'type' => 'asset_sale', 'direction' => 'in', 'amount' => $netProceeds,
                    'entry_date' => $data['transaction_date'], 'idempotency_key' => $key, 'note' => 'Penjualan '.$asset->name,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } else {
                $unitPrice = (string) $data['unit_price'];
                DB::table('shared_assets')->where('id', $assetId)->update(['latest_price_per_gram' => $unitPrice, 'price_captured_at' => $data['transaction_date'], 'updated_at' => now()]);
                $this->createSharedTransaction($user, $workspaceId, $assetId, $data, '0.00', '0.00000000', '0.00', $unitPrice);
            }
            return $this->snapshot($user, $workspaceId);
        }, 3);
    }

    public function withdraw(User $user, int $workspaceId, array $data): array
    {
        return DB::transaction(function () use ($user, $workspaceId, $data) {
            $this->lockedWorkspace($user, $workspaceId);
            $key = $data['idempotency_key'] ?? null;
            if ($key) {
                $prior = DB::table('shared_financial_workspace_cash_entries')->where('workspace_id', $workspaceId)->where('idempotency_key', $key)->first();
                if ($prior) {
                    abort_unless($prior->type === 'withdrawal' && (int) $prior->member_user_id === (int) $user->id
                        && (int) $prior->source_account_id === (int) $data['destination_account_id']
                        && DecimalMath::compare((string) $prior->amount, (string) $data['amount']) === 0
                        && $prior->entry_date === $data['entry_date'], 409, 'Kunci permintaan telah digunakan untuk penarikan berbeda.');
                    return $this->snapshot($user, $workspaceId);
                }
            }
            $cash = DB::table('shared_financial_workspace_member_cash')->where('workspace_id', $workspaceId)->where('user_id', $user->id)->lockForUpdate()->first();
            $availableCash = $cash ? DecimalMath::subtract((string) $cash->balance, $this->reservedCashForMember($workspaceId, $user->id)) : '0.00';
            if (! $cash || DecimalMath::compare($availableCash, (string) $data['amount']) < 0) throw ValidationException::withMessages(['amount' => 'Saldo kas bebas Anda tidak mencukupi. Lepaskan alokasi target lebih dulu jika diperlukan.']);
            $account = Account::where('user_id', $user->id)->where('is_archived', false)->lockForUpdate()->findOrFail($data['destination_account_id']);
            $account->balance = DecimalMath::add((string) $account->balance, (string) $data['amount']);
            $account->save();
            $personal = Transaction::create([
                'user_id' => $user->id, 'account_id' => $account->id, 'type' => 'income', 'amount' => $data['amount'],
                'note' => 'Penarikan kas ruang bersama', 'date' => $data['entry_date'], 'financial_purpose' => 'shared_withdrawal',
                'idempotency_key' => $key ? 'shared-'.hash('sha256', $workspaceId.'|'.$key) : null,
            ]);
            $this->updateMemberCash($workspaceId, $user->id, (string) $data['amount'], false);
            DB::table('shared_financial_workspace_cash_entries')->insert([
                'workspace_id' => $workspaceId, 'member_user_id' => $user->id, 'source_account_id' => $account->id,
                'personal_transaction_id' => $personal->id, 'type' => 'withdrawal', 'direction' => 'out', 'amount' => $data['amount'],
                'entry_date' => $data['entry_date'], 'idempotency_key' => $key, 'note' => $data['note'] ?? null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            return $this->snapshot($user, $workspaceId);
        }, 3);
    }

    public function removeMember(User $user, int $workspaceId, int $memberId): void
    {
        DB::transaction(function () use ($user, $workspaceId, $memberId) {
            $this->lockedWorkspace($user, $workspaceId, true);
            abort_if($memberId === $user->id, 422, 'Pemilik tidak dapat mengeluarkan dirinya sendiri.');
            $membership = DB::table('shared_financial_workspace_members')->where('workspace_id', $workspaceId)->where('user_id', $memberId)->first();
            abort_unless($membership, 404);
            $cash = DB::table('shared_financial_workspace_member_cash')->where('workspace_id', $workspaceId)->where('user_id', $memberId)->value('balance') ?? '0.00';
            $units = DB::table('shared_asset_member_holdings')->join('shared_assets', 'shared_assets.id', '=', 'shared_asset_member_holdings.asset_id')->where('shared_assets.workspace_id', $workspaceId)->where('shared_asset_member_holdings.user_id', $memberId)->where('shared_asset_member_holdings.quantity', '>', 0)->exists();
            abort_if(DecimalMath::compare((string) $cash, '0.00') > 0 || $units, 409, 'Anggota masih memiliki saldo atau unit aset. Pindahkan atau selesaikan porsinya terlebih dahulu.');
            DB::table('shared_financial_workspace_members')->where('id', $membership->id)->delete();
            DB::table('shared_financial_workspace_member_cash')->where('workspace_id', $workspaceId)->where('user_id', $memberId)->delete();
        }, 3);
    }

    public function snapshot(User $user, int $workspaceId, bool $includeHistory = true): array
    {
        abort_unless(DB::table('shared_financial_workspace_members')->where('workspace_id', $workspaceId)->where('user_id', $user->id)->exists(), 404);
        $workspace = DB::table('shared_financial_workspaces')->find($workspaceId);
        abort_unless($workspace, 404);
        $members = DB::table('shared_financial_workspace_members as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->leftJoin('shared_financial_workspace_member_cash as c', fn ($join) => $join->on('c.workspace_id', '=', 'm.workspace_id')->on('c.user_id', '=', 'm.user_id'))
            ->where('m.workspace_id', $workspaceId)->select('u.id', 'u.name', 'm.role', 'm.joined_at', DB::raw("COALESCE(c.balance, 0) as cash_balance"))->get()
            ->map(function ($member) use ($workspaceId) {
                $member->cash_reserved = $this->reservedCashForMember($workspaceId, (int) $member->id);
                $member->cash_available = DecimalMath::subtract((string) $member->cash_balance, (string) $member->cash_reserved);
                return $member;
            });
        $assets = DB::table('shared_assets')->where('workspace_id', $workspaceId)->orderBy('id')->get()->map(function ($asset) use ($includeHistory) {
            $holdings = DB::table('shared_asset_member_holdings as h')->join('users as u', 'u.id', '=', 'h.user_id')->where('h.asset_id', $asset->id)
                ->select('u.id as user_id', 'u.name', 'h.quantity', 'h.cost_basis')->orderBy('u.id')->get();
            $quantity = '0.00000000'; $basis = '0.00';
            foreach ($holdings as $holding) { $quantity = DecimalMath::add($quantity, (string) $holding->quantity, 8); $basis = DecimalMath::add($basis, (string) $holding->cost_basis); }
            $value = $asset->latest_price_per_gram === null ? null : DecimalMath::quantityValue($quantity, (string) $asset->latest_price_per_gram);
            $holdings = $holdings->map(function ($holding) use ($asset) {
                $memberValue = $asset->latest_price_per_gram === null ? null : DecimalMath::quantityValue((string) $holding->quantity, (string) $asset->latest_price_per_gram);
                $holding->estimated_value = $memberValue;
                $holding->profit_loss = $memberValue === null ? null : DecimalMath::subtract($memberValue, (string) $holding->cost_basis);
                return $holding;
            });
            $transactions = $includeHistory ? DB::table('shared_asset_transactions')->where('asset_id', $asset->id)->orderByDesc('transaction_date')->orderByDesc('id')->get() : collect();
            return [
                'id' => $asset->id, 'type' => $asset->type, 'name' => $asset->name, 'provider' => $asset->provider,
                'quantity' => $quantity, 'cost_basis' => $basis, 'latest_price_per_gram' => $asset->latest_price_per_gram,
                'price_captured_at' => $asset->price_captured_at, 'estimated_value' => $value,
                'profit_loss' => $value === null ? null : DecimalMath::subtract($value, $basis),
                'holdings' => $holdings, 'transactions' => $transactions,
            ];
        })->all();
        $cashBalance = '0.00'; foreach ($members as $member) $cashBalance = DecimalMath::add($cashBalance, (string) $member->cash_balance);
        $entries = $includeHistory ? DB::table('shared_financial_workspace_cash_entries as e')->leftJoin('users as u', 'u.id', '=', 'e.member_user_id')->where('e.workspace_id', $workspaceId)->orderByDesc('e.entry_date')->orderByDesc('e.id')->limit(100)->select('e.*', 'u.name as member_name')->get() : [];
        $goals = DB::table('shared_financial_workspace_goals')->where('workspace_id', $workspaceId)->where('is_active', true)->orderBy('target_date')->orderBy('id')->get()->map(function ($goal) use ($workspaceId) {
            $allocations = DB::table('shared_financial_workspace_goal_allocations as a')->join('users as u', 'u.id', '=', 'a.member_user_id')
                ->where('a.workspace_id', $workspaceId)->where('a.goal_id', $goal->id)->select('a.member_user_id as user_id', 'u.name', DB::raw("SUM(CASE WHEN a.action = 'allocate' THEN a.amount ELSE -a.amount END) as amount"))->groupBy('a.member_user_id', 'u.name')->get();
            $funded = '0.00'; foreach ($allocations as $allocation) $funded = DecimalMath::add($funded, (string) $allocation->amount);
            return [
                'id' => $goal->id, 'name' => $goal->name, 'target_amount' => $goal->target_amount, 'target_date' => $goal->target_date,
                'funded_amount' => $funded, 'remaining_amount' => DecimalMath::compare($goal->target_amount, $funded) > 0 ? DecimalMath::subtract($goal->target_amount, $funded) : '0.00',
                'progress_percent' => DecimalMath::compare($goal->target_amount, '0.00') > 0 ? min(100, (int) DecimalMath::divide(DecimalMath::multiply($funded, '100', 4), $goal->target_amount, 2)) : 0,
                'allocations' => $allocations,
            ];
        })->all();
        return [
            'id' => $workspace->id, 'name' => $workspace->name, 'owner_user_id' => $workspace->owner_user_id,
            'is_owner' => (int) $workspace->owner_user_id === (int) $user->id, 'current_user_id' => $user->id, 'cash_balance' => $cashBalance,
            'members' => $members, 'assets' => $assets, 'goals' => $goals, 'cash_entries' => $entries,
            'pending_invitations' => (int) $workspace->owner_user_id === (int) $user->id
                ? DB::table('shared_financial_workspace_invitations')->where('workspace_id', $workspaceId)->where('status', 'pending')->where('expires_at', '>', now())->select('id', 'email', 'expires_at')->orderBy('created_at')->get()
                : [],
            'can_invite' => (int) $workspace->owner_user_id === (int) $user->id,
        ];
    }

    private function lockedWorkspace(User $user, int $workspaceId, bool $ownerOnly = false): object
    {
        $workspace = DB::table('shared_financial_workspaces')->whereExists(function ($query) use ($user, $workspaceId) {
            $query->selectRaw('1')->from('shared_financial_workspace_members as m')->whereColumn('m.workspace_id', 'shared_financial_workspaces.id')->where('m.user_id', $user->id)->where('m.workspace_id', $workspaceId);
        })->lockForUpdate()->find($workspaceId);
        abort_unless($workspace, 404);
        abort_if($ownerOnly && (int) $workspace->owner_user_id !== (int) $user->id, 403);
        return $workspace;
    }

    private function joinInvitation(User $user, ?object $invitation): array
    {
        abort_unless($invitation && $invitation->status === 'pending' && $invitation->expires_at > now()->toDateTimeString(), 404, 'Undangan tidak valid atau sudah kedaluwarsa.');
        abort_unless(mb_strtolower($invitation->email) === mb_strtolower($user->email), 403, 'Undangan ini ditujukan ke email yang berbeda.');
        DB::table('shared_financial_workspace_members')->insertOrIgnore([
            'workspace_id' => $invitation->workspace_id, 'user_id' => $user->id, 'role' => 'member', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('shared_financial_workspace_member_cash')->insertOrIgnore([
            'workspace_id' => $invitation->workspace_id, 'user_id' => $user->id, 'balance' => '0.00', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('shared_financial_workspace_invitations')->where('id', $invitation->id)->update(['status' => 'accepted', 'accepted_at' => now(), 'updated_at' => now()]);
        return $this->snapshot($user, (int) $invitation->workspace_id);
    }

    private function updateMemberCash(int $workspaceId, int $userId, string $amount, bool $credit): void
    {
        $row = DB::table('shared_financial_workspace_member_cash')->where('workspace_id', $workspaceId)->where('user_id', $userId)->lockForUpdate()->first();
        if (! $row) throw ValidationException::withMessages(['workspace' => 'Keanggotaan kas ruang bersama tidak ditemukan.']);
        $next = $credit ? DecimalMath::add((string) $row->balance, $amount) : DecimalMath::subtract((string) $row->balance, $amount);
        if (DecimalMath::compare($next, '0.00') < 0) throw ValidationException::withMessages(['amount' => 'Saldo kas anggota tidak mencukupi.']);
        DB::table('shared_financial_workspace_member_cash')->where('id', $row->id)->update(['balance' => $next, 'updated_at' => now()]);
    }

    private function reservedCashForMember(int $workspaceId, int $userId): string
    {
        return (string) (DB::table('shared_financial_workspace_goal_allocations')->where('workspace_id', $workspaceId)->where('member_user_id', $userId)
            ->selectRaw("COALESCE(SUM(CASE WHEN action = 'allocate' THEN amount ELSE -amount END), 0) as total")->value('total') ?? '0.00');
    }

    private function updateHolding(int $assetId, int $userId, string $quantityDelta, string $basisDelta, bool $create): void
    {
        $holding = DB::table('shared_asset_member_holdings')->where('asset_id', $assetId)->where('user_id', $userId)->lockForUpdate()->first();
        if (! $holding && ! $create) throw ValidationException::withMessages(['quantity' => 'Kepemilikan anggota tidak ditemukan.']);
        $quantity = DecimalMath::add((string) ($holding->quantity ?? '0.00000000'), $quantityDelta, 8);
        $basis = DecimalMath::add((string) ($holding->cost_basis ?? '0.00'), $basisDelta);
        if (DecimalMath::compare($quantity, '0.00000000', 8) < 0 || DecimalMath::compare($basis, '0.00') < 0) throw ValidationException::withMessages(['quantity' => 'Transaksi melebihi porsi kepemilikan anggota.']);
        DB::table('shared_asset_member_holdings')->updateOrInsert(
            ['asset_id' => $assetId, 'user_id' => $userId],
            ['quantity' => $quantity, 'cost_basis' => $basis, 'created_at' => $holding->created_at ?? now(), 'updated_at' => now()],
        );
    }

    private function createSharedTransaction(User $user, int $workspaceId, int $assetId, array $data, string $amount, string $quantity, string $fees, ?string $unitPrice): int
    {
        return DB::table('shared_asset_transactions')->insertGetId([
            'workspace_id' => $workspaceId, 'asset_id' => $assetId, 'recorded_by' => $user->id, 'action' => $data['action'],
            'amount' => $amount, 'quantity' => $quantity, 'unit_price' => $unitPrice ?? (DecimalMath::compare($quantity, '0.00000000', 8) > 0 ? DecimalMath::divide($amount, $quantity, 8) : null),
            'fees' => $fees, 'transaction_date' => $data['transaction_date'], 'idempotency_key' => $data['idempotency_key'] ?? null,
            'note' => $data['note'] ?? null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
