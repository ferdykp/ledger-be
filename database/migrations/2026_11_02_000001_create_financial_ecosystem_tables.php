<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goals', function (Blueprint $table) {
            $table->decimal('opening_balance', 15, 2)->default(0)->after('current_amount');
        });

        // Preserve the old saved amount without inventing a historical contribution.
        DB::table('goals')->orderBy('id')->get()->each(function ($goal): void {
            $toMinor = static function ($amount): int {
                [$whole, $fraction] = array_pad(explode('.', (string) $amount, 2), 2, '');
                $fraction = str_pad(substr($fraction, 0, 2), 2, '0');
                return ((int) $whole * 100) + (int) $fraction;
            };
            $toDecimal = static fn (int $minor): string => intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
            $contributed = '0.00';
            foreach (DB::table('goal_contributions')->where('goal_id', $goal->id)->orderBy('id')->get(['amount']) as $entry) {
                $contributed = bcadd($contributed, (string) $entry->amount, 2);
            }
            $opening = max(0, $toMinor($goal->current_amount) - $toMinor($contributed));
            DB::table('goals')->where('id', $goal->id)->update(['opening_balance' => $toDecimal($opening)]);
        });

        Schema::table('goal_contributions', function (Blueprint $table) {
            $table->string('note', 500)->nullable()->after('date');
            $table->string('idempotency_key', 80)->nullable();
            $table->unique(['goal_id', 'idempotency_key']);
        });

        Schema::create('goal_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goal_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->decimal('quantity', 16, 4)->default(1);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['goal_id', 'sort_order']);
        });

        Schema::create('goal_obligations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goal_item_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->decimal('amount', 15, 2);
            $table->date('due_date')->nullable();
            $table->string('payee', 160)->nullable();
            $table->string('status', 24)->default('planned');
            $table->timestamps();
            $table->index(['goal_item_id', 'due_date']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->string('financial_purpose', 32)->nullable()->index();
            $table->foreignId('goal_obligation_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('goal_allocation_amount', 15, 2)->default(0);
            $table->string('idempotency_key', 80)->nullable();
            $table->unique(['user_id', 'idempotency_key']);
        });

        Schema::create('goal_cash_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('goal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('action', 24);
            $table->string('note', 500)->nullable();
            $table->string('idempotency_key', 80)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'account_id']);
            $table->index(['goal_id', 'created_at']);
            $table->unique(['user_id', 'idempotency_key']);
        });

        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->string('name', 160);
            $table->string('provider', 160)->nullable();
            $table->string('identifier', 100)->nullable();
            $table->string('storage_type', 24)->nullable();
            $table->string('currency', 3)->default('IDR');
            $table->timestamps();
            $table->index(['user_id', 'type']);
        });

        Schema::create('asset_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('cash_transaction_id')->nullable()->unique()->constrained('transactions')->nullOnDelete();
            $table->string('type', 24);
            $table->string('status', 24)->default('settled');
            $table->boolean('cash_debited')->default(false);
            $table->decimal('amount', 15, 2)->default(0);
            $table->decimal('quantity', 24, 8)->default(0);
            $table->decimal('unit_price', 24, 8)->default(0);
            $table->decimal('price_unit_grams', 16, 8)->default(1);
            $table->decimal('fees', 15, 2)->default(0);
            $table->decimal('cost_basis', 15, 2)->default(0);
            $table->foreignId('settlement_transaction_id')->nullable()->unique()->constrained('transactions')->nullOnDelete();
            $table->date('transaction_date');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->string('idempotency_key', 80)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['asset_id', 'transaction_date']);
            $table->index(['asset_id', 'status']);
            $table->unique(['user_id', 'idempotency_key']);
        });

        Schema::create('asset_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 24, 8);
            $table->string('unit', 16);
            $table->string('source', 120)->default('manual');
            $table->timestamp('captured_at');
            $table->timestamps();
            $table->index(['asset_id', 'captured_at']);
        });

        Schema::create('asset_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('transaction_id')->unique()->constrained('transactions')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->date('received_date');
            $table->string('idempotency_key', 80)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key']);
        });

        Schema::create('goal_asset_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('goal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 24, 8);
            $table->string('action', 24);
            $table->string('note', 500)->nullable();
            $table->string('idempotency_key', 80)->nullable();
            $table->timestamps();
            $table->index(['asset_id', 'goal_id']);
            $table->unique(['user_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goal_asset_allocations');
        Schema::dropIfExists('asset_refunds');
        Schema::dropIfExists('asset_prices');
        Schema::dropIfExists('asset_transactions');
        Schema::dropIfExists('assets');
        Schema::dropIfExists('goal_cash_allocations');
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('goal_obligation_id');
            $table->dropColumn(['financial_purpose', 'goal_allocation_amount', 'idempotency_key']);
        });
        Schema::dropIfExists('goal_obligations');
        Schema::dropIfExists('goal_items');
        Schema::table('goal_contributions', function (Blueprint $table) {
            $table->dropUnique(['goal_id', 'idempotency_key']);
            $table->dropColumn(['note', 'idempotency_key']);
        });
        Schema::table('goals', fn (Blueprint $table) => $table->dropColumn('opening_balance'));
    }
};
