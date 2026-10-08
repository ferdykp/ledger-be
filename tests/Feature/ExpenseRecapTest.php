<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpenseRecapTest extends TestCase
{
    use RefreshDatabase;

    public function test_months_are_separate_and_totals_include_every_page(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $account = $user->accounts()->create(['name' => 'Cash', 'type' => 'cash']);
        foreach (range(1, 25) as $number) {
            $user->transactions()->create(['account_id' => $account->id, 'type' => 'expense', 'amount' => 1000, 'date' => '2026-05-01']);
        }
        $user->transactions()->create(['account_id' => $account->id, 'type' => 'expense', 'amount' => 50000, 'date' => '2026-06-30']);
        $this->getJson('/api/reports/expenses?month=2026-05')->assertOk()->assertJsonPath('data.expense', 25000)->assertJsonPath('data.count', 25)->assertJsonCount(20, 'data.transactions.data');
        $this->getJson('/api/reports/expenses?month=2026-05&page=2')->assertOk()->assertJsonPath('data.expense', 25000)->assertJsonCount(5, 'data.transactions.data');
        $this->getJson('/api/reports/expenses?month=2026-06')->assertOk()->assertJsonPath('data.expense', 50000)->assertJsonPath('data.previous_expense', 25000);
        $this->getJson('/api/reports/expenses?from=2026-01-01&to=2026-12-31')->assertOk()
            ->assertJsonPath('data.expense', 75000)->assertJsonPath('data.trend_interval', 'month')
            ->assertJsonCount(2, 'data.trend')->assertJsonPath('data.trend.0.date', '2026-05')
            ->assertJsonPath('data.trend.0.expense', 25000)->assertJsonPath('data.trend.1.expense', 50000);
    }

    public function test_range_is_inclusive_scoped_to_owner_and_excludes_transfers(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $account = $user->accounts()->create(['name' => 'Cash', 'type' => 'cash']);
        $category = $user->categories()->create(['name' => 'Makan', 'type' => 'expense']);
        foreach (['2026-09-30', '2026-10-01', '2026-10-05', '2026-10-06'] as $date) {
            $user->transactions()->create(['account_id' => $account->id, 'category_id' => $category->id, 'type' => 'expense', 'amount' => 10000, 'date' => $date]);
        }
        $user->transactions()->create(['account_id' => $account->id, 'type' => 'income', 'amount' => 50000, 'date' => '2026-10-05']);
        $user->transactions()->create(['account_id' => $account->id, 'type' => 'transfer', 'amount' => 90000, 'date' => '2026-10-01']);
        $other = User::factory()->create();
        $otherAccount = $other->accounts()->create(['name' => 'Private', 'type' => 'cash']);
        $other->transactions()->create(['account_id' => $otherAccount->id, 'type' => 'expense', 'amount' => 99999, 'date' => '2026-10-01']);
        $this->getJson('/api/reports/expenses?from=2026-10-01&to=2026-10-05')->assertOk()
            ->assertJsonPath('data.expense', 20000)->assertJsonPath('data.income', 50000)->assertJsonPath('data.net', 30000)
            ->assertJsonPath('data.daily_average', 4000)->assertJsonPath('data.days', 5)->assertJsonPath('data.count', 2)
            ->assertJsonPath('data.categories.0.name', 'Makan')->assertJsonPath('data.categories.0.percentage', 100)
            ->assertJsonPath('data.trend_interval', 'day')->assertJsonCount(2, 'data.trend')
            ->assertJsonPath('data.trend.1.income', 50000)
            ->assertJsonCount(2, 'data.transactions.data');
        $this->getJson('/api/reports/expenses?from=2026-10-05&to=2026-10-05')->assertOk()->assertJsonPath('data.days', 1)->assertJsonPath('data.expense', 10000);
    }

    public function test_empty_period_leap_year_and_invalid_filters(): void
    {
        $this->getJson('/api/reports/expenses?month=2026-05')->assertUnauthorized();
        $this->actingAs(User::factory()->create());
        $this->getJson('/api/reports/expenses?month=2024-02')->assertOk()->assertJsonPath('data.days', 29)->assertJsonPath('data.to', '2024-02-29')->assertJsonPath('data.expense', 0)->assertJsonCount(0, 'data.transactions.data');
        foreach (['', '?month=2026-13', '?from=2026-10-05&to=2026-10-01', '?from=2026-10-01', '?month=2026-05&from=2026-05-01&to=2026-05-31'] as $query) {
            $this->getJson('/api/reports/expenses'.$query)->assertUnprocessable();
        }
    }
}
