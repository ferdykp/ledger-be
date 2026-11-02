<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->boolean('auto_pay_enabled')->default(false)->after('reminder_enabled');
            $table->foreignId('auto_pay_account_id')->nullable()->after('auto_pay_enabled')->constrained('accounts')->nullOnDelete();
            $table->index(['auto_pay_enabled', 'status', 'due_date'], 'bills_auto_due_idx');
        });

        Schema::create('bill_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->date('scheduled_for');
            $table->decimal('amount', 18, 2);
            $table->string('status', 32);
            $table->text('message')->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamps();
            $table->unique(['bill_id', 'scheduled_for']);
            $table->index(['user_id', 'status', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_payments');
        Schema::table('bills', function (Blueprint $table) {
            $table->dropIndex('bills_auto_due_idx');
            $table->dropConstrainedForeignId('auto_pay_account_id');
            $table->dropColumn('auto_pay_enabled');
        });
    }
};
