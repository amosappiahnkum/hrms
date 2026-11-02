<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Required certifications (SOP 5.1, 5.3.3): the kinds of certificate a role can require, which
 * positions require them, and which kind each uploaded certificate is. Starts empty; HR fills it in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certification_types', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            // How long a certificate of this kind is usually valid; the certificate's own expiry date wins.
            $table->unsignedSmallInteger('validity_months')->nullable();
            // The competency it evidences: renewals become training needs for it.
            $table->foreignId('competency_id')->nullable()->constrained(indexName: 'cert_types_competency_fk')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('employee_certifications', function (Blueprint $table) {
            // Existing certificates stay free text until HR sets their type.
            $table->foreignId('certification_type_id')->nullable()->after('certification_provider_id')
                ->constrained(indexName: 'emp_certs_type_fk')->nullOnDelete();
        });

        Schema::create('position_certifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('position_id')->constrained()->cascadeOnDelete();
            $table->foreignId('certification_type_id')->constrained(indexName: 'pos_certs_type_fk')->cascadeOnDelete();
            // Not mandatory: recommended, shown but not a gap.
            $table->boolean('mandatory')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['position_id', 'certification_type_id'], 'pos_certs_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('position_certifications');
        Schema::table('employee_certifications', function (Blueprint $table) {
            // Dropped by the name it was created with.
            $table->dropForeign('emp_certs_type_fk');
            $table->dropColumn('certification_type_id');
        });
        Schema::dropIfExists('certification_types');
    }
};
