<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('information_updates', function (Blueprint $table) {
            $table->enum('type', ['create', 'update', 'delete'])->nullable();
            $table->text('rejection_reason')->nullable();
            $table->integer('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('information_updates', function (Blueprint $table) {

            $table->dropColumn('type');
            $table->dropColumn('rejection_reason');
            $table->dropColumn('reviewed_at');
            $table->dropColumn('reviewed_by');
        });
    }
};
