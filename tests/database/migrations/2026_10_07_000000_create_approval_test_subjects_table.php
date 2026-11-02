<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Test-only: a stand-in request for approval workflow tests (loaded only in the testing environment). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_test_subjects', function (Blueprint $table) {
            $table->id();
            $table->decimal('approved_hours', 5, 1)->nullable();
            $table->string('outcome')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_test_subjects');
    }
};
