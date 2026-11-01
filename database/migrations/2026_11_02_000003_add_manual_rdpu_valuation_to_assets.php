<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->string('valuation_mode', 16)->default('units')->after('type');
            $table->decimal('manual_current_value', 24, 2)->nullable()->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn(['valuation_mode', 'manual_current_value']);
        });
    }
};
