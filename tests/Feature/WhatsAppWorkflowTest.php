<?php

namespace Tests\Feature;

use App\Models\ConversationSession;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Services\AI\GroqParser;
use App\Services\QuickAdd\QuickAddParser;
use App\Services\WhatsApp\EvolutionProvider;
use App\Services\WhatsApp\WhatsAppMessageProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class WhatsAppWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $phone = '6281234567890';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.evolution.webhook_secret' => 'test-secret', 'services.groq.key' => null]);
        $this->user = User::factory()->create();
        // Deliberately create destination first, to catch database-order transfers.
        $this->user->accounts()->create(['name' => 'gopay', 'type' => 'ewallet', 'balance' => 100000]);
        $this->user->accounts()->create(['name' => 'bca', 'type' => 'bank', 'balance' => 2000000]);
        WhatsAppConnection::create(['user_id' => $this->user->id, 'phone_number' => $this->phone, 'status' => 'connected', 'verified_at' => now()]);
    }

    private function webhook(string $id, string $text, array $key = [])
    {
        return $this->postJson('/api/webhooks/whatsapp', [
            'event' => 'messages.upsert',
            'data' => ['key' => [...['id' => $id, 'remoteJid' => $this->phone . '@s.whatsapp.net', 'fromMe' => false], ...$key], 'message' => ['conversation' => $text]],
        ], ['X-Ledger-Webhook-Secret' => 'test-secret']);
    }

    public function test_reply_delivery_does_not_hold_a_database_transaction(): void
    {
        $level = DB::transactionLevel();
        $this->mock(EvolutionProvider::class, function ($mock) use ($level) {
            $mock->shouldReceive('sendText')->once()->andReturnUsing(function () use ($level) {
                $this->assertSame($level, DB::transactionLevel());

                return true;
            });
        });
        $this->webhook('outside-transaction', 'bantuan')->assertOk();
        $this->webhook('outside-transaction', 'bantuan')->assertOk();
    }

    public function test_concurrent_reply_attempt_is_retryable_and_sends_once(): void
    {
        $message = WhatsAppMessage::create([
            'provider_message_id' => 'locked-reply',
            'phone_number' => $this->phone,
            'message_type' => 'text',
            'body' => 'bantuan',
            'processed_at' => now(),
            'reply_text' => 'Help',
        ]);
        $lock = Cache::lock('whatsapp:reply:' . $message->id, 30);
        $this->assertTrue($lock->get());
        $this->mock(EvolutionProvider::class, fn($mock) => $mock->shouldReceive('sendText')->once()->andReturn(true));
        try {
            $this->webhook('locked-reply', 'bantuan')->assertStatus(502)->assertJsonPath('reply_pending', true);
            $this->assertNull($message->fresh()->replied_at);
        } finally {
            $lock->release();
        }
        $this->webhook('locked-reply', 'bantuan')->assertOk();
        $this->webhook('locked-reply', 'bantuan')->assertOk();
    }

    public function test_amount_formats_and_transfer_direction_are_not_database_order(): void
    {
        $parser = app(QuickAddParser::class);
        foreach (['1.000.000' => 1000000, '1,000,000' => 1000000, '50.000,50' => 50000.5, '1,5jt' => 1500000, '1.5jt' => 1500000, '50rb' => 50000, '35k' => 35000] as $input => $expected) {
            $this->assertEquals($expected, $parser->parse($this->user, "gaji $input bca")['amount'], $input);
        }
        foreach (['transfer 50rb bca ke gopay', 'transfer bca ke gopay 50rb', 'transfer 50rb dari bca ke gopay'] as $text) {
            $draft = $parser->parse($this->user, $text);
            $this->assertSame('bca', $draft['account_name']);
            $this->assertSame('gopay', $draft['related_account_name']);
        }
        $this->assertNull($parser->parse($this->user, 'makan -50rb bca')['amount']);
        $this->assertEquals(50000, $parser->parse($this->user, 'makan 2 orang 50rb bca')['amount']);
    }

    public function test_longer_wallet_name_is_not_confused_with_its_prefix(): void
    {
        $this->user->accounts()->create(['name' => 'bca utama', 'type' => 'bank']);
        $draft = app(QuickAddParser::class)->parse($this->user, 'transfer 50rb bca utama ke gopay');
        $this->assertSame('bca utama', $draft['account_name']);
        $this->assertSame('gopay', $draft['related_account_name']);
    }

    public function test_invalid_web_form_type_returns_validation_error(): void
    {
        $this->actingAs($this->user)->postJson('/api/transactions', ['type' => []])->assertUnprocessable();
    }

    public function test_preview_confirm_replay_and_second_confirmation_only_move_money_once(): void
    {
        $provider = Mockery::mock(EvolutionProvider::class);
        $provider->shouldReceive('sendText')->times(3)->andReturnTrue();
        $this->app->instance(EvolutionProvider::class, $provider);
        $this->webhook('draft-1', 'transfer 50rb bca ke gopay')->assertOk();
        $this->assertDatabaseCount('transactions', 0);
        $this->assertStringContainsString('bca → gopay', WhatsAppMessage::first()->reply_text);
        $this->webhook('confirm-1', '1')->assertOk();
        $this->webhook('confirm-1', '1')->assertOk();
        $this->webhook('confirm-2', '1')->assertOk();
        $this->assertDatabaseCount('transactions', 1);
        $this->assertEquals(1950000, $this->user->accounts()->where('name', 'bca')->first()->balance);
        $this->assertEquals(150000, $this->user->accounts()->where('name', 'gopay')->first()->balance);
        $this->assertSame('idle', ConversationSession::first()->state);
    }

    public function test_failed_reply_is_retried_without_repeating_transaction(): void
    {
        app(WhatsAppMessageProcessor::class)->handle($this->phone, 'bensin 50rb bca');
        $provider = Mockery::mock(EvolutionProvider::class);
        $provider->shouldReceive('sendText')->twice()->andReturn(false, true);
        $this->app->instance(EvolutionProvider::class, $provider);
        $this->webhook('confirm-delivery', '1')->assertStatus(502);
        $this->assertDatabaseCount('transactions', 1);
        $message = WhatsAppMessage::first();
        $this->assertNotNull($message->processed_at);
        $this->assertNull($message->replied_at);
        $this->webhook('confirm-delivery', '1')->assertOk();
        $this->assertDatabaseCount('transactions', 1);
        $this->assertNotNull($message->fresh()->replied_at);
    }

    public function test_processing_failure_remains_retryable(): void
    {
        $processor = Mockery::mock(WhatsAppMessageProcessor::class);
        $processor->shouldReceive('handle')->once()->andThrow(new \RuntimeException('simulated failure'));
        $this->app->instance(WhatsAppMessageProcessor::class, $processor);
        $this->webhook('retry-process', 'bensin 50rb bca')->assertStatus(500);
        $this->assertNull(WhatsAppMessage::first()->processed_at);
        $this->app->forgetInstance(WhatsAppMessageProcessor::class);
        $provider = Mockery::mock(EvolutionProvider::class);
        $provider->shouldReceive('sendText')->once()->andReturnTrue();
        $this->app->instance(EvolutionProvider::class, $provider);
        $this->webhook('retry-process', 'bensin 50rb bca')->assertOk();
        $this->assertSame('waiting_confirmation', ConversationSession::first()->state);
        $this->assertNotNull(WhatsAppMessage::first()->processed_at);
    }

    public function test_webhook_fails_closed_and_ignores_echoes_and_groups(): void
    {
        $this->postJson('/api/webhooks/whatsapp')->assertUnauthorized();
        config(['services.evolution.webhook_secret' => '']);
        $this->webhook('no-secret', 'bensin 50rb bca')->assertStatus(503);
        config(['services.evolution.webhook_secret' => 'test-secret']);
        $this->webhook('echo', 'bensin 50rb bca', ['fromMe' => true])->assertOk()->assertJsonPath('ignored', true);
        $this->webhook('group', '1', ['remoteJid' => '1234567890@g.us'])->assertOk()->assertJsonPath('ignored', true);
        $this->webhook('', '1')->assertUnprocessable();
        $this->assertDatabaseCount('whatsapp_messages', 0);
    }

    public function test_confirmation_revalidates_stale_category_and_amount(): void
    {
        $processor = app(WhatsAppMessageProcessor::class);
        $processor->handle($this->phone, 'bensin 50rb bca');
        $session = ConversationSession::first();
        $draft = $session->context;
        $draft['amount'] = -50000;
        $session->update(['context' => $draft]);
        $this->assertStringContainsString('tidak valid', $processor->handle($this->phone, '1'));
        $this->assertDatabaseCount('transactions', 0);
        $processor->handle($this->phone, 'bensin 50rb bca');
        $draft = $session->fresh()->context;
        $other = User::factory()->create()->categories()->create(['name' => 'Private', 'type' => 'expense']);
        $draft['category_id'] = $other->id;
        $session->update(['context' => $draft]);
        $this->assertStringContainsString('tidak valid', $processor->handle($this->phone, '1'));
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_cancel_expiry_disconnect_and_legacy_pairing_cannot_save_a_draft(): void
    {
        $processor = app(WhatsAppMessageProcessor::class);
        $processor->handle($this->phone, 'makan 35k gopay');
        $this->assertStringContainsString(
            'Transaksi Dibatalkan',
            $processor->handle($this->phone, '3')
        );
        $processor->handle($this->phone, 'makan 35k gopay');
        $this->travel(31)->minutes();
        $this->assertStringContainsString(
            'tidak ada transaksi yang menunggu konfirmasi',
            $processor->handle($this->phone, '1')
        );
        $processor->handle($this->phone, 'makan 35k gopay');
        $this->actingAs($this->user)->deleteJson('/api/integrations/whatsapp')->assertOk();
        $this->assertDatabaseCount('conversation_sessions', 0);
        $this->postJson('/api/integrations/whatsapp/pairing')->assertStatus(410);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_ai_timeout_and_invalid_output_do_not_crash_or_create_transactions(): void
    {
        config(['services.groq.key' => 'test-key']);
        Http::fake(['api.groq.com/*' => Http::failedConnection()]);
        $this->assertNull(app(GroqParser::class)->parse($this->user, 'makan'));
        foreach (['[]', '"text"', '{"amount":-1}', '{"amount":1,"account_name":[]}'] as $content) {
            Http::fake(['api.groq.com/*' => Http::response(['choices' => [['message' => ['content' => $content]]]])]);
            $this->assertNull(app(GroqParser::class)->parse($this->user, 'makan'));
        }
    }
    public function test_financial_information_commands_are_scoped_and_do_not_create_transactions(): void
    {
        $processor = app(WhatsAppMessageProcessor::class);
        $other = User::factory()->create();
        $other->accounts()->create(['name' => 'rahasia', 'type' => 'bank', 'balance' => 9999999]);
        $this->assertStringContainsString('bca', $processor->handle($this->phone, 'dompet'));
        $this->assertStringNotContainsString('rahasia', $processor->handle($this->phone, 'dompet'));
        $this->assertStringContainsString('Rp2.000.000', $processor->handle($this->phone, 'saldo bca'));
        $this->assertStringContainsString('tidak ditemukan', $processor->handle($this->phone, 'saldo rahasia'));
        $this->assertStringContainsString('Pemasukan', $processor->handle($this->phone, 'ringkasan hari ini'));
        $this->assertStringContainsString('Pengeluaran', $processor->handle($this->phone, 'ringkasan bulan ini'));
        $this->assertStringContainsString('Belum ada transaksi', $processor->handle($this->phone, '5 transaksi terakhir'));
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_information_commands_preserve_pending_confirmation(): void
    {
        $processor = app(WhatsAppMessageProcessor::class);
        $processor->handle($this->phone, 'bensin 50rb bca');
        $this->assertSame('waiting_confirmation', ConversationSession::first()->state);
        $this->assertStringContainsString('Saldo bca', $processor->handle($this->phone, 'saldo bca'));
        $this->assertSame('waiting_confirmation', ConversationSession::first()->fresh()->state);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertStringContainsString('Tersimpan', $processor->handle($this->phone, '1'));
        $this->assertDatabaseCount('transactions', 1);
        $this->assertStringContainsString('50.000', $processor->handle($this->phone, '5 transaksi terakhir'));
    }
}
