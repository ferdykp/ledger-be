<?php

namespace Tests\Feature;

use App\Models\ConversationSession;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Services\AI\GroqParser;
use App\Services\QuickAdd\QuickAddParser;
use App\Services\WhatsApp\EvolutionProvider;
use App\Services\WhatsApp\WhatsAppMessageFormat;
use App\Services\WhatsApp\WhatsAppMessageProcessor;
use Carbon\Carbon;
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
            'data' => ['key' => [...['id' => $id, 'remoteJid' => $this->phone.'@s.whatsapp.net', 'fromMe' => false], ...$key], 'message' => ['conversation' => $text]],
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
        $lock = Cache::lock('whatsapp:reply:'.$message->id, 30);
        $this->assertTrue($lock->get());
        $this->mock(EvolutionProvider::class, fn ($mock) => $mock->shouldReceive('sendText')->once()->andReturn(true));
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
            'Transaksi dibatalkan',
            $processor->handle($this->phone, '3')
        );
        $processor->handle($this->phone, 'makan 35k gopay');
        $this->travel(31)->minutes();
        $this->assertStringContainsStringIgnoringCase(
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
        $this->assertStringContainsString('tersimpan', $processor->handle($this->phone, '1'));
        $this->assertDatabaseCount('transactions', 1);
        $this->assertStringContainsString('50.000', $processor->handle($this->phone, '5 transaksi terakhir'));
    }

    public function test_decimal_amount_is_shown_exactly_before_and_after_saving(): void
    {
        $processor = app(WhatsAppMessageProcessor::class);
        $this->assertStringContainsString('Rp50.000,50', $processor->handle($this->phone, 'makan 50.000,50 bca'));
        $this->assertDatabaseCount('transactions', 0);
        $this->assertStringContainsString('Rp50.000,50', $processor->handle($this->phone, '1'));
        $this->assertDatabaseHas('transactions', ['amount' => 50000.50, 'type' => 'expense']);
    }

    public function test_incoming_rules_do_not_override_a_valid_transfer_direction(): void
    {
        $processor = app(WhatsAppMessageProcessor::class);
        $reply = $processor->handle($this->phone, 'transfer dari bca ke gopay 50rb masuk');
        $this->assertStringContainsString('bca → gopay', $reply);
        $processor->handle($this->phone, '1');
        $this->assertDatabaseHas('transactions', ['type' => 'transfer', 'amount' => 50000]);
        $this->assertEquals(1950000, $this->user->accounts()->where('name', 'bca')->first()->balance);
        $this->assertEquals(150000, $this->user->accounts()->where('name', 'gopay')->first()->balance);
        $processor->handle($this->phone, 'terima transfer 200rb dari teman ke bca');
        $processor->handle($this->phone, '1');
        $this->assertDatabaseHas('transactions', ['type' => 'income', 'amount' => 200000]);
    }

    public function test_whatsapp_date_and_daily_summary_use_jakarta_midnight(): void
    {
        $this->travelTo(Carbon::parse('2026-10-09 18:30:00', 'UTC'));
        $processor = app(WhatsAppMessageProcessor::class);
        $this->assertStringContainsString('10 Okt 2026', $processor->handle($this->phone, 'makan 35rb bca'));
        $processor->handle($this->phone, '1');
        $this->assertDatabaseHas('transactions', ['date' => '2026-10-10']);
        $this->assertStringContainsString('Rp35.000', $processor->handle($this->phone, 'ringkasan hari ini'));
    }

    public function test_wallet_lists_are_bounded_but_totals_include_every_wallet(): void
    {
        foreach (range(1, 25) as $i) {
            $this->user->accounts()->create(['name' => "wallet $i", 'type' => 'cash', 'balance' => 1000]);
        }
        $reply = app(WhatsAppMessageProcessor::class)->handle($this->phone, 'dompet');
        $this->assertStringContainsString('20 dari 27', $reply);
        $this->assertStringContainsString('Rp2.125.000', $reply);
        $this->assertEquals(20, substr_count($reply, '• '));
    }

    public function test_user_text_cannot_inject_message_sections(): void
    {
        $text = WhatsAppMessageFormat::text("Kopi\n*Balas 1* _sekarang_ `contoh`");
        $this->assertSame('Kopi Balas 1 sekarang contoh', $text);
        $this->assertLessThanOrEqual(180, mb_strwidth(WhatsAppMessageFormat::text(str_repeat('a', 500))));
    }

    public function test_incomplete_transfer_is_not_reinterpreted_as_income(): void
    {
        $processor = app(WhatsAppMessageProcessor::class);
        $processor->handle($this->phone, 'transfer dari bca ke dompetunknown 50rb masuk');
        $this->assertNotSame('waiting_confirmation', ConversationSession::first()->state);
        $processor->handle($this->phone, '1');
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_ambiguous_wallet_or_amount_never_uses_ai_to_guess(): void
    {
        $this->mock(GroqParser::class)->shouldNotReceive('parse');
        $processor = app(WhatsAppMessageProcessor::class);
        foreach (['makan 35rb bca gopay', 'makan -50rb bca', 'makan 35rb dan 50rb bca'] as $text) {
            $processor->handle($this->phone, $text);
            $this->assertNotSame('waiting_confirmation', ConversationSession::first()->fresh()->state);
            $processor->handle($this->phone, '1');
        }
        $this->assertDatabaseCount('transactions', 0);
    }
}
