<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialEcosystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_goal_budget_cash_allocation_and_payments_reconcile_without_changing_allocation_twice(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $account = $user->accounts()->create(['name' => 'BCA', 'type' => 'bank', 'balance' => '1000.00']);
        $goalId = $this->postJson('/api/goals', ['name' => 'Lamaran', 'target_amount' => '1.00'])->assertCreated()->json('data.id');
        $itemId = $this->postJson("/api/goals/$goalId/items", ['name' => 'Vendor', 'quantity' => '2', 'unit_price' => '50.00'])->assertCreated()->json('data.id');
        $obligationId = $this->postJson("/api/goals/$goalId/items/$itemId/obligations", ['name' => 'DP vendor', 'amount' => '80.00', 'due_date' => '2026-12-01'])->assertCreated()->json('data.id');
        $this->getJson("/api/goals/$goalId")->assertOk()->assertJsonPath('data.budget_total', '100.00');

        $this->postJson("/api/goals/$goalId/cash-allocations", ['account_id' => $account->id, 'amount' => '100.00', 'action' => 'allocate'])->assertCreated();
        $this->postJson("/api/goals/$goalId/cash-allocations", ['account_id' => $account->id, 'amount' => '900.01', 'action' => 'allocate'])->assertUnprocessable();
        $this->assertSame('1000.00', $account->fresh()->balance);

        $this->postJson('/api/transactions', ['type' => 'expense', 'account_id' => $account->id, 'amount' => '30.00', 'date' => '2026-11-02', 'goal_obligation_id' => $obligationId])->assertCreated();
        $this->assertSame('970.00', $account->fresh()->balance);
        $this->assertSame('30.00', $user->transactions()->where('goal_obligation_id', $obligationId)->first()->amount);

        $summary = $this->getJson("/api/goals/$goalId/funding")->assertOk()->json('data');
        $this->assertSame('30.00', $summary['paid_expenses']);
        $this->assertSame('70.00', $summary['allocated_cash']);
        $this->assertSame('100.00', $summary['funding_coverage']);
        $this->assertSame('0.00', $summary['funding_gap']);
    }

    public function test_gold_trade_cash_valuation_allocation_and_realized_profit_are_decimal_safe(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $account = $user->accounts()->create(['name' => 'Cash', 'type' => 'cash', 'balance' => '10000.00']);
        $assetId = $this->postJson('/api/assets', ['type' => 'gold', 'name' => 'Emas 24K', 'storage_type' => 'physical'])->assertCreated()->json('data.id');

        $this->postJson("/api/assets/$assetId/transactions", ['type' => 'purchase', 'cash_account_id' => $account->id, 'amount' => '1000.00', 'quantity' => '0.50000000', 'unit_price' => '2000.00000000', 'fees' => '10.00', 'transaction_date' => '2026-11-02'])->assertCreated();
        $this->assertSame('8990.00', $account->fresh()->balance);
        $this->postJson("/api/assets/$assetId/prices", ['price' => '2400.00000000', 'captured_at' => '2026-11-02', 'source' => 'manual'])->assertOk();
        $asset = $this->getJson('/api/assets')->assertOk()->json('data.0');
        $this->assertSame('1200.00', $asset['estimated_value']);
        $this->assertSame('1010.00', $asset['cost_basis']);
        $this->assertSame('190.00', $asset['unrealized_profit_loss']);

        $goalId = $this->postJson('/api/goals', ['name' => 'Rumah', 'target_amount' => '5000.00'])->assertCreated()->json('data.id');
        $this->postJson("/api/goals/$goalId/assets/$assetId/allocations", ['quantity' => '0.25000000', 'action' => 'allocate'])->assertCreated();
        $this->postJson("/api/goals/$goalId/assets/$assetId/allocations", ['quantity' => '0.30000000', 'action' => 'allocate'])->assertUnprocessable();

        $this->postJson("/api/assets/$assetId/transactions", ['type' => 'sale', 'cash_account_id' => $account->id, 'amount' => '500.00', 'quantity' => '0.20000000', 'unit_price' => '2500.00000000', 'transaction_date' => '2026-11-02'])->assertCreated();
        $this->assertSame('9490.00', $account->fresh()->balance);
        $asset = $this->getJson('/api/assets')->assertOk()->json('data.0');
        $this->assertSame('0.30000000', $asset['quantity']);
        $this->assertSame('96.00', $asset['realized_profit_loss']);
        $this->assertSame('0.00', $this->getJson('/api/reports/monthly?month=2026-11')->assertOk()->json('data.expense'));
        $this->assertSame('0.00', $this->getJson('/api/reports/monthly?month=2026-11')->json('data.income'));
    }

    public function test_rdpu_pending_subscription_and_redemption_only_change_holdings_and_cash_at_actual_events(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $account = $user->accounts()->create(['name' => 'Bank', 'type' => 'bank', 'balance' => '5000.00']);
        $assetId = $this->postJson('/api/assets', ['type' => 'rdpu', 'name' => 'RDPU Aman', 'provider' => 'Platform'])->assertCreated()->json('data.id');

        $subscriptionId = $this->postJson("/api/assets/$assetId/transactions", ['type' => 'subscription', 'status' => 'pending', 'cash_account_id' => $account->id, 'cash_debited' => true, 'amount' => '2000.00', 'quantity' => '1000.00000000', 'unit_price' => '2.00000000', 'transaction_date' => '2026-11-02'])->assertCreated()->json('data.id');
        $this->assertSame('3000.00', $account->fresh()->balance);
        $this->assertSame('0.00000000', $this->getJson('/api/assets')->assertOk()->json('data.0.quantity'));

        $this->postJson("/api/assets/transactions/$subscriptionId/status", ['status' => 'confirmed', 'quantity' => '990.00000000', 'unit_price' => '2.02020202', 'cash_debited' => true])->assertOk();
        $this->assertSame('990.00000000', $this->getJson('/api/assets')->assertOk()->json('data.0.quantity'));
        $this->assertSame('3000.00', $account->fresh()->balance);

        $redemptionId = $this->postJson("/api/assets/$assetId/transactions", ['type' => 'redemption', 'status' => 'pending', 'amount' => '0.00', 'quantity' => '400.00000000', 'transaction_date' => '2026-11-02'])->assertCreated()->json('data.id');
        $asset = $this->getJson('/api/assets')->assertOk()->json('data.0');
        $this->assertSame('990.00000000', $asset['quantity']);
        $this->assertSame('400.00000000', $asset['reserved_quantity']);
        $this->assertSame('3000.00', $account->fresh()->balance);

        $this->postJson("/api/assets/transactions/$redemptionId/status", ['status' => 'settled', 'amount' => '800.00', 'fees' => '10.00', 'cash_account_id' => $account->id])->assertOk();
        $asset = $this->getJson('/api/assets')->assertOk()->json('data.0');
        $this->assertSame('590.00000000', $asset['quantity']);
        $assetTransaction = \App\Models\AssetTransaction::where('asset_id', $assetId)->where('type', 'redemption')->firstOrFail();
        $this->assertNotNull($assetTransaction->settlement_transaction_id);
        $this->assertSame('790.00', $user->transactions()->findOrFail($assetTransaction->settlement_transaction_id)->amount);
        $this->assertSame('3790.00', $account->fresh()->balance);
        $this->assertSame('0.00', $this->getJson('/api/reports/monthly?month=2026-11')->assertOk()->json('data.income'));
    }

    public function test_total_assets_reconciles_cash_with_rdpu_purchase_and_pending_subscription(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $account = $user->accounts()->create(['name' => 'BCA', 'type' => 'bank', 'balance' => '5000000.00']);
        $assetId = $this->postJson('/api/assets', ['type' => 'rdpu', 'name' => 'RDPU Aman'])->assertCreated()->json('data.id');

        $before = $this->getJson('/api/reports/net-worth')->assertOk()->json('data');
        $this->assertSame('5000000.00', $before['cash_total']);
        $this->assertSame('5000000.00', $before['total_assets']);

        $subscriptionId = $this->postJson("/api/assets/$assetId/transactions", [
            'type' => 'subscription', 'status' => 'pending', 'cash_account_id' => $account->id,
            'cash_debited' => true, 'amount' => '1000000.00', 'quantity' => '1000.00000000',
            'unit_price' => '1000.00000000', 'fees' => '0.00', 'transaction_date' => '2026-11-02',
        ])->assertCreated()->json('data.id');

        $afterDebit = $this->getJson('/api/reports/net-worth')->assertOk()->json('data');
        $this->assertSame('4000000.00', $account->fresh()->balance);
        $this->assertSame('1000000.00', $afterDebit['investment_in_transit']);
        $this->assertSame('5000000.00', $afterDebit['total_assets']);
        $this->assertSame('0.00', $this->getJson('/api/reports/monthly?month=2026-11')->assertOk()->json('data.expense'));

        $this->postJson("/api/assets/transactions/$subscriptionId/status", [
            'status' => 'confirmed', 'quantity' => '1000.00000000', 'unit_price' => '1000.00000000', 'cash_debited' => true,
        ])->assertOk();
        $confirmed = $this->getJson('/api/reports/net-worth')->assertOk()->json('data');
        $this->assertSame('0.00', $confirmed['investment_in_transit']);
        $this->assertSame('1000000.00', $confirmed['investment_book_value_fallback']);
        $this->assertSame('5000000.00', $confirmed['total_assets']);

        $this->postJson("/api/assets/$assetId/prices", [
            'price' => '1100.00000000', 'captured_at' => '2026-11-02', 'source' => 'manual',
        ])->assertOk();
        $revalued = $this->getJson('/api/reports/net-worth')->assertOk()->json('data');
        $this->assertSame('1100000.00', $revalued['investment_market_value']);
        $this->assertSame('5100000.00', $revalued['total_assets']);
    }

    public function test_purchase_fees_reduce_total_assets_without_becoming_consumption(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $account = $user->accounts()->create(['name' => 'BCA', 'type' => 'bank', 'balance' => '5000000.00']);
        $assetId = $this->postJson('/api/assets', ['type' => 'gold', 'name' => 'Emas'])->assertCreated()->json('data.id');

        $this->postJson("/api/assets/$assetId/transactions", [
            'type' => 'purchase', 'cash_account_id' => $account->id, 'amount' => '1000000.00',
            'quantity' => '10.00000000', 'unit_price' => '100000.00000000', 'fees' => '10000.00',
            'transaction_date' => '2026-11-02',
        ])->assertCreated();

        $summary = $this->getJson('/api/reports/net-worth')->assertOk()->json('data');
        $this->assertSame('3990000.00', $summary['cash_total']);
        $this->assertSame('1000000.00', $summary['investment_book_value_fallback']);
        $this->assertSame('4990000.00', $summary['total_assets']);
        $this->assertSame('0.00', $this->getJson('/api/reports/monthly?month=2026-11')->assertOk()->json('data.expense'));
    }
}
