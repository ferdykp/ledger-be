<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_paid_paths_generate_only_one_successor(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $payload = ['name' => 'Subscription', 'amount' => 12000, 'due_date' => '2026-01-31', 'frequency' => 'monthly', 'status' => 'active'];
        $id = $this->postJson('/api/bills', $payload)->assertCreated()->json('data.id');
        $this->putJson("/api/bills/$id", [...$payload, 'status' => 'paid'])->assertOk();
        $this->assertEquals(1, $user->bills()->whereDate('due_date', '2026-02-28')->count());
        $this->putJson("/api/bills/$id", $payload)->assertOk();
        $this->postJson("/api/bills/$id/paid")->assertOk();
        $this->postJson("/api/bills/$id/paid")->assertOk();
        $this->assertEquals(2, $user->bills()->count());
        $next = $user->bills()->where('status', 'active')->first();
        $this->postJson("/api/bills/{$next->id}/paid")->assertOk();
        $this->assertEquals(3, $user->bills()->count());
        $this->postJson('/api/bills', [...$payload, 'name' => 'Already paid', 'status' => 'paid'])->assertCreated();
        $this->assertEquals(5, $user->bills()->count());
    }

    public function test_bill_summary_covers_all_pages_and_only_the_owner(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        foreach (range(1, 25) as $i) {
            $user->bills()->create(['name' => "Bill $i", 'amount' => 1000, 'due_date' => '2026-10-01', 'frequency' => 'monthly', 'status' => 'active', 'reminder_enabled' => true]);
        }
        User::factory()->create()->bills()->create(['name' => 'Private', 'amount' => 99999, 'due_date' => '2026-10-01', 'frequency' => 'monthly']);
        $this->getJson('/api/bills?today=2026-10-01')->assertOk()->assertJsonCount(20, 'data')
            ->assertJsonPath('summary.active_count', 25)->assertJsonPath('summary.monthly_total', 25000)
            ->assertJsonPath('summary.overdue_count', 25);
        $this->getJson('/api/bills?page=2&today=2026-09-30')->assertOk()->assertJsonCount(5, 'data')
            ->assertJsonPath('summary.monthly_total', 25000)->assertJsonPath('summary.overdue_count', 0);
    }

    public function test_goal_history_is_loaded_separately_and_paginated(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $goal = $user->goals()->create(['name' => 'Trip', 'target_amount' => 100000]);
        foreach (range(1, 25) as $i) {
            $goal->contributions()->create(['amount' => 100, 'date' => '2026-10-01']);
        }
        $this->getJson('/api/goals')->assertOk()->assertJsonMissingPath('data.0.contributions');
        $this->getJson("/api/goals/{$goal->id}/contributions?page=2")->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('meta.total', 25);
        $this->actingAs(User::factory()->create())->getJson("/api/goals/{$goal->id}/contributions")->assertForbidden();
    }

    public function test_cash_flow_uses_one_query_and_fills_empty_months(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 31));
        $user = User::factory()->create();
        $account = $user->accounts()->create(['name' => 'Cash', 'type' => 'cash']);
        $user->transactions()->create(['account_id' => $account->id, 'type' => 'income', 'amount' => 12000, 'date' => '2026-10-01']);
        $user->transactions()->create(['account_id' => $account->id, 'type' => 'transfer', 'amount' => 9000, 'date' => '2026-10-02']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $flow = app(TransactionService::class)->cashFlow($user, 6);
        $this->assertCount(1, DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertCount(6, $flow);
        $this->assertEquals('2026-05', $flow[0]['month']);
        $this->assertEquals(0, $flow[0]['income']);
        $this->assertEquals(12000, $flow[5]['income']);
        $this->assertEquals(0, $flow[5]['expense']);
    }
}
