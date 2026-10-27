<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A pending "create" request has no record yet, so information_id must allow null.
     */
    public function up(): void
    {
        Schema::table('information_updates', function (Blueprint $table) {
            $table->unsignedBigInteger('information_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Not reversible once pending "create" requests (null information_id) exist.
    }
};
