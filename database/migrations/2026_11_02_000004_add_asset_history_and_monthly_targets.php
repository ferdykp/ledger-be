<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_transactions', function (Blueprint $table) {
            $table->string('origin', 20)->default('live')->after('status');
            $table->boolean('cost_basis_known')->default(true)->after('cost_basis');
            $table->timestamp('superseded_at')->nullable()->after('cost_basis_known');
        });

        Schema::create('asset_monthly_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('asset_type', 16);
            $table->decimal('amount', 15, 2);
            $table->timestamps();
            $table->unique(['user_id', 'asset_type']);
        });

        Schema::create('asset_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->boolean('reconciled_opening')->default(false);
            $table->unsignedInteger('transaction_count');
            $table->string('idempotency_key', 80)->nullable();
            $table->string('payload_hash', 64)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key']);
        });
        Schema::table('asset_transactions', function (Blueprint $table) {
            $table->foreignId('import_batch_id')->nullable()->after('asset_id')->constrained('asset_import_batches')->nullOnDelete();
        });

        // Previous manual RDPU entries stored nominal amounts in the quantity field.
        // Keep their portfolio values intact and explicitly identify them as value-only.
        DB::table('assets')->where('type', 'rdpu')->where('valuation_mode', 'manual')->update(['valuation_mode' => 'value_only']);
        DB::table('asset_transactions')->whereIn('notes', [
            'Saldo awal aset yang sudah dimiliki sebelum menggunakan Ledger.',
        ])->update(['origin' => 'opening']);
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_monthly_targets');
        Schema::table('asset_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('import_batch_id');
            $table->dropColumn(['origin', 'cost_basis_known', 'superseded_at']);
        });
        Schema::dropIfExists('asset_import_batches');
        DB::table('assets')->where('type', 'rdpu')->where('valuation_mode', 'value_only')->update(['valuation_mode' => 'manual']);
    }
};
