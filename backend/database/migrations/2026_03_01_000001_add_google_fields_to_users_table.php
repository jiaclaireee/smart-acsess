<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'google_id')) {
                $table->string('google_id')->nullable()->unique()->after('email');
            }
            if (!Schema::hasColumn('users', 'auth_provider')) {
                $table->string('auth_provider')->nullable()->after('google_id');
            }
            if (!Schema::hasColumn('users', 'avatar_url')) {
                $table->text('avatar_url')->nullable()->after('auth_provider');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'avatar_url')) $table->dropColumn('avatar_url');
            if (Schema::hasColumn('users', 'auth_provider')) $table->dropColumn('auth_provider');
            if (Schema::hasColumn('users', 'google_id')) $table->dropColumn('google_id');
        });
    }
};
