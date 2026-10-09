<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->timestamp('recurrence_generated_at')->nullable();
            $table->index(['user_id', 'due_date', 'id']);
        });
        // Existing paid bills may already have a successor; never regenerate them automatically.
        DB::table('bills')->where('status', 'paid')->where('frequency', '!=', 'once')
            ->update(['recurrence_generated_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'due_date', 'id']);
            $table->dropColumn('recurrence_generated_at');
        });
    }
};
