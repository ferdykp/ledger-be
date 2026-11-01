<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Goal;
use App\Models\GoalAssetAllocation;
use App\Models\GoalCashAllocation;
use App\Support\DecimalMath;
use Carbon\Carbon;

class GoalFinancialSummaryService
{
    public function __construct(private AssetService $assets) {}

    public function forGoal(Goal $goal): array
    {
        $goal->loadMissing(['items.obligations.payments', 'cashAllocations.account']);
        $budget = '0.00';
        $paid = '0.00';
        foreach ($goal->items as $item) {
            $budget = DecimalMath::add($budget, $item->estimated_amount);
            foreach ($item->obligations as $obligation) $paid = DecimalMath::add($paid, $obligation->paid_amount);
        }
        if (DecimalMath::compare($budget, '0.00') === 0) $budget = (string) $goal->target_amount;

        $cashRows = GoalCashAllocation::where('goal_id', $goal->id)->with('account')->get();
        $cashByAccount = [];
        foreach ($cashRows as $row) {
            $delta = in_array($row->action, ['allocate'], true) ? (string) $row->amount : bcmul((string) $row->amount, '-1', 2);
            $cashByAccount[$row->account_id] ??= ['account_id' => $row->account_id, 'account_name' => $row->account?->name, 'amount' => '0.00'];
            $cashByAccount[$row->account_id]['amount'] = DecimalMath::add($cashByAccount[$row->account_id]['amount'], $delta);
        }
        $cash = '0.00';
        foreach ($cashByAccount as $accountId => &$entry) {
            $goalNominal = DecimalMath::compare($entry['amount'], '0.00') < 0 ? '0.00' : $entry['amount'];
            $account = $cashRows->firstWhere('account_id', $accountId)?->account;
            $allRows = GoalCashAllocation::where('user_id', $goal->user_id)->where('account_id', $accountId)->get();
            $allAllocated = '0.00';
            foreach ($allRows as $row) {
                $delta = $row->action === 'allocate' ? (string) $row->amount : bcmul((string) $row->amount, '-1', 2);
                $allAllocated = DecimalMath::add($allAllocated, $delta);
            }
            $backing = $account ? (string) $account->balance : '0.00';
            if (DecimalMath::compare($backing, '0.00') < 0) $backing = '0.00';
            $effective = DecimalMath::compare($allAllocated, $backing) > 0 && DecimalMath::compare($allAllocated, '0.00') > 0
                ? DecimalMath::multiply($goalNominal, DecimalMath::divide($backing, $allAllocated, 8), 2)
                : $goalNominal;
            $entry['amount'] = $effective;
            $entry['nominal_amount'] = $goalNominal;
            $entry['account_balance'] = $account ? (string) $account->balance : '0.00';
            $entry['allocation_deficit'] = DecimalMath::compare($allAllocated, $backing) > 0 ? DecimalMath::subtract($allAllocated, $backing) : '0.00';
            $cash = DecimalMath::add($cash, $effective);
        }
        unset($entry);

        $assetRows = GoalAssetAllocation::where('goal_id', $goal->id)->get();
        $assetSummary = [];
        $assetValue = '0.00';
        foreach ($assetRows->groupBy('asset_id') as $assetId => $rows) {
            $asset = Asset::where('user_id', $goal->user_id)->with('transactions', 'prices')->find($assetId);
            if (! $asset) continue;
            $quantity = '0.00000000';
            foreach ($rows as $row) $quantity = $row->action === 'allocate' ? DecimalMath::add($quantity, (string) $row->quantity, 8) : DecimalMath::subtract($quantity, (string) $row->quantity, 8);
            $snapshot = $this->assets->snapshot($asset);
            $allocatedValue = $snapshot['latest_price'] ? DecimalMath::quantityValue($quantity, $snapshot['latest_price'], 8) : null;
            if ($allocatedValue !== null) $assetValue = DecimalMath::add($assetValue, $allocatedValue);
            $assetSummary[] = ['asset_id' => $asset->id, 'name' => $asset->name, 'type' => $asset->type, 'quantity' => $quantity, 'value' => $allocatedValue, 'price_timestamp' => $snapshot['valuation_at']];
        }

        $coverage = DecimalMath::add(DecimalMath::add($paid, $cash), $assetValue);
        $gap = DecimalMath::subtract($budget, $coverage);
        if (DecimalMath::compare($gap, '0.00') < 0) $gap = '0.00';
        $days = $goal->target_date ? max(0, Carbon::today()->diffInDays($goal->target_date, false)) : null;
        $months = $days === null ? null : ($days === 0 ? 0 : (int) ceil($days / 30));
        $monthly = null;
        if ($months > 0 && DecimalMath::compare($gap, '0.00') > 0) {
            $monthly = DecimalMath::divide($gap, (string) $months, 2);
            if (DecimalMath::compare(DecimalMath::multiply($monthly, (string) $months), $gap) < 0) $monthly = DecimalMath::add($monthly, '0.01');
        }
        $upcoming = collect();
        foreach ($goal->items as $item) {
            foreach ($item->obligations as $obligation) {
                if ($obligation->due_date && DecimalMath::compare($obligation->remaining_amount, '0.00') > 0) {
                    $upcoming->push(['id' => $obligation->id, 'item' => $item->name, 'name' => $obligation->name, 'payee' => $obligation->payee, 'amount' => (string) $obligation->amount, 'remaining_amount' => $obligation->remaining_amount, 'due_date' => $obligation->due_date->format('Y-m-d'), 'overdue' => $obligation->due_date->lt(Carbon::today())]);
                }
            }
        }
        $upcoming = $upcoming->sortBy('due_date')->values();
        $cashForObligations = $cash;
        $upcoming = $upcoming->map(function (array $obligation) use (&$cashForObligations) {
            $cashAssigned = DecimalMath::compare($cashForObligations, $obligation['remaining_amount']) >= 0 ? $obligation['remaining_amount'] : $cashForObligations;
            $shortfall = DecimalMath::subtract($obligation['remaining_amount'], $cashAssigned);
            if (DecimalMath::compare($shortfall, '0.00') < 0) $shortfall = '0.00';
            $obligation['cash_available'] = $cashAssigned;
            $obligation['cash_shortfall'] = $shortfall;
            $obligation['payment_ready'] = DecimalMath::compare($shortfall, '0.00') === 0;
            $cashForObligations = DecimalMath::subtract($cashForObligations, $cashAssigned);
            return $obligation;
        });
        $nextObligation = $upcoming->first();

        return [
            'goal_id' => $goal->id, 'budget_total' => $budget, 'savings_journal' => (string) $goal->current_amount,
            'paid_expenses' => $paid, 'allocated_cash' => $cash, 'allocated_cash_by_account' => array_values($cashByAccount),
            'allocated_assets_value' => $assetValue, 'allocated_assets' => $assetSummary,
            'funding_coverage' => $coverage, 'funding_coverage_percent' => DecimalMath::compare($budget, '0.00') > 0 ? min(100, (float) bcdiv(bcmul($coverage, '1000', 0), $budget, 0) / 10) : 0,
            'funding_gap' => $gap, 'liquid_cash' => $cash, 'monthly_funding_required' => $monthly,
            'payment_readiness' => $nextObligation ? ['due_date' => $nextObligation['due_date'], 'name' => $nextObligation['name'], 'amount_due' => $nextObligation['remaining_amount'], 'cash_available' => $nextObligation['cash_available'], 'cash_shortfall' => $nextObligation['cash_shortfall'], 'ready' => $nextObligation['payment_ready']] : null,
            'months_remaining' => $months, 'upcoming_obligations' => $upcoming,
        ];
    }
}
