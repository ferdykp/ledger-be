<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SharedFinancialWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_email_invitation_is_scoped_to_the_invited_member_and_acceptance_is_required(): void
    {
        $owner = User::factory()->create();
        $partner = User::factory()->create();
        $stranger = User::factory()->create();

        $this->actingAs($owner);
        $workspace = $this->postJson('/api/shared-workspaces', ['name' => 'Emas Bareng'])->assertCreated()->json('data');
        $invitation = $this->postJson("/api/shared-workspaces/{$workspace['id']}/invitations", ['email' => $partner->email])
            ->assertCreated()->json('data');

        $this->actingAs($stranger)->getJson("/api/shared-workspaces/{$workspace['id']}")->assertNotFound();
        $this->postJson('/api/shared-workspaces/invitations/accept', ['token' => $invitation['invite_token']])->assertForbidden();

        $this->actingAs($partner)->postJson('/api/shared-workspaces/invitations/accept', ['token' => $invitation['invite_token']])->assertOk();
        $this->getJson("/api/shared-workspaces/{$workspace['id']}")->assertOk()->assertJsonCount(2, 'data.members');
    }

    public function test_shared_contributions_gold_purchase_and_valuation_split_cash_and_units_without_expense(): void
    {
        $owner = User::factory()->create();
        $partner = User::factory()->create();
        $ownerAccount = $owner->accounts()->create(['name' => 'BCA', 'type' => 'bank', 'purpose' => 'operational', 'balance' => '1000000.00']);
        $partnerAccount = $partner->accounts()->create(['name' => 'Jago', 'type' => 'bank', 'purpose' => 'operational', 'balance' => '1000000.00']);

        $this->actingAs($owner);
        $workspace = $this->postJson('/api/shared-workspaces', ['name' => 'Emas Bareng'])->assertCreated()->json('data');
        $invitation = $this->postJson("/api/shared-workspaces/{$workspace['id']}/invitations", ['email' => $partner->email])->assertCreated()->json('data');
        $this->actingAs($partner)->postJson('/api/shared-workspaces/invitations/accept', ['token' => $invitation['invite_token']])->assertOk();

        $this->actingAs($owner)->postJson("/api/shared-workspaces/{$workspace['id']}/contributions", [
            'source_account_id' => $ownerAccount->id, 'amount' => '500000.00', 'entry_date' => '2026-11-02', 'idempotency_key' => 'owner-save-1',
        ])->assertCreated();
        $this->actingAs($partner)->postJson("/api/shared-workspaces/{$workspace['id']}/contributions", [
            'source_account_id' => $partnerAccount->id, 'amount' => '500000.00', 'entry_date' => '2026-11-02', 'idempotency_key' => 'partner-save-1',
        ])->assertCreated();

        $this->assertSame('500000.00', $ownerAccount->fresh()->balance);
        $this->assertSame('500000.00', $partnerAccount->fresh()->balance);
        $asset = $this->actingAs($owner)->postJson("/api/shared-workspaces/{$workspace['id']}/assets", ['name' => 'Emas Digital', 'provider' => 'Tring'])->assertCreated()->json('data.assets.0');

        $this->postJson("/api/shared-workspaces/{$workspace['id']}/assets/{$asset['id']}/transactions", [
            'action' => 'purchase', 'amount' => '1000000.00', 'quantity' => '0.01000000', 'fees' => '0.00',
            'transaction_date' => '2026-11-02', 'idempotency_key' => 'shared-buy-1',
        ])->assertCreated()->assertJsonPath('data.cash_balance', '0.00');

        $valued = $this->postJson("/api/shared-workspaces/{$workspace['id']}/assets/{$asset['id']}/transactions", [
            'action' => 'valuation', 'unit_price' => '110000000.00000000', 'transaction_date' => '2026-11-02',
        ])->assertCreated()
            ->assertJsonPath('data.assets.0.estimated_value', '1100000.00');
        $this->assertEquals(0.005, (float) $valued->json('data.assets.0.holdings.0.quantity'));
        $this->assertEquals(0.005, (float) $valued->json('data.assets.0.holdings.1.quantity'));

        $sold = $this->postJson("/api/shared-workspaces/{$workspace['id']}/assets/{$asset['id']}/transactions", [
            'action' => 'sale', 'amount' => '220000.00', 'quantity' => '0.00200000', 'fees' => '10000.00',
            'transaction_date' => '2026-11-02', 'idempotency_key' => 'shared-sale-1',
        ])->assertCreated();
        $this->assertSame('210000.00', $sold->json('data.cash_balance'));
        $this->assertEquals(0.004, (float) $sold->json('data.assets.0.holdings.0.quantity'));
        $this->assertEquals(105000, (float) $sold->json('data.members.0.cash_balance'));

        $this->assertSame('0.00', $this->getJson('/api/reports/monthly?month=2026-11')->assertOk()->json('data.expense'));
    }

    public function test_member_cannot_contribute_from_another_users_account_or_spend_unavailable_shared_cash(): void
    {
        $owner = User::factory()->create();
        $partner = User::factory()->create();
        $foreignAccount = $owner->accounts()->create(['name' => 'BCA', 'type' => 'bank', 'balance' => '1000000.00']);
        $this->actingAs($owner);
        $workspace = $this->postJson('/api/shared-workspaces', ['name' => 'Emas Bareng'])->assertCreated()->json('data');
        $invitation = $this->postJson("/api/shared-workspaces/{$workspace['id']}/invitations", ['email' => $partner->email])->assertCreated()->json('data');
        $this->actingAs($partner)->postJson('/api/shared-workspaces/invitations/accept', ['token' => $invitation['invite_token']])->assertOk();

        $this->postJson("/api/shared-workspaces/{$workspace['id']}/contributions", [
            'source_account_id' => $foreignAccount->id, 'amount' => '100000.00', 'entry_date' => '2026-11-02',
        ])->assertNotFound();

        $this->actingAs($owner);
        $asset = $this->postJson("/api/shared-workspaces/{$workspace['id']}/assets", ['name' => 'Emas Digital'])->assertCreated()->json('data.assets.0');
        $this->postJson("/api/shared-workspaces/{$workspace['id']}/assets/{$asset['id']}/transactions", [
            'action' => 'purchase', 'amount' => '100000.00', 'quantity' => '0.00100000', 'transaction_date' => '2026-11-02',
        ])->assertUnprocessable();
    }

    public function test_shared_cash_withdrawal_returns_funds_to_the_member_and_is_idempotent(): void
    {
        $user = User::factory()->create();
        $account = $user->accounts()->create(['name' => 'BCA', 'type' => 'bank', 'balance' => '1000000.00']);
        $this->actingAs($user);
        $workspace = $this->postJson('/api/shared-workspaces', ['name' => 'Emas Bareng'])->assertCreated()->json('data');
        $this->postJson("/api/shared-workspaces/{$workspace['id']}/contributions", [
            'source_account_id' => $account->id, 'amount' => '500000.00', 'entry_date' => '2026-11-02', 'idempotency_key' => 'contribute-1',
        ])->assertCreated();

        $payload = ['destination_account_id' => $account->id, 'amount' => '200000.00', 'entry_date' => '2026-11-02', 'idempotency_key' => 'withdraw-1'];
        $this->postJson("/api/shared-workspaces/{$workspace['id']}/withdrawals", $payload)->assertCreated()->assertJsonPath('data.cash_balance', '300000.00');
        $this->postJson("/api/shared-workspaces/{$workspace['id']}/withdrawals", $payload)->assertCreated()->assertJsonPath('data.cash_balance', '300000.00');
        $this->assertSame('700000.00', $account->fresh()->balance);
        $report = $this->getJson('/api/reports/monthly?month=2026-11')->assertOk()->json('data');
        $this->assertSame('0.00', $report['expense']);
        $this->assertSame('0.00', $report['income']);
    }

    public function test_shared_goal_reserves_cash_without_double_counting_and_blocks_spending_reserved_funds(): void
    {
        $user = User::factory()->create();
        $account = $user->accounts()->create(['name' => 'BCA', 'type' => 'bank', 'balance' => '1000000.00']);
        $this->actingAs($user);
        $workspace = $this->postJson('/api/shared-workspaces', ['name' => 'Rencana Bersama'])->assertCreated()->json('data');
        $this->postJson("/api/shared-workspaces/{$workspace['id']}/contributions", [
            'source_account_id' => $account->id, 'amount' => '1000000.00', 'entry_date' => '2026-11-02', 'idempotency_key' => 'shared-goal-fund',
        ])->assertCreated();
        $goal = $this->postJson("/api/shared-workspaces/{$workspace['id']}/goals", ['name' => 'Liburan', 'target_amount' => '800000.00'])->assertCreated()->json('data.goals.0');
        $this->postJson("/api/shared-workspaces/{$workspace['id']}/goals/{$goal['id']}/allocations", [
            'action' => 'allocate', 'amount' => '800000.00', 'allocation_date' => '2026-11-02', 'idempotency_key' => 'goal-allocate-1',
        ])->assertCreated()->assertJsonPath('data.cash_balance', '1000000.00')->assertJsonPath('data.goals.0.funded_amount', '800000.00');

        $asset = $this->postJson("/api/shared-workspaces/{$workspace['id']}/assets", ['name' => 'Emas tujuan'])->assertCreated()->json('data.assets.0');
        $this->postJson("/api/shared-workspaces/{$workspace['id']}/assets/{$asset['id']}/transactions", [
            'action' => 'purchase', 'amount' => '300000.00', 'quantity' => '0.00300000', 'transaction_date' => '2026-11-02',
        ])->assertUnprocessable();
        $this->postJson("/api/shared-workspaces/{$workspace['id']}/withdrawals", [
            'destination_account_id' => $account->id, 'amount' => '300000.00', 'entry_date' => '2026-11-02',
        ])->assertUnprocessable();

        $this->postJson("/api/shared-workspaces/{$workspace['id']}/goals/{$goal['id']}/allocations", [
            'action' => 'release', 'amount' => '200000.00', 'allocation_date' => '2026-11-02', 'idempotency_key' => 'goal-release-1',
        ])->assertCreated();
        $this->postJson("/api/shared-workspaces/{$workspace['id']}/assets/{$asset['id']}/transactions", [
            'action' => 'purchase', 'amount' => '300000.00', 'quantity' => '0.00300000', 'transaction_date' => '2026-11-02',
        ])->assertCreated()->assertJsonPath('data.cash_balance', '700000.00')->assertJsonPath('data.members.0.cash_reserved', '600000');
    }
}
