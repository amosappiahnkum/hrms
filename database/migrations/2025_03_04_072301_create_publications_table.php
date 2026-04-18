<?php

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('publications', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->jsonb('authors');
            $table->date('publication_date')->nullable();
            $table->string('publisher')->nullable();
            $table->string('edition')->nullable();
            $table->string('volume_and_issue_number')->nullable();
            $table->string('isbn_issn')->nullable();
            $table->string('doi')->comment('Digital Object Identifier')->nullable();
            $table->foreignIdFor(Employee::class)->nullable()->constrained()->onDelete('set null');
            $table->foreignIdFor(User::class)->nullable()->constrained()->onDelete('set null');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('publications');
    }
};
