<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_details', function (Blueprint $table) {
            $table->string('digital_address')->nullable()->after('zip_code');
            $table->string('ghana_card_number')->nullable()->after('digital_address');
        });
    }

    public function down(): void
    {
        Schema::table('contact_details', function (Blueprint $table) {
            $table->dropColumn(['digital_address', 'ghana_card_number']);
        });
    }
};
