<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_transfer_edit_and_delete_preserve_balances_even_with_stale_models(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $a = $user->accounts()->create(['name' => 'Bank', 'type' => 'bank', 'balance' => 1000]);
        $b = $user->accounts()->create(['name' => 'Cash', 'type' => 'cash', 'balance' => 100]);
        $payload = ['type' => 'transfer', 'account_id' => $a->id, 'to_account_id' => $b->id, 'amount' => 200, 'date' => '2026-10-01'];
        $id = $this->postJson('/api/transactions', $payload)->assertCreated()->json('data.id');
        $stale = Transaction::findOrFail($id);
        $this->assertEquals(800, $a->fresh()->balance);
        $this->assertEquals(300, $b->fresh()->balance);
        $this->putJson("/api/transactions/$id", [...$payload, 'amount' => 300])->assertOk();
        app(TransactionService::class)->updateTransaction($user, $stale, [...$payload, 'amount' => 400]);
        $this->assertEquals(600, $a->fresh()->balance);
        $this->assertEquals(500, $b->fresh()->balance);
        app(TransactionService::class)->deleteTransaction($user, $stale);
        $this->assertEquals(1000, $a->fresh()->balance);
        $this->assertEquals(100, $b->fresh()->balance);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_invalid_or_foreign_transaction_references_never_change_balances(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($user);
        $a = $user->accounts()->create(['name' => 'Bank', 'type' => 'bank', 'balance' => 1000]);
        $foreign = $other->categories()->create(['name' => 'Private', 'type' => 'expense']);
        $payload = ['type' => 'expense', 'account_id' => $a->id, 'amount' => 100, 'date' => '2026-10-01'];
        $this->postJson('/api/transactions', [...$payload, 'category_id' => $foreign->id])->assertUnprocessable();
        $this->postJson('/api/transactions', [...$payload, 'amount' => 0.001])->assertUnprocessable();
        $this->postJson('/api/transactions', [...$payload, 'type' => 'transfer', 'to_account_id' => $a->id])->assertUnprocessable();
        $this->assertEquals(1000, $a->fresh()->balance);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_reports_include_first_day_and_do_not_overflow_short_months(): void
    {
        $this->travelTo(now()->setDate(2026, 3, 31));
        $user = User::factory()->create();
        $this->actingAs($user);
        $account = $user->accounts()->create(['name' => 'Cash', 'type' => 'cash']);
        foreach (['2026-02-01', '2026-02-07', '2026-02-08', '2026-02-28'] as $date) {
            $this->postJson('/api/transactions', ['type' => 'expense', 'account_id' => $account->id, 'amount' => 100, 'date' => $date])->assertCreated();
        }
        $report = $this->getJson('/api/reports/monthly?month=2026-02')->assertOk()->json('data');
        $this->assertEquals(400, $report['expense']);
        $this->assertEquals(400, array_sum(array_column($report['weekly'], 'expense')));
        $this->assertEquals(200, $report['weekly'][0]['expense']);
    }

    public function test_accounts_with_transactions_return_validation_error_instead_of_database_error(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $a = $user->accounts()->create(['name' => 'Bank', 'type' => 'bank']);
        $b = $user->accounts()->create(['name' => 'Cash', 'type' => 'cash']);
        $this->postJson('/api/transactions', ['type' => 'transfer', 'account_id' => $a->id, 'to_account_id' => $b->id, 'amount' => 10, 'date' => '2026-10-01'])->assertCreated();
        $this->deleteJson("/api/accounts/{$a->id}")->assertUnprocessable();
        $this->deleteJson("/api/accounts/{$b->id}")->assertUnprocessable();
        $this->getJson('/api/budgets?month=invalid')->assertUnprocessable();
    }

    public function test_budget_upsert_returns_identity_and_counts_first_day_expense(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $a = $user->accounts()->create(['name' => 'Cash', 'type' => 'cash']);
        $category = $user->categories()->create(['name' => 'Food', 'type' => 'expense']);
        $this->postJson('/api/transactions', ['type' => 'expense', 'account_id' => $a->id, 'category_id' => $category->id, 'amount' => 10, 'date' => '2026-10-01'])->assertCreated();
        $payload = ['category_id' => $category->id, 'amount_limit' => 100, 'start_date' => '2026-10-01'];
        $this->postJson('/api/budgets', $payload)->assertCreated()->assertJsonPath('data.category_id', $category->id);
        $this->postJson('/api/budgets', [...$payload, 'amount_limit' => 200])->assertCreated();
        $result = $this->getJson('/api/budgets?month=2026-10')->assertOk()->json('data');
        $this->assertCount(1, $result);
        $this->assertEquals(10, $result[0]['spent_amount']);
    }
}
