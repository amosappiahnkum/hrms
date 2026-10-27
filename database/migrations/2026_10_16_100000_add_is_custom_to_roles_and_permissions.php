<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Roles and permissions a super-admin made (they can rename or remove them); built-in ones are seeded. */
return new class extends Migration
{
    public function up(): void
    {
        foreach ([config('permission.table_names.roles'), config('permission.table_names.permissions')] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->boolean('is_custom')->default(false);
            });
        }
    }

    public function down(): void
    {
        foreach ([config('permission.table_names.roles'), config('permission.table_names.permissions')] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('is_custom'));
        }
    }
};
