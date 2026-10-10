<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppVerificationCode;
use App\Services\WhatsApp\EvolutionProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class WhatsAppVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function code(User $user, array $attributes = []): WhatsAppVerificationCode
    {
        return WhatsAppVerificationCode::create([
            'user_id' => $user->id, 'phone_number' => '6281234567890',
            'code_hash' => Hash::make('123456'), 'expires_at' => now()->addMinutes(10), ...$attributes,
        ]);
    }

    public function test_send_normalizes_phone_hashes_code_and_applies_cooldown(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $code = null;
        $this->mock(EvolutionProvider::class)->shouldReceive('sendText')->once()->andReturnUsing(function ($phone, $text) use (&$code) {
            $this->assertSame('6281234567890', $phone);
            preg_match('/\*(\d{6})\*/', $text, $match);
            $code = $match[1];

            return true;
        });
        $this->postJson('/api/integrations/whatsapp/otp/send', ['phone_number' => '0812-3456-7890'])->assertOk()->assertJsonMissingPath('data.code');
        $otp = WhatsAppVerificationCode::firstOrFail();
        $this->assertTrue(Hash::check($code, $otp->code_hash));
        $this->assertNotSame($code, $otp->code_hash);
        $this->postJson('/api/integrations/whatsapp/otp/send', ['phone_number' => '081234567890'])->assertStatus(429);
    }

    public function test_failed_delivery_keeps_previous_code_valid_and_releases_cooldown(): void
    {
        $user = User::factory()->create();
        $old = $this->code($user);
        $this->actingAs($user);
        $this->mock(EvolutionProvider::class)->shouldReceive('sendText')->once()->andReturn(false);
        $this->postJson('/api/integrations/whatsapp/otp/send', ['phone_number' => '081234567890'])->assertStatus(502);
        $this->assertDatabaseCount('whatsapp_verification_codes', 1);
        $this->assertNull($old->fresh()->used_at);
        $this->assertFalse(Cache::has('wa-otp:cooldown:'.$user->id));
    }

    public function test_slow_older_delivery_does_not_invalidate_a_newer_code(): void
    {
        $user = User::factory()->create();
        $old = $this->code($user);
        $newer = null;
        $this->actingAs($user);
        $this->mock(EvolutionProvider::class)->shouldReceive('sendText')->once()->andReturnUsing(function () use ($user, &$newer) {
            $newer = $this->code($user);

            return true;
        });
        $this->postJson('/api/integrations/whatsapp/otp/send', ['phone_number' => '081234567890'])->assertOk();
        $this->assertNotNull($old->fresh()->used_at);
        $this->assertNull($newer->fresh()->used_at);
    }

    public function test_correct_code_is_consumed_even_if_welcome_delivery_fails(): void
    {
        $user = User::factory()->create();
        $otp = $this->code($user);
        $this->actingAs($user);
        $this->mock(EvolutionProvider::class)->shouldReceive('sendText')->once()->andReturn(false);
        $this->postJson('/api/integrations/whatsapp/otp/verify', ['code' => '123456'])->assertOk();
        $this->assertNotNull($otp->fresh()->used_at);
        $this->assertDatabaseHas('whatsapp_connections', ['user_id' => $user->id, 'status' => 'connected']);
        $this->postJson('/api/integrations/whatsapp/otp/verify', ['code' => '123456'])->assertUnprocessable();
    }

    public function test_expiry_attempt_limit_and_owner_scope_are_enforced(): void
    {
        $user = User::factory()->create();
        $otp = $this->code($user, ['expires_at' => now()]);
        $this->actingAs($user);
        $this->mock(EvolutionProvider::class)->shouldNotReceive('sendText');
        $this->postJson('/api/integrations/whatsapp/otp/verify', ['code' => '123456'])->assertUnprocessable();
        $otp->update(['expires_at' => now()->addMinutes(10)]);
        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/integrations/whatsapp/otp/verify', ['code' => '999999'])->assertUnprocessable();
        }
        $this->assertSame(5, $otp->fresh()->attempts);
        $this->postJson('/api/integrations/whatsapp/otp/verify', ['code' => '123456'])->assertUnprocessable();
        $this->actingAs(User::factory()->create());
        $this->postJson('/api/integrations/whatsapp/otp/verify', ['code' => '123456'])->assertUnprocessable();
        $this->assertDatabaseCount('whatsapp_connections', 0);
    }

    public function test_reserved_phone_is_rejected_before_sending_and_latest_code_is_required(): void
    {
        $owner = User::factory()->create();
        WhatsAppConnection::create(['user_id' => $owner->id, 'phone_number' => '6281234567890', 'status' => 'pending']);
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->mock(EvolutionProvider::class)->shouldNotReceive('sendText');
        $this->postJson('/api/integrations/whatsapp/otp/send', ['phone_number' => '081234567890'])->assertUnprocessable();
        $this->assertDatabaseCount('whatsapp_verification_codes', 0);
        $this->code($user, ['phone_number' => '6281111111111']);
        $this->code($user, ['phone_number' => '6281111111111', 'code_hash' => Hash::make('654321')]);
        $this->postJson('/api/integrations/whatsapp/otp/verify', ['code' => '123456'])->assertUnprocessable();
    }
}
