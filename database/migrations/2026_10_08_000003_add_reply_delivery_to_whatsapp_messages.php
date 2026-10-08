<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->text('reply_text')->nullable();
            $table->timestamp('replied_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', fn (Blueprint $table) => $table->dropColumn(['reply_text', 'replied_at']));
    }
};
