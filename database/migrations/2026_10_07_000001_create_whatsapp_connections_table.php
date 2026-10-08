<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('whatsapp_connections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('phone_number')->nullable()->unique();
            $t->string('provider')->default('evolution');
            $t->string('status')->default('pending');
            $t->timestamp('verified_at')->nullable();
            $t->timestamp('last_message_at')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_connections');
    }
};
