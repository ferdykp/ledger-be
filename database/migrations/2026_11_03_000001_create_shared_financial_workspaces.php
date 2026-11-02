<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('shared_financial_workspaces')) Schema::create('shared_financial_workspaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('currency', 3)->default('IDR');
            $table->timestamps();
            $table->index('owner_user_id');
        });

        if (!Schema::hasTable('shared_financial_workspace_members')) Schema::create('shared_financial_workspace_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('shared_financial_workspaces')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20)->default('member');
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'user_id']);
        });

        if (!Schema::hasTable('shared_financial_workspace_invitations')) Schema::create('shared_financial_workspace_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('shared_financial_workspaces')->cascadeOnDelete();
            $table->foreignId('invited_by')->constrained('users')->cascadeOnDelete();
            $table->string('email', 255);
            $table->string('token_hash', 64)->unique();
            $table->string('status', 20)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->index(['email', 'status']);
            $table->index(['workspace_id', 'status']);
        });

        if (!Schema::hasTable('shared_financial_workspace_member_cash')) Schema::create('shared_financial_workspace_member_cash', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('shared_financial_workspaces')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('balance', 15, 2)->default(0);
            $table->timestamps();
            $table->unique(['workspace_id', 'user_id'], 'sfw_member_cash_user_unique');
        });
        // A previous MySQL run may have created this table before rejecting its generated index name.
        $memberCashIndexes = Schema::getIndexes('shared_financial_workspace_member_cash');
        if (!collect($memberCashIndexes)->contains(fn ($index) => ($index['name'] ?? null) === 'sfw_member_cash_user_unique')) {
            Schema::table('shared_financial_workspace_member_cash', function (Blueprint $table) {
                $table->unique(['workspace_id', 'user_id'], 'sfw_member_cash_user_unique');
            });
        }

        if (!Schema::hasTable('shared_financial_workspace_goals')) Schema::create('shared_financial_workspace_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('shared_financial_workspaces')->cascadeOnDelete();
            $table->string('name', 160);
            $table->decimal('target_amount', 15, 2);
            $table->date('target_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['workspace_id', 'is_active']);
        });

        if (!Schema::hasTable('shared_financial_workspace_goal_allocations')) Schema::create('shared_financial_workspace_goal_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('shared_financial_workspaces')->cascadeOnDelete();
            $table->foreignId('goal_id')->constrained('shared_financial_workspace_goals')->cascadeOnDelete();
            $table->foreignId('member_user_id')->constrained('users', null, 'sfwga_member_user_fk')->cascadeOnDelete();
            $table->string('action', 16);
            $table->decimal('amount', 15, 2);
            $table->date('allocation_date');
            $table->string('idempotency_key', 80)->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'goal_id', 'created_at'], 'sfwga_ws_goal_created_idx');
            $table->unique(['workspace_id', 'idempotency_key'], 'sfwga_ws_idempotency_unique');
        });

        if (!Schema::hasTable('shared_financial_workspace_cash_entries')) Schema::create('shared_financial_workspace_cash_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('shared_financial_workspaces')->cascadeOnDelete();
            $table->foreignId('member_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('source_account_id')->nullable()->constrained('accounts', null, 'sfwce_source_account_fk')->nullOnDelete();
            $table->foreignId('personal_transaction_id')->nullable()->unique('sfwce_personal_tx_unique')->constrained('transactions', null, 'sfwce_personal_tx_fk')->nullOnDelete();
            $table->string('type', 24);
            $table->string('direction', 8);
            $table->decimal('amount', 15, 2);
            $table->date('entry_date');
            $table->string('idempotency_key', 80)->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'entry_date', 'id'], 'sfwce_ws_date_id_idx');
            $table->unique(['workspace_id', 'idempotency_key'], 'sfwce_ws_idempotency_unique');
        });

        if (!Schema::hasTable('shared_assets')) Schema::create('shared_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('shared_financial_workspaces')->cascadeOnDelete();
            $table->string('type', 16)->default('gold');
            $table->string('name', 160);
            $table->string('provider', 160)->nullable();
            $table->decimal('latest_price_per_gram', 24, 8)->nullable();
            $table->date('price_captured_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'type']);
        });

        if (!Schema::hasTable('shared_asset_transactions')) Schema::create('shared_asset_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('shared_financial_workspaces')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('shared_assets')->cascadeOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->cascadeOnDelete();
            $table->string('action', 20);
            $table->decimal('amount', 15, 2)->default(0);
            $table->decimal('quantity', 24, 8)->default(0);
            $table->decimal('unit_price', 24, 8)->nullable();
            $table->decimal('fees', 15, 2)->default(0);
            $table->date('transaction_date');
            $table->string('idempotency_key', 80)->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();
            $table->index(['asset_id', 'transaction_date', 'id']);
            $table->unique(['workspace_id', 'idempotency_key'], 'sat_ws_idempotency_unique');
        });

        if (!Schema::hasTable('shared_asset_transaction_splits')) Schema::create('shared_asset_transaction_splits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shared_asset_transaction_id')->constrained('shared_asset_transactions', null, 'satx_transaction_fk')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('cash_amount', 15, 2)->default(0);
            $table->decimal('quantity', 24, 8)->default(0);
            $table->decimal('cost_basis', 15, 2)->default(0);
            $table->timestamps();
            $table->unique(['shared_asset_transaction_id', 'user_id'], 'shared_asset_tx_user_unique');
        });

        if (!Schema::hasTable('shared_asset_member_holdings')) Schema::create('shared_asset_member_holdings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('shared_assets')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 24, 8)->default(0);
            $table->decimal('cost_basis', 15, 2)->default(0);
            $table->timestamps();
            $table->unique(['asset_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_asset_member_holdings');
        Schema::dropIfExists('shared_asset_transaction_splits');
        Schema::dropIfExists('shared_asset_transactions');
        Schema::dropIfExists('shared_assets');
        Schema::dropIfExists('shared_financial_workspace_cash_entries');
        Schema::dropIfExists('shared_financial_workspace_goal_allocations');
        Schema::dropIfExists('shared_financial_workspace_goals');
        Schema::dropIfExists('shared_financial_workspace_member_cash');
        Schema::dropIfExists('shared_financial_workspace_invitations');
        Schema::dropIfExists('shared_financial_workspace_members');
        Schema::dropIfExists('shared_financial_workspaces');
    }
};
