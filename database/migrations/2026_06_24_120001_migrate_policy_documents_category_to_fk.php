<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('policy_documents', function (Blueprint $table) {
            $table->foreignId('document_category_id')
                  ->nullable()
                  ->after('description')
                  ->constrained('document_categories');
        });

        // Map old enum values to the seeded category names
        $map = [
            'policy'   => 'Policy',
            'manual'   => 'Manual',
            'handbook' => 'Handbook',
            'notice'   => 'Notice',
            'form'     => 'Form',
            'other'    => 'Other',
        ];

        $categories = DB::table('document_categories')
            ->whereIn('name', array_values($map))
            ->pluck('id', 'name');

        foreach ($map as $enumValue => $name) {
            if ($id = $categories[$name] ?? null) {
                DB::table('policy_documents')
                    ->where('category', $enumValue)
                    ->update(['document_category_id' => $id]);
            }
        }

        // Default any unmapped rows to "Other"
        $otherId = $categories['Other'] ?? null;
        if ($otherId) {
            DB::table('policy_documents')
                ->whereNull('document_category_id')
                ->update(['document_category_id' => $otherId]);
        }

        Schema::table('policy_documents', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }

    public function down(): void
    {
        Schema::table('policy_documents', function (Blueprint $table) {
            $table->enum('category', ['policy', 'manual', 'handbook', 'notice', 'form', 'other'])
                  ->default('policy')
                  ->after('description');
        });

        Schema::table('policy_documents', function (Blueprint $table) {
            $table->dropForeign(['document_category_id']);
            $table->dropColumn('document_category_id');
        });
    }
};
