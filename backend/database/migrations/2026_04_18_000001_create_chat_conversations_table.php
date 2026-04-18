<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('database_id')->nullable()->constrained('connected_databases')->nullOnDelete();
            $table->string('scope_key')->index();
            $table->string('resource')->nullable();
            $table->string('title', 160)->nullable();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_conversations');
    }
};
