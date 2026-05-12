<?php

use App\Models\JobCategory;
use App\Models\Position;
use App\Models\SelfService\Employee;
use App\Models\SubUnit;
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
    public function up(): void
    {
        Schema::create('job_details', static function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Employee::class)->constrained();
            $table->foreignIdFor(Position::class)->nullable()->constrained();
            $table->string('status')->nullable();
            $table->foreignIdFor(JobCategory::class)->nullable()->constrained();
            $table->foreignIdFor(SubUnit::class)->nullable()->constrained();
            $table->string('location')->nullable();
            $table->date('joined_date')->nullable();
            $table->date('contract_start_date')->nullable();
            $table->date('contract_end_date')->nullable();
            $table->softDeletes();
            $table->foreignIdFor(User::class)->nullable()->constrained();
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
        Schema::dropIfExists('job_details');
    }
};
