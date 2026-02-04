<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        $ignore = [
            'websockets_statistics_entries',
            'model_has_permissions',
            'role_has_permissions',
            'migrations',
            'model_has_roles',
            'password_resets',
            'failed_jobs',
            'personal_access_tokens',
            'photos',
            'jobs',
        ];

        // Get all table names
        $database = DB::getDatabaseName();
        $query = DB::select('SELECT table_name AS name  FROM information_schema.tables WHERE table_schema = ?', [$database]);
        $tables = collect($query)
            ->map(fn($table) => Str::replace('app_', '', $table->name))
            ->reject(fn(string $t) => in_array($t, $ignore, true))
            ->values();

        \Illuminate\Support\Facades\Log::info('ds', $tables->toArray());
        // 1. Add uuid column if missing
        $tables->each(function (string $table) {
            $columns = collect(Schema::getColumns($table))->pluck('name');
            if (!$columns->contains('uuid')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->uuid('uuid')->nullable()->after('id');
                });
            }
        });

        // 2. Backfill UUIDs
        $tables->each(function (string $table) {
            $columns = collect(Schema::getColumns($table))->pluck('name');
            if (!$columns->contains('uuid')) {
                return;
            }

            $pk = $columns->contains('id') ? 'id' : $columns->first();

            DB::table($table)
                ->whereNull('uuid')
                ->orWhere('uuid', '')
                ->orderBy($pk)
                ->chunkById(1000, function ($rows) use ($table, $pk) {
                    foreach ($rows as $row) {
                        DB::table($table)
                            ->where($pk, $row->{$pk})
                            ->update(['uuid' => (string)Str::uuid()]);
                    }
                }, $pk);
        });

        // 3. Make uuid required + unique
        $tables->each(function (string $table) {
            $columns = collect(Schema::getColumns($table))->pluck('name');
            if (!$columns->contains('uuid')) {
                return;
            }

            // Ensure no nulls remain
            DB::table($table)
                ->whereNull('uuid')
                ->update(['uuid' => (string)Str::uuid()]);

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->uuid('uuid')->nullable(false)->change();

                $indexes = collect(Schema::getIndexes($table))->pluck('name');
                $indexName = "{$table}_uuid_unique";

                if (!$indexes->contains($indexName)) {
                    $blueprint->unique('uuid', $indexName);
                }
            });
        });
    }

    public function down(): void
    {
        $tables = collect(Schema::getTables())->map(fn($table) => $table['name']);

        $tables->each(function (string $table) {
            $columns = collect(Schema::getColumns($table))->pluck('name');
            if ($columns->contains('uuid')) {
                Schema::table($table, function (Blueprint $blueprint) use ($table) {
                    $blueprint->dropUnique("{$table}_uuid_unique");
                    $blueprint->dropColumn('uuid');
                });
            }
        });
    }
};
