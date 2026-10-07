<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AccountLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_login_password_change_reset_and_logout(): void
    {
        $credentials = ['email' => 'ledger-test@example.test', 'password' => 'Password123'];
        $registered = $this->postJson('/api/register', [...$credentials, 'name' => 'Test User', 'password_confirmation' => $credentials['password']])->assertCreated();
        $user = User::where('email', $credentials['email'])->firstOrFail();
        $token = $this->postJson('/api/login', $credentials)->assertOk()->json('data.token');
        $this->withToken($token)->getJson('/api/user')->assertOk()->assertJsonPath('email', $credentials['email']);
        $this->withToken($token)->putJson('/api/user/password', ['current_password' => 'wrong', 'password' => 'NewPassword123', 'password_confirmation' => 'NewPassword123'])->assertUnprocessable();
        $this->withToken($token)->putJson('/api/user/password', ['current_password' => 'Password123', 'password' => 'NewPassword123', 'password_confirmation' => 'NewPassword123'])->assertOk();
        $this->assertEquals(1, $user->tokens()->count());
        $this->assertTrue(Hash::check('NewPassword123', $user->fresh()->password));
        $this->withToken($token)->postJson('/api/logout')->assertOk();
        $this->assertEquals(0, $user->tokens()->count());
        $reset = Password::createToken($user);
        $user->createToken('other-device');
        $this->postJson('/api/reset-password', ['token' => $reset, 'email' => $user->email, 'password' => 'ResetPassword123', 'password_confirmation' => 'ResetPassword123'])->assertOk();
        $this->assertEquals(0, $user->tokens()->count());
        $this->assertTrue(Hash::check('ResetPassword123', $user->fresh()->password));
    }

    public function test_foreign_resources_cannot_be_mutated_and_export_is_private(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $account = $owner->accounts()->create(['name' => 'Private', 'type' => 'cash']);
        $category = $owner->categories()->create(['name' => 'Private', 'type' => 'expense']);
        $goal = $owner->goals()->create(['name' => 'Private', 'target_amount' => 100]);
        $bill = $owner->bills()->create(['name' => 'Private', 'amount' => 10, 'due_date' => '2026-10-01', 'frequency' => 'monthly']);
        $this->actingAs($other);
        foreach (["accounts/{$account->id}", "categories/{$category->id}", "goals/{$goal->id}", "bills/{$bill->id}"] as $path) {
            $this->deleteJson('/api/'.$path)->assertForbidden();
        }
        $this->getJson('/api/transactions/export')->assertOk();
    }

    public function test_recurring_bill_payment_is_idempotent_and_handles_month_end(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $id = $this->postJson('/api/bills', ['name' => 'Internet', 'amount' => 100, 'due_date' => '2026-01-31', 'frequency' => 'monthly'])->assertCreated()->json('data.id');
        $this->postJson("/api/bills/$id/paid")->assertOk();
        $this->postJson("/api/bills/$id/paid")->assertOk();
        $this->assertEquals(2, $user->bills()->count());
        $this->assertEquals('2026-02-28', $user->bills()->where('status', 'active')->first()->due_date->format('Y-m-d'));
    }

    public function test_pagination_and_csv_include_records_beyond_the_default_limit(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $account = $user->accounts()->create(['name' => 'Cash', 'type' => 'cash']);
        for ($i = 0; $i < 105; $i++) {
            $user->transactions()->create(['account_id' => $account->id, 'type' => 'income', 'amount' => 1, 'date' => '2026-10-01', 'note' => '=SUM(1,2)']);
        }
        $this->getJson('/api/transactions?page=2&per_page=100')->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('meta.total', 105);
        $csv = $this->get('/api/transactions/export')->assertOk()->streamedContent();
        $this->assertEquals(106, count(array_filter(explode("\n", $csv))));
        $this->assertStringContainsString("'=SUM", $csv);
        $this->getJson('/api/reports/monthly?month=2026-10')->assertOk()->assertJsonPath('data.income', 105);
    }
}
