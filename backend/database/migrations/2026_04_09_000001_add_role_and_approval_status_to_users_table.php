<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'role')) {
                $table->string('role')->default(User::ROLE_END_USER)->after('avatar_url');
            }

            if (!Schema::hasColumn('users', 'approval_status')) {
                $table->string('approval_status')->default(User::APPROVAL_PENDING)->after('role');
            }
        });

        DB::table('users')->update([
            'role' => User::ROLE_END_USER,
            'approval_status' => User::APPROVAL_APPROVED,
        ]);

        DB::table('users')
            ->where('email', 'admin@uplb.edu.ph')
            ->update([
                'role' => User::ROLE_ADMIN,
                'approval_status' => User::APPROVAL_APPROVED,
            ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'approval_status')) {
                $table->dropColumn('approval_status');
            }

            if (Schema::hasColumn('users', 'role')) {
                $table->dropColumn('role');
            }
        });
    }
};
