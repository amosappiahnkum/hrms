<?php

use App\Models\SelfService\Employee;
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
        Schema::create('grant_and_funds', function (Blueprint $table) {
            $table->id();
            $table->string('source'); // Government, Private, Nonprofit, International Funds
            $table->string('purpose')->nullable(); // Research, Project, Capital, Operating, Fellowships
            $table->string('amount')->nullable();
            $table->string('benefactor')->nullable();
            $table->date('date')->nullable();
            $table->text('description')->nullable();
            $table->foreignIdFor(Employee::class)->nullable()->constrained()->onDelete('set null');
            $table->foreignIdFor(User::class)->nullable()->constrained()->onDelete('set null');
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
        Schema::dropIfExists('grant_and_funds');
    }
};
