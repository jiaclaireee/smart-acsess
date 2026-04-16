<?php

use App\Models\ConnectedDatabase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connected_databases', function (Blueprint $table) {
            if (!Schema::hasColumn('connected_databases', 'type')) {
                $table->string('type')->default(ConnectedDatabase::TYPE_POSTGRESQL)->after('name');
            }

            if (!Schema::hasColumn('connected_databases', 'host')) {
                $table->string('host')->nullable()->after('type');
            }

            if (!Schema::hasColumn('connected_databases', 'port')) {
                $table->unsignedInteger('port')->nullable()->after('host');
            }

            if (!Schema::hasColumn('connected_databases', 'database_name')) {
                $table->string('database_name')->nullable()->after('port');
            }

            if (!Schema::hasColumn('connected_databases', 'username')) {
                $table->string('username')->nullable()->after('database_name');
            }

            if (!Schema::hasColumn('connected_databases', 'password_encrypted')) {
                $table->text('password_encrypted')->nullable()->after('username');
            }

            if (!Schema::hasColumn('connected_databases', 'extra_config_encrypted')) {
                $table->text('extra_config_encrypted')->nullable()->after('password_encrypted');
            }
        });

        $records = DB::table('connected_databases')
            ->select('id', 'connection_string_encrypted')
            ->get();

        foreach ($records as $record) {
            if (empty($record->connection_string_encrypted)) {
                continue;
            }

            try {
                $dsn = Crypt::decryptString($record->connection_string_encrypted);
                $parts = parse_url($dsn);

                if (!$parts || ($parts['scheme'] ?? '') !== 'postgresql') {
                    continue;
                }

                DB::table('connected_databases')
                    ->where('id', $record->id)
                    ->update([
                        'type' => ConnectedDatabase::TYPE_POSTGRESQL,
                        'host' => $parts['host'] ?? '127.0.0.1',
                        'port' => (int) ($parts['port'] ?? 5432),
                        'database_name' => ltrim($parts['path'] ?? '', '/'),
                        'username' => $parts['user'] ?? null,
                        'password_encrypted' => isset($parts['pass']) && $parts['pass'] !== ''
                            ? Crypt::encryptString($parts['pass'])
                            : null,
                    ]);
            } catch (\Throwable) {
                continue;
            }
        }
    }

    public function down(): void
    {
        Schema::table('connected_databases', function (Blueprint $table) {
            if (Schema::hasColumn('connected_databases', 'extra_config_encrypted')) {
                $table->dropColumn('extra_config_encrypted');
            }

            if (Schema::hasColumn('connected_databases', 'password_encrypted')) {
                $table->dropColumn('password_encrypted');
            }

            if (Schema::hasColumn('connected_databases', 'username')) {
                $table->dropColumn('username');
            }

            if (Schema::hasColumn('connected_databases', 'database_name')) {
                $table->dropColumn('database_name');
            }

            if (Schema::hasColumn('connected_databases', 'port')) {
                $table->dropColumn('port');
            }

            if (Schema::hasColumn('connected_databases', 'host')) {
                $table->dropColumn('host');
            }

            if (Schema::hasColumn('connected_databases', 'type')) {
                $table->dropColumn('type');
            }
        });
    }
};
