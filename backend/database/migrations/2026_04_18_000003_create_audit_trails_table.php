<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_trails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name', 200);
            $table->string('user_email', 255)->nullable();
            $table->string('module', 150);
            $table->string('action', 150);
            $table->string('description', 255)->nullable();
            $table->string('subject_type', 200)->nullable();
            $table->string('subject_id', 100)->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['module', 'action']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_trails');
    }
};
